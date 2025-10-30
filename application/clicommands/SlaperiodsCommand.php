<?php

namespace Icinga\Module\Idbdash\CliCommands;

use DateTimeImmutable;
use Icinga\Cli\Command;
use Icinga\Module\Icingadb\Common\Database;
use Icinga\Module\Idbdash\IcingaDbLookup;
use ipl\Sql\Select;

class SlaperiodsCommand extends Command
{
    use Database;

    public function generateAction(): void
    {
        $start = new DateTimeImmutable('00:00 -1day');
        $end = new DateTimeImmutable('00:00 +7day');
        $db = new IcingaDbLookup();
        $dba = $db->getDb();
        $periods = $db->loadTimePeriods();
        $table = 'idbdash_sla_periods';
        $tsStart = $start->format('U') * 1_000;
        $tsEnd = $end->format('U') * 1_000;
        $current = [];
        foreach ($dba->select(
            (new Select())->from($table)
                ->columns(['timeperiod_id', 'start_time', 'end_time'])
                ->where(
                    "(start_time >= $tsStart AND start_time < $tsEnd) OR (end_time >= $tsStart AND end_time < $tsEnd)"
                )
        )->fetchAll() as $row) {
            $current[$row->timeperiod_id . $row->start_time] = $row;
        }

        $seen = [];
        $new = [];
        $modify = [];
        $remove = [];
        foreach ($periods as $period) {
            foreach ($period->getResolvedTimeSlots($start, $end)->getSlots() as $slot) {
                $slotStart = (int) ($slot->begin->format('U') * 1_000);
                $slotEnd = (int) ($slot->end->format('U') * 1_000);
                $key = $period->id . $slotStart;
                $seen[$key] = true;
                $newRow = [
                    'timeperiod_id' => $period->id,
                    'start_time'    => $slotStart,
                    'end_time'      => $slotEnd,
                ];
                if (isset($current[$key])) {
                    if ((int) $current[$key]->end_time !== $slotEnd) {
                        $modify[$key] = $newRow;
                    }
                } else {
                    $new[] = $newRow;
                }
            }
        }
        foreach ($current as $key => $row) {
            if (! isset($seen[$key])) {
                $remove[] = (array) $row;
            }
        }

        if (empty($new) && empty($modify) && empty($remove)) {
            return;
        }
        $dba->beginTransaction();
        try {
            foreach ($remove as $row) {
                $dba->delete($table, [
                    'timeperiod_id = ?' => $row['timeperiod_id'],
                    'start_time = ?'    => $row['start_time'],
                ]);
            }
            foreach ($modify as $row) {
                $dba->update($table, ['end_time' => $row['end_time']], [
                    'timeperiod_id = ?' => $row['timeperiod_id'],
                    'start_time = ?'    => $row['start_time'],
                ]);
            }
            foreach ($new as $row) {
                $dba->insert($table, $row);
            }
            $dba->commitTransaction();
        } catch (\Exception $e) {
            try {
                $dba->rollBackTransaction();
            } catch (\Exception) {
                // ignore
            }

            throw $e;
        }
    }
}
