<?php

namespace Icinga\Module\Idbdash;

use Icinga\Data\Filter\Filter as LegacyFilter;
use Icinga\Data\Filter\FilterAnd as LegacyFilterAnd;
use Icinga\Data\Filter\FilterEqual as LegacyFilterEqual;
use Icinga\Data\Filter\FilterEqualOrGreaterThan as LegacyFilterEqualOrGreaterThan;
use Icinga\Data\Filter\FilterEqualOrLessThan as LegacyFilterEqualOrLessThan;
use Icinga\Data\Filter\FilterExpression as LegacyFilterExpression;
use Icinga\Data\Filter\FilterGreaterThan as LegacyFilterGreaterThan;
use Icinga\Data\Filter\FilterLessThan as LegacyFilterLessThan;
use Icinga\Data\Filter\FilterMatch as LegacyFilterMatch;
use Icinga\Data\Filter\FilterNot as LegacyFilterNot;
use Icinga\Data\Filter\FilterNotEqual as LegacyFilterNotEqual;
use Icinga\Data\Filter\FilterOr as LegacyFilterOr;
use InvalidArgumentException;
use ipl\Stdlib\Filter;
use ipl\Stdlib\Filter\All;
use ipl\Stdlib\Filter\Any;
use ipl\Stdlib\Filter\Condition;
use ipl\Stdlib\Filter\Equal;
use ipl\Stdlib\Filter\GreaterThan;
use ipl\Stdlib\Filter\GreaterThanOrEqual;
use ipl\Stdlib\Filter\LessThan;
use ipl\Stdlib\Filter\LessThanOrEqual;
use ipl\Stdlib\Filter\Like;
use ipl\Stdlib\Filter\None;
use ipl\Stdlib\Filter\Unequal;
use RuntimeException;

class FilterConverter
{
    // Mappings from icingadb/library/Icingadb/Compat/UrlMigrator.php
    protected const NO_YES = ['n', 'y'];
    protected const USE_EXPR = 'use-expr';
    protected const DROP = 'drop';

    protected static ?array $activeSlaTimes = null;

    protected static function listSlaTimes(): array
    {
        if (self::$activeSlaTimes === null) {
            $db = new IcingaDbLookup();
            self::$activeSlaTimes = TimePeriodHelper::filterActiveTimePeriods($db->loadTimePeriods());
        }

        return self::$activeSlaTimes;
    }

    public static function convert(LegacyFilter $filter)
    {
        if ($filter instanceof LegacyFilterAnd) {
            return new All(...array_map(self::convert(...), $filter->filters()));
        }
        if ($filter instanceof LegacyFilterOr) {
            return new Any(...array_map(self::convert(...), $filter->filters()));
        }
        if ($filter instanceof LegacyFilterNot) {
            return new None(...array_map(self::convert(...), $filter->filters()));
        }
        assert($filter instanceof LegacyFilterExpression);

        $expression = $filter->getExpression();
        $column = $filter->getColumn();
        if ($column === 'servicehost_in_slatime' || $column === 'host_in_slatime') {
            if ((string) $filter->getExpression() === '1') {
                $filters = array_map(fn ($period) => new Equal(
                    'host.vars.sla_id',
                    preg_replace('/^sla/', '', $period->name)
                ), self::listSlaTimes());
            } else {
                $filters = array_map(fn ($period) => new Unequal(
                    'host.vars.sla_id',
                    preg_replace('/^sla/', '', $period->name)
                ), self::listSlaTimes());
            }

            return new All(...$filters);
        }

        $expression = self::convertColumnValue($column, $expression);
        $column = self::convertColumnName($column);

        if ($filter instanceof LegacyFilterEqual) {
            return new Equal($column, $expression);
        }
        if ($filter instanceof LegacyFilterMatch) {
            return new Like($column, $expression);
        }
        if ($filter instanceof LegacyFilterGreaterThan) {
            return new GreaterThan($column, $expression);
        }
        if ($filter instanceof LegacyFilterEqualOrGreaterThan) {
            return new GreaterThanOrEqual($column, $expression);
        }
        if ($filter instanceof LegacyFilterLessThan) {
            return new LessThan($column, $expression);
        }
        if ($filter instanceof LegacyFilterEqualOrLessThan) {
            return new LessThanOrEqual($column, $expression);
        }
        if ($filter instanceof LegacyFilterNotEqual) {
            return new Unequal($column, $expression);
        }

        throw new RuntimeException('Unsupported filter class: ' . get_class($filter));
    }

