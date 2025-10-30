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
