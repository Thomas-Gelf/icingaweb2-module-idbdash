DROP TABLE IF EXISTS idbdash_sla_periods;
CREATE TABLE idbdash_sla_periods (
  timeperiod_id BINARY(20) NOT NULL,
  start_time BIGINT(20) UNSIGNED NOT NULL,
  end_time BIGINT(20) UNSIGNED NOT NULL,
  PRIMARY KEY tp_start (timeperiod_id, start_time),
  UNIQUE KEY tp_end (timeperiod_id, end_time),
  CONSTRAINT idbdash_sla_periods_timeperiod
    FOREIGN KEY idbdash_sla_periods_timeperiod_id (timeperiod_id)
      REFERENCES timeperiod (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE InnoDB;

DROP FUNCTION IF EXISTS get_sla_ok_percent_with_slatime;
DELIMITER //
CREATE FUNCTION get_sla_ok_percent_with_slatime(
  in_host_id binary(20),
  in_service_id binary(20),
  in_start_time bigint unsigned,
  in_end_time bigint unsigned
)
RETURNS decimal(7, 4)
READS SQL DATA
BEGIN
  DECLARE result decimal(7, 4);
  DECLARE row_event_time bigint unsigned;
  DECLARE row_event_type enum('state_change', 'downtime_start', 'downtime_end', 'end');
  DECLARE row_event_prio int;
  DECLARE row_hard_state tinyint unsigned;
  DECLARE row_previous_hard_state tinyint unsigned;
  DECLARE last_event_time bigint unsigned;
  DECLARE last_hard_state tinyint unsigned;
  DECLARE active_downtimes int unsigned;
  DECLARE problem_time bigint unsigned;
  DECLARE total_time bigint unsigned;
  DECLARE done int;
  DECLARE cur CURSOR FOR
    (
      -- all downtime_start events before the end of the SLA interval
      -- for downtimes that overlap the SLA interval in any way
      SELECT
        GREATEST(downtime_start, in_start_time) AS event_time,
        'downtime_start' AS event_type,
        1 AS event_prio,
        NULL AS hard_state,
        NULL AS previous_hard_state
      FROM sla_history_downtime d
      WHERE d.host_id = in_host_id
        AND ((in_service_id IS NULL AND d.service_id IS NULL) OR d.service_id = in_service_id)
        AND d.downtime_start < in_end_time
        AND d.downtime_end >= in_start_time
    ) UNION ALL (
      -- all downtime_end events before the end of the SLA interval
      -- for downtimes that overlap the SLA interval in any way
      SELECT
        downtime_end AS event_time,
        'downtime_end' AS event_type,
        2 AS event_prio,
        NULL AS hard_state,
        NULL AS previous_hard_state
      FROM sla_history_downtime d
      WHERE d.host_id = in_host_id
        AND ((in_service_id IS NULL AND d.service_id IS NULL) OR d.service_id = in_service_id)
        AND d.downtime_start < in_end_time
        AND d.downtime_end >= in_start_time
        AND d.downtime_end < in_end_time
    ) UNION ALL (
      -- all state events strictly in interval
      SELECT
        event_time,
        'state_change' AS event_type,
        0 AS event_prio,
        hard_state,
        previous_hard_state
      FROM sla_history_state s
      WHERE s.host_id = in_host_id
        AND ((in_service_id IS NULL AND s.service_id IS NULL) OR s.service_id = in_service_id)
        AND s.event_time > in_start_time
        AND s.event_time < in_end_time
    ) UNION ALL (
      -- end event to keep loop simple, values are not used
      SELECT
        in_end_time AS event_time,
        'end' AS event_type,
        3 AS event_prio,
        NULL AS hard_state,
        NULL AS previous_hard_state
    )
    ORDER BY event_time, event_prio;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  IF in_end_time <= in_start_time THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'end time must be greater than start time';
  END IF;

  -- Use the latest event at or before the beginning of the SLA interval as the initial state.
  SELECT hard_state INTO last_hard_state
  FROM sla_history_state s
  WHERE s.host_id = in_host_id
    AND ((in_service_id IS NULL AND s.service_id IS NULL) OR s.service_id = in_service_id)
    AND s.event_time <= in_start_time
  ORDER BY s.event_time DESC
  LIMIT 1;

  -- If this does not exist, use the previous state from the first event after the beginning of the SLA interval.
  IF last_hard_state IS NULL THEN
    SELECT previous_hard_state INTO last_hard_state
    FROM sla_history_state s
    WHERE s.host_id = in_host_id
      AND ((in_service_id IS NULL AND s.service_id IS NULL) OR s.service_id = in_service_id)
      AND s.event_time > in_start_time
    ORDER BY s.event_time ASC
    LIMIT 1;
  END IF;

  -- If this also does not exist, use the current host/service state.
  IF last_hard_state IS NULL THEN
    IF in_service_id IS NULL THEN
      SELECT hard_state INTO last_hard_state
      FROM host_state s
      WHERE s.host_id = in_host_id;
    ELSE
      SELECT hard_state INTO last_hard_state
      FROM service_state s
      WHERE s.host_id = in_host_id
        AND s.service_id = in_service_id;
    END IF;
  END IF;

  IF last_hard_state IS NULL THEN
    SET last_hard_state = 0;
  END IF;

  SET problem_time = 0;
  SET total_time = in_end_time - in_start_time;
  SET last_event_time = in_start_time;
  SET active_downtimes = 0;

  SET done = 0;
  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO row_event_time, row_event_type, row_event_prio, row_hard_state, row_previous_hard_state;
    IF done THEN
      LEAVE read_loop;
    END IF;

    IF row_previous_hard_state = 99 THEN
      SET total_time = total_time - (row_event_time - last_event_time);
    ELSEIF ((in_service_id IS NULL AND last_hard_state > 0) OR (in_service_id IS NOT NULL AND last_hard_state > 1))
      AND last_hard_state != 99
      AND active_downtimes = 0
    THEN
      SET problem_time = problem_time + row_event_time - last_event_time;
    END IF;

    SET last_event_time = row_event_time;
    IF row_event_type = 'state_change' THEN
      SET last_hard_state = row_hard_state;
    ELSEIF row_event_type = 'downtime_start' THEN
      SET active_downtimes = active_downtimes + 1;
    ELSEIF row_event_type = 'downtime_end' THEN
      SET active_downtimes = active_downtimes - 1;
    END IF;
  END LOOP;
  CLOSE cur;

  SET result = 100 * (total_time - problem_time) / total_time;
  RETURN result;
END//
DELIMITER ;
