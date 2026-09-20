<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_aicharts\local;

use core_calendar\type_factory;
use core_date;
use DateTimeImmutable;
use stdClass;

/**
 * Works out when a scheduled chart is due to run.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schedule {
    /** @var string Run mode of a chart that is queried on every dashboard load. */
    public const MODE_LIVE = 'live';

    /** @var string[] Every run mode a chart may carry. */
    public const MODES = [self::MODE_LIVE, 'daily', 'weekly', 'monthly'];

    /** @var string[] Interval between two runs of a scheduled mode. */
    protected const INTERVALS = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'];

    /**
     * Returns the start of the current period at the given hour, in the site timezone.
     *
     * @param string $runmode One of self::MODES.
     * @param int $runhour Hour of the day, 0 to 23.
     * @param int $now Reference time.
     * @return int Timestamp, or 0 when the mode is not scheduled.
     */
    public static function due_time(string $runmode, int $runhour, int $now): int {
        if (!isset(self::INTERVALS[$runmode])) {
            return 0;
        }

        $date = (new DateTimeImmutable('@' . $now))
            ->setTimezone(core_date::get_server_timezone_object())
            ->setTime($runhour, 0);

        if ($runmode === 'weekly') {
            $startingweekday = type_factory::get_calendar_instance()->get_starting_weekday();
            $back = ((int) $date->format('w') - $startingweekday + 7) % 7;
            $date = $date->modify('-' . $back . ' days');
        } else if ($runmode === 'monthly') {
            $date = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1);
        }

        return $date->getTimestamp();
    }

    /**
     * Tells whether the chart has not been run yet in the current period.
     *
     * @param stdClass $chart Chart record.
     * @param int $now Reference time.
     * @return bool True when the chart should be queued.
     */
    public static function is_due(stdClass $chart, int $now): bool {
        $due = self::due_time($chart->runmode, (int) $chart->runhour, $now);

        return $due > 0 && $now >= $due && (int) $chart->lastrun < $due;
    }

    /**
     * Returns the first due time after the given moment.
     *
     * @param stdClass $chart Chart record.
     * @param int $now Reference time.
     * @return int Timestamp, or 0 when the chart is not scheduled.
     */
    public static function next_run(stdClass $chart, int $now): int {
        $due = self::due_time($chart->runmode, (int) $chart->runhour, $now);
        if ($due === 0 || $due > $now) {
            return $due;
        }

        return (new DateTimeImmutable('@' . $due))
            ->setTimezone(core_date::get_server_timezone_object())
            ->modify(self::INTERVALS[$chart->runmode])
            ->getTimestamp();
    }

    /**
     * Returns the translated name of a run mode.
     *
     * @param string $runmode One of self::MODES.
     * @return string Human readable label.
     */
    public static function label(string $runmode): string {
        return get_string('runmode_' . $runmode, 'local_aicharts');
    }
}
