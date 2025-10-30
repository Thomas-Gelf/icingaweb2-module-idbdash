<?php

namespace Icinga\Module\Idbdash;

use Icinga\Module\Icingadb\Common\Database;
use Icinga\Module\Icingadb\Model\Timeperiod as IcingaDbTimeperiod;
use Icinga\Module\Icingadb\Model\TimeperiodOverrideExclude;
use Icinga\Module\Icingadb\Model\TimeperiodOverrideInclude;
use Icinga\Module\Icingadb\Model\TimeperiodRange as TimeperiodRangeModel;

class IcingaDbLookup
{
    use Database;

    /**
     * @return TimePeriod[]
     * @throws \Icinga\Exception\ConfigurationError
     */
    public function loadTimePeriods(): array
    {
        $db = $this->getDb();

        $periods = [];
        foreach (IcingaDbTimeperiod::on($db) as $timePeriod) {
            $periods[$timePeriod->id] = new TimePeriod(
                $timePeriod->id,
                $timePeriod->name,
                $timePeriod->prefer_includes === 'y'
            );
        }
        foreach (TimeperiodRangeModel::on($db) as $range) {
            $periods[$range->timeperiod_id]->addRange(new TimePeriodRange($range->range_key, $range->range_value));
        }
        foreach (TimeperiodOverrideExclude::on($db) as $exclude) {
            $periods[$exclude->timeperiod_id]->addExclude($periods[$exclude->override_id]);
        }
        foreach (TimeperiodOverrideInclude::on($db) as $include) {
            $periods[$include->timeperiod_id]->addInclude($periods[$include->override_id]);
        }

        return $periods;
    }
}
