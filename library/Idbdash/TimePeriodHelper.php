<?php

namespace Icinga\Module\Idbdash;

class TimePeriodHelper
{
    /**
     * @param TimePeriod[] $periods
     * @return TimePeriod[]
     */
    public static function filterActiveTimePeriods(array $periods, ?\DateTimeInterface $now = null): array
    {
        $filtered = [];
        foreach ($periods as $period) {
            if ($period->isActive($now)) {
                $filtered[] = $period;
            }
        }

        return $filtered;
    }
}
