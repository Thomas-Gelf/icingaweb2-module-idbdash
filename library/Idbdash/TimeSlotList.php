<?php

namespace Icinga\Module\Idbdash;

use DateTimeInterface;

class TimeSlotList
{
    /** @var TimePeriodSlot[] */
    protected array $slots;

    /**
     * @param TimePeriodSlot[] $slots
     */
    public function __construct(array $slots)
    {
        foreach ($slots as $slot) {
            $this->slots[$slot->begin->getTimestamp()] = $slot;
        }

        $this->slots = $slots;
        ksort($this->slots);
    }

    public function contains(DateTimeInterface $time): bool
    {
        foreach ($this->slots as $ts => $slot) {
            if ($slot->begin > $time) {
                continue;
            }
            if ($slot->end > $time) {
                return true;
            }

            // we already reached the first possible timestamp, and they are sorted and not overlapping
            // return false;
        }

        return false;
    }

    public function with(TimeSlotList $other): TimeSlotList
    {
        if (empty($this->slots)) {
            return clone $other;
        }
        if (empty($other->slots)) {
            return clone $this;
        }

        $allSlots = array_merge(array_values($this->slots), array_values($other->slots));
        usort($allSlots, fn($a, $b) => $a->begin <=> $b->begin);

        $merged = [];
        $current = array_shift($allSlots);

        foreach ($allSlots as $slot) {
            if ($slot->begin <= $current->end) {
                // Overlapping or adjacent slots - merge them
                $current = new TimePeriodSlot(
                    min($current->begin, $slot->begin),
                    max($current->end, $slot->end)
                );
            } else {
                $merged[] = $current;
                $current = $slot;
            }
        }
        $merged[] = $current;

        return new TimeSlotList($merged);
    }

    public function without(TimeSlotList $subtract): TimeSlotList
    {
        $resultSlots = [];

        foreach ($this->slots as $originalSlot) {
            $remainingSlots = [$originalSlot];

            foreach ($subtract->slots as $subtractSlot) {
                $newRemainingParts = [];

                foreach ($remainingSlots as $currentPart) {
                    // No overlapping
                    if ($currentPart->end <= $subtractSlot->begin || $currentPart->begin >= $subtractSlot->end) {
                        $newRemainingParts[] = $currentPart;
                        continue;
                    }

                    // Split the slot if needed
                    if ($currentPart->begin < $subtractSlot->begin) {
                        $newRemainingParts[] = new TimePeriodSlot($currentPart->begin, $subtractSlot->begin);
                    }

                    if ($currentPart->end > $subtractSlot->end) {
                        $newRemainingParts[] = new TimePeriodSlot($subtractSlot->end, $currentPart->end);
                    }
                }

                $remainingSlots = $newRemainingParts;
                if (empty($remainingSlots)) {
                    break;
                }
            }

            $resultSlots = array_merge($resultSlots, $remainingSlots);
        }

        return new TimeSlotList($resultSlots);
    }

    /**
     * @return TimePeriodSlot[]
     */
    public function getSlots(): array
    {
        return array_values($this->slots);
    }
}
