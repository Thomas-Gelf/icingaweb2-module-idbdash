<?php

namespace Icinga\Module\Idbdash;

class TimePeriodRange
{
    public function __construct(
        public readonly string $rangeKey,
        public readonly string $rangeValue
    ) {
    }
}