    public static function convertColumnName(string $name): string
    {
        if ($name === 'service') {
            return 'service.name';
        }
        if (preg_match('/^_(host|service)_(.+)$/', $name, $match)) {
            return $match[1] . '.vars.' . $match[2];
        }

        $mapping = self::hostsColumns()[$name] ?? self::servicesColumns()[$name] ?? null;

        if ($mapping === null) {
            throw new RuntimeException("Unsupported filter column: $name");
        }
        if ($mapping === self::DROP) {
            throw new RuntimeException("Unsupported filter column, DROP: $name");
        }

        return array_key_first($mapping);
    }

    public static function convertColumnValue(string $columnName, $value): string
    {
        if ($columnName === 'service') {
            return $value;
        }
        if (preg_match('/^_(host|service)_(.+)$/', $columnName)) {
            return $value;
        }

        $mapping = self::hostsColumns()[$columnName] ?? self::servicesColumns()[$columnName] ?? null;

        if ($mapping === null) {
            throw new InvalidArgumentException("Unsupported filter column: $columnName");
        }
        if ($mapping === self::DROP) {
            throw new InvalidArgumentException("Unsupported filter column, DROP: $columnName");
        }
        if ($mapping === self::USE_EXPR) {
            return $value;
        }

        if (is_array($mapping)) {
            return $mapping[$value] ?? throw new InvalidArgumentException(sprintf(
                'Cannot map %s for %s, I have: %s',
                $value,
                $columnName,
                implode(', ', $mapping)
            ));
        }

        return $value;
    }

    protected static function hostsColumns(): array
    {
        return [

            // Query columns
            'host_acknowledged' => [
                'host.state.is_acknowledged' => self::NO_YES
            ],
            'host_acknowledgement_type' => [
                'host.state.is_acknowledged' => array_merge(self::NO_YES, ['sticky'])
            ],
            'host_action_url' => [
                'host.action_url.action_url' => self::USE_EXPR
            ],
            'host_active_checks_enabled' => [
                'host.active_checks_enabled' => self::NO_YES
            ],
            'host_active_checks_enabled_changed' => self::DROP,
            'host_address' => [
                'host.address' => self::USE_EXPR
            ],
            'host_address6' => [
                'host.address6' => self::USE_EXPR
            ],
            'host_alias' => self::DROP,
            'host_check_command' => [
                'host.checkcommand_name' => self::USE_EXPR
            ],
            'host_check_execution_time' => [
                'host.state.execution_time' => self::USE_EXPR
            ],
            'host_check_latency' => [
                'host.state.latency' => self::USE_EXPR
            ],
            'host_check_source' => [
                'host.state.check_source' => self::USE_EXPR
            ],
            'host_check_timeperiod' => [
                'host.check_timeperiod_name' => self::USE_EXPR
            ],
            'host_current_check_attempt' => [
                'host.state.check_attempt' => self::USE_EXPR
            ],
            'host_current_notification_number' => self::DROP,
            'host_display_name' => [
                'host.display_name' => self::USE_EXPR
            ],
            'host_event_handler_enabled' => [
                'host.event_handler_enabled' => self::NO_YES
            ],
            'host_event_handler_enabled_changed' => self::DROP,
            'host_flap_detection_enabled' => [
                'host.flapping_enabled' => self::NO_YES
            ],
            'host_flap_detection_enabled_changed' => self::DROP,
            'host_handled' => [
                'host.state.is_handled' => self::NO_YES
            ],
            'host_hard_state' => [
                'host.state.hard_state' => self::USE_EXPR
            ],
            'host_in_downtime' => [
                'host.state.in_downtime' => self::NO_YES
            ],
            'host_ipv4' => [
                'host.address_bin' => self::USE_EXPR
            ],
            'host_is_flapping' => [
                'host.state.is_flapping' => self::NO_YES
            ],
            'host_is_reachable' => [
                'host.state.is_reachable' => self::NO_YES
            ],
            'host_last_check' => [
                'host.state.last_update' => self::USE_EXPR
            ],
            'host_last_notification' => self::DROP,
            'host_last_state_change' => [
                'host.state.last_state_change' => self::USE_EXPR
            ],
            'host_last_state_change_ts' => [
                'host.state.last_state_change' => self::USE_EXPR
            ],
            'host_long_output' => [
                'host.state.long_output' => self::USE_EXPR
            ],
            'host_max_check_attempts' => [
                'host.max_check_attempts' => self::USE_EXPR
            ],
            'host_modified_host_attributes' => self::DROP,
            'host_name' => [
                'host.name' => self::USE_EXPR
            ],
            'host_next_check' => [
                'host.state.next_check' => self::USE_EXPR
            ],
            'host_notes_url' => [
                'host.notes_url.notes_url' => self::USE_EXPR
            ],
            'host_notifications_enabled' => [
                'host.notifications_enabled' => self::NO_YES
            ],
            'host_notifications_enabled_changed' => self::DROP,
            'host_obsessing' => self::DROP,
            'host_obsessing_changed' => self::DROP,
            'host_output' => [
                'host.state.output' => self::USE_EXPR
            ],
            'host_passive_checks_enabled' => [
                'host.passive_checks_enabled' => self::NO_YES
            ],
            'host_passive_checks_enabled_changed' => self::DROP,
            'host_percent_state_change' => self::DROP,
            'host_perfdata' => [
                'host.state.performance_data' => self::USE_EXPR
            ],
            'host_problem' => [
                'host.state.is_problem' => self::NO_YES
            ],
            'host_severity' => [
                'host.state.severity' => self::USE_EXPR
            ],
            'host_state' => [
                'host.state.soft_state' => self::USE_EXPR
            ],
            'host_state_type' => [
                'host.state.state_type' => ['soft', 'hard']
            ],
            'host_unhandled' => [
                'host.state.is_handled' => array_reverse(self::NO_YES)
            ],

            // Filter columns
            'host_contact' => [
                'host.user.name' => self::USE_EXPR
            ],
            'host_contactgroup' => [
                'host.usergroup.name' => self::USE_EXPR
            ],

            // Query columns the dataview doesn't include, added here because it's possible to filter for them anyway
            'host_check_interval' => self::DROP,
            'host_icon_image' => self::DROP,
            'host_icon_image_alt' => self::DROP,
            'host_notes' => self::DROP,
            'object_type' => self::DROP,
            'object_id' => self::DROP,
            'host_attempt' => self::DROP,
            'host_check_type' => self::DROP,
            'host_event_handler' => self::DROP,
            'host_failure_prediction_enabled' => self::DROP,
            'host_is_passive_checked' => self::DROP,
            'host_last_hard_state' => self::DROP,
            'host_last_hard_state_change' => self::DROP,
            'host_last_time_down' => self::DROP,
            'host_last_time_unreachable' => self::DROP,
            'host_last_time_up' => self::DROP,
            'host_next_notification' => self::DROP,
            'host_next_update' => function ($filter) {
                /** @var Condition $filter */
                if ($filter->getValue() !== 'now') {
                    return false;
                }

                // Doesn't get dropped because there's a default dashlet using it.
                // Though since this dashlet uses it to check for overdue hosts we'll
                // replace it as next_update is volatile (only in redis up2date)
                return Filter::equal('host.state.is_overdue', $filter instanceof LessThan ? 'y' : 'n');
            },
            'host_no_more_notifications' => self::DROP,
            'host_normal_check_interval' => self::DROP,
            'host_problem_has_been_acknowledged' => self::DROP,
            'host_process_performance_data' => self::DROP,
            'host_retry_check_interval' => self::DROP,
            'host_scheduled_downtime_depth' => self::DROP,
            'host_status_update_time' => self::DROP,
            'problems' => self::DROP
        ];
    }

