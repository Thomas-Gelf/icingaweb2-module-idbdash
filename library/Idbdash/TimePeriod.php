<?php

namespace Icinga\Module\Idbdash;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class TimePeriod
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $preferIncludes,
        /** @var TimePeriodRange[] $ranges */
        protected array $ranges = [],
        /** @var TimePeriod[] $includes */
        protected array $includes = [],
        /** @var TimePeriod[] $excludes */
        protected array $excludes = [],
    ) {
    }

    public function addRange(TimePeriodRange $range): void
    {
        if (isset($this->ranges[$range->rangeKey])) {
            throw new InvalidArgumentException('Cannot set range key twice: ' . $range->rangeKey);
        }
        $this->ranges[$range->rangeKey] = $range;
    }

    public function addInclude(TimePeriod $period): void
    {
        $this->includes[] = $period;
    }

    public function addExclude(TimePeriod $period): void
    {
        $this->excludes[] = $period;
    }

    public function getTimeSlots(DateTimeInterface $from, DateTimeInterface $to): TimeSlotList
    {
        $slots = new TimeSlotList([]);
        foreach ($this->ranges as $range) {
            $slots = $slots->with(TimePeriodSlot::createSlotsForRange($range, $from, $to));
        }

        return $slots;
    }

    public function getResolvedTimeSlots(DateTimeInterface $from, DateTimeInterface $to): TimeSlotList
    {
        $slots = $this->getTimeSlots($from, $to);
        if ($this->preferIncludes) {
            foreach ($this->excludes as $exclude) {
                $slots = $slots->without($exclude->getResolvedTimeSlots($from, $to));
            }
            foreach ($this->includes as $include) {
                $slots = $slots->with($include->getResolvedTimeSlots($from, $to));
            }
        } else {
            foreach ($this->includes as $include) {
                $slots = $slots->with($include->getResolvedTimeSlots($from, $to));
            }
            foreach ($this->excludes as $exclude) {
                $slots = $slots->without($exclude->getResolvedTimeSlots($from, $to));
            }
        }

        return $slots;
    }

    public function isActive(?DateTimeInterface $now): bool
    {
        $now ??= new DateTimeImmutable();
        $before = DateTime::createFromInterface($now)->modify('-2 days');
        $after = DateTime::createFromInterface($now)->modify('+2 days');
        // print_r($this->getResolvedTimeSlots($before, $after));

        return $this->getResolvedTimeSlots($before, $after)->contains($now);
    }
}
