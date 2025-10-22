<?php

namespace Icinga\Module\Idbdash;

use DateTimeImmutable as DT;
use DateTimeInterface as DTI;
use InvalidArgumentException;

class TimePeriodSlot
{
    protected const REGEXP_DOM
        = '/^(january|february|march|april|may|june|july|august|september|october|november|december)\s(\d{1,2})$/i';
    protected const REGEXP_DOW = '/^(?:mon|tues|wednes|thurs|fri|satur|sun)day$/i';

    public function __construct(
        public readonly DT $begin,
        public readonly DT $end,
    ) {
        if ($begin >= $end) {
            throw new InvalidArgumentException(sprintf(
                'TimePeriodSlot: begin (%s) must be before end (%s)',
                $begin->format('c'),
                $end->format('c')
            ));
        }
    }

    /**
     * @throws UnsupportedDefinitionError
     */
    public static function createSlotsForRange(TimePeriodRange $range, DTI $from, DTI $to): TimeSlotList
    {
        $dailySlots = self::splitTimeRange($range->rangeValue);
        $slots = [];
        foreach (self::generateDays($range->rangeKey, $from, $to) as $day) {
            foreach ($dailySlots as $slot) {
                $slots[] = new TimePeriodSlot(
                    DT::createFromFormat('Y-m-d H:i', $day . ' ' . $slot[0]),
                    DT::createFromFormat('Y-m-d H:i', $day . ' ' . $slot[1]),
                );
            }
        }

        return new TimeSlotList($slots);
    }

    /**
     * @return string[]
     * @throws UnsupportedDefinitionError
     */
    protected static function generateDays(string $expression, DTI $from, DTI $to): array
    {
        $days = [];
        while ($next = self::generateNext($expression, $from, $to)) {
            $from = $next;
            $days[] = $next->format('Y-m-d');
        }

        return $days;
    }

    protected static function generateNext(string $expression, DTI $after, DTI $to): ?DT
    {
        $expression = trim($expression);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $expression)) {
            $next = new DT($expression);
            if ($next <= $after) {
                return null;
            }
        } else {
            $datetime = \DateTime::createFromInterface($after);
            if (preg_match(self::REGEXP_DOW, $expression)) {
                $next = $datetime->modify("next $expression");
            } elseif (preg_match(self::REGEXP_DOM, $expression)) {
                $next = $datetime->modify($expression);
                if ($next <= $after) {
                    $next = $next->modify("next year");
                }
            } else {
                // TODO:
                // january 1
                // july 4
                // december 31
                // monday -1 may
                // monday 1 september
                // thursday 4 november

                throw new UnsupportedDefinitionError('Unsupported: ' . $expression);
            }
        }

        if ($next >= $to) {
            return null;
        }

        return DT::createFromInterface($next);
    }

    /**
     * @return array{0:string, 1:string}
     * @throws UnsupportedDefinitionError
     */
    protected static function splitTimeRange(string $range): array
    {
        $slots = [];
        foreach (preg_split('/\s*,\s*/', trim($range)) as $slot) {
            if (preg_match('/^(\d{1,2}:\d{2})-(\d{1,2}:\d{2})$/', $slot, $match)) {
                $slots[] = [$match[1], $match[2]];
            } else {
                throw new UnsupportedDefinitionError("Unsupported time slot: $slot");
            }
        }

        return $slots;
    }
}