    protected static function servicesColumns(): array
    {
        return [
            // Query columns
            'host_acknowledged' => [
                'host.state.is_acknowledged' => self::NO_YES
            ],
            'host_action_url' => [
                'host.action_url.action_url' => self::USE_EXPR
            ],
            'host_active_checks_enabled' => [
                'host.active_checks_enabled' => self::NO_YES
            ],
            'host_address' => [
                'host.address' => self::USE_EXPR
            ],
            'host_address6' => [
                'host.address6' => self::USE_EXPR
            ],
            'host_alias' => self::DROP,
            'host_check_source' => [
                'host.state.check_source' => self::USE_EXPR
            ],
            'host_display_name' => [
                'host.display_name' => self::USE_EXPR
            ],
            'host_handled' => [
                'host.state.is_handled' => self::NO_YES
            ],
            'host_hard_state' => [
                'host.state.hard_state' => self::USE_EXPR
            ],
            'host_in_downtime' => [
                'host.state.in_downtime' => self::NO_YES
            ],
            'host_ipv4' => [
                'host.address_bin' => self::USE_EXPR
            ],
            'host_is_flapping' => [
                'host.state.is_flapping' => self::NO_YES
            ],
            'host_last_check' => [
                'host.state.last_update' => self::USE_EXPR
            ],
            'host_last_hard_state' => [
                'host.state.previous_hard_state' => self::USE_EXPR
            ],
            'host_last_hard_state_change' => self::DROP,
            'host_last_state_change' => [
                'host.state.last_state_change' => self::USE_EXPR
            ],
            'host_last_time_down' => self::DROP,
            'host_last_time_unreachable' => self::DROP,
            'host_last_time_up' => self::DROP,
            'host_long_output' => [
                'host.state.long_output' => self::USE_EXPR
            ],
            'host_modified_host_attributes' => self::DROP,
            'host_notes_url' => [
                'host.notes_url.notes_url' => self::USE_EXPR
            ],
            'host_notifications_enabled' => [
                'host.notifications_enabled' => self::NO_YES
            ],
            'host_output' => [
                'host.state.output' => self::USE_EXPR
            ],
            'host_passive_checks_enabled' => [
                'host.passive_checks_enabled' => self::NO_YES
            ],
            'host_perfdata' => [
                'host.state.performance_data' => self::USE_EXPR
            ],
            'host_problem' => [
                'host.state.is_problem' => self::NO_YES
            ],
            'host_severity' => [
                'host.state.severity' => self::USE_EXPR
            ],
            'host_state' => [
                'host.state.soft_state' => self::USE_EXPR
            ],
            'host_state_type' => [
                'host.state.state_type' => ['soft', 'hard']
            ],
            'service_acknowledged' => [
                'service.state.is_acknowledged' => self::NO_YES
            ],
            'service_acknowledgement_type' => [
                'service.state.is_acknowledged' => array_merge(self::NO_YES, ['sticky'])
            ],
            'service_action_url' => [
                'service.action_url.action_url' => self::USE_EXPR
            ],
            'service_active_checks_enabled' => [
                'service.active_checks_enabled' => self::NO_YES
            ],
            'service_active_checks_enabled_changed' => self::DROP,
            'service_attempt' => [
                'service.state.check_attempt' => self::USE_EXPR
            ],
            'service_check_command' => [
                'service.checkcommand_name' => self::USE_EXPR
            ],
            'service_check_source' => [
                'service.state.check_source' => self::USE_EXPR
            ],
            'service_check_timeperiod' => [
                'service.check_timeperiod_name' => self::USE_EXPR
            ],
            'service_current_check_attempt' => [
                'service.state.check_attempt' => self::USE_EXPR
            ],
            'service_current_notification_number' => self::DROP,
            'service_display_name' => [
                'service.display_name' => self::USE_EXPR
            ],
            'service_event_handler_enabled' => [
                'service.event_handler_enabled' => self::NO_YES
            ],
            'service_event_handler_enabled_changed' => self::DROP,
            'service_flap_detection_enabled' => [
                'service.flapping_enabled' => self::NO_YES
            ],
            'service_flap_detection_enabled_changed' => self::DROP,
            'service_handled' => [
                'service.state.is_handled' => self::NO_YES
            ],
            'service_hard_state' => [
                'service.state.hard_state' => self::USE_EXPR
            ],
            'service_host_name' => [
                'host.name' => self::USE_EXPR
            ],
            'service_in_downtime' => [
                'service.state.in_downtime' => self::NO_YES
            ],
            'service_is_flapping' => [
                'service.state.is_flapping' => self::NO_YES
            ],
            'service_is_reachable' => [
                'service.state.is_reachable' => self::NO_YES
            ],
            'service_last_check' => [
                'service.state.last_update' => self::USE_EXPR
            ],
            'service_last_hard_state' => [
                'service.state.previous_hard_state' => self::USE_EXPR
            ],
            'service_last_hard_state_change' => self::DROP,
            'service_last_notification' => self::DROP,
            'service_last_state_change' => [
                'service.state.last_state_change' => self::USE_EXPR
            ],
            'service_last_state_change_ts' => [
                'service.state.last_state_change' => self::USE_EXPR
            ],
            'service_last_time_critical' => self::DROP,
            'service_last_time_ok' => self::DROP,
            'service_last_time_unknown' => self::DROP,
            'service_last_time_warning' => self::DROP,
            'service_long_output' => [
                'service.state.long_output' => self::USE_EXPR
            ],
            'service_max_check_attempts' => [
                'service.max_check_attempts' => self::USE_EXPR
            ],
            'service_modified_service_attributes' => self::DROP,
            'service_next_check' => [
                'service.state.next_check' => self::USE_EXPR
            ],
            'service_notes' => [
                'service.notes' => self::USE_EXPR
            ],
            'service_notes_url' => [
                'service.notes_url.notes_url' => self::USE_EXPR
            ],
            'service_notifications_enabled' => [
                'service.notifications_enabled' => self::NO_YES
            ],
            'service_notifications_enabled_changed' => self::DROP,
            'service_obsessing' => self::DROP,
            'service_obsessing_changed' => self::DROP,
            'service_output' => [
                'service.state.output' => self::USE_EXPR
            ],
            'service_passive_checks_enabled' => [
                'service.passive_checks_enabled' => self::USE_EXPR
            ],
            'service_passive_checks_enabled_changed' => self::DROP,
            'service_perfdata' => [
                'service.state.performance_data' => self::USE_EXPR
            ],
            'service_problem' => [
                'service.state.is_problem' => self::NO_YES
            ],
            'service_severity' => [
                'service.state.severity' => self::USE_EXPR
            ],
            'service_state' => [
                'service.state.soft_state' => self::USE_EXPR
            ],
            'service_state_type' => [
                'service.state.state_type' => ['soft', 'hard']
            ],
            'service_unhandled' => [
                'service.state.is_handled' => array_reverse(self::NO_YES)
            ],

            // Filter columns
            'host_contact' => [
                'host.user.name' => self::USE_EXPR
            ],
            'host_contactgroup' => [
                'host.usergroup.name' => self::USE_EXPR
            ],
            'service_contact' => [
                'service.user.name' => self::USE_EXPR
            ],
            'service_contactgroup' => [
                'service.usergroup.name' => self::USE_EXPR
            ],
            'service_host' => [
                'host.name_ci' => self::USE_EXPR
            ],

            // Query columns the dataview doesn't include, added here because it's possible to filter for them anyway
            'host_icon_image' => self::DROP,
            'host_icon_image_alt' => self::DROP,
            'host_notes' => self::DROP,
            'host_acknowledgement_type' => self::DROP,
            'host_active_checks_enabled_changed' => self::DROP,
            'host_attempt' => self::DROP,
            'host_check_command' => self::DROP,
            'host_check_execution_time' => self::DROP,
            'host_check_latency' => self::DROP,
            'host_check_timeperiod_object_id' => self::DROP,
            'host_check_type' => self::DROP,
            'host_current_check_attempt' => self::DROP,
            'host_current_notification_number' => self::DROP,
            'host_event_handler' => self::DROP,
            'host_event_handler_enabled' => self::DROP,
            'host_event_handler_enabled_changed' => self::DROP,
            'host_failure_prediction_enabled' => self::DROP,
            'host_flap_detection_enabled' => self::DROP,
            'host_flap_detection_enabled_changed' => self::DROP,
            'host_is_reachable' => self::DROP,
            'host_last_notification' => self::DROP,
            'host_max_check_attempts' => self::DROP,
            'host_next_check' => self::DROP,
            'host_next_notification' => self::DROP,
            'host_no_more_notifications' => self::DROP,
            'host_normal_check_interval' => self::DROP,
            'host_notifications_enabled_changed' => self::DROP,
            'host_obsessing' => self::DROP,
            'host_obsessing_changed' => self::DROP,
            'host_passive_checks_enabled_changed' => self::DROP,
            'host_percent_state_change' => self::DROP,
            'host_problem_has_been_acknowledged' => self::DROP,
            'host_process_performance_data' => self::DROP,
            'host_retry_check_interval' => self::DROP,
            'host_scheduled_downtime_depth' => self::DROP,
            'host_status_update_time' => self::DROP,
            'host_unhandled' => self::DROP,
            'object_type' => self::DROP,
            'service_check_interval' => self::DROP,
            'service_icon_image' => self::DROP,
            'service_icon_image_alt' => self::DROP,
            'service_check_execution_time' => self::DROP,
            'service_check_latency' => self::DROP,
            'service_check_timeperiod_object_id' => self::DROP,
            'service_check_type' => self::DROP,
            'service_event_handler' => self::DROP,
            'service_failure_prediction_enabled' => self::DROP,
            'service_is_passive_checked' => self::DROP,
            'service_next_notification' => self::DROP,
            'service_next_update' => function ($filter) {
                /** @var Condition $filter */
                if ($filter->getValue() !== 'now') {
                    return false;
                }

                // Doesn't get dropped because there's a default dashlet using it..
                // Though since this dashlet uses it to check for overdue services we'll
                // replace it as next_update is volatile (only in redis up2date)
                return Filter::equal('service.state.is_overdue', $filter instanceof LessThan ? 'y' : 'n');
            },
            'service_no_more_notifications' => self::DROP,
            'service_normal_check_interval' => self::DROP,
            'service_percent_state_change' => self::DROP,
            'service_problem_has_been_acknowledged' => self::DROP,
            'service_process_performance_data' => self::DROP,
            'service_retry_check_interval' => self::DROP,
            'service_scheduled_downtime_depth' => self::DROP,
            'service_status_update_time' => self::DROP,
            'problems' => self::DROP,
        ];
    }
}
