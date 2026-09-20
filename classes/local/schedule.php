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

use core_date;
use core_text;
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

    /** @var string[] Scheduled run modes. */
    protected const SCHEDULED = ['daily', 'weekly', 'monthly'];

    /** @var int Month day meaning the last day of the month. */
    public const LAST_DAY = 31;

    /** @var string[] Calendar string of each weekday, indexed like PHP's w format. */
    protected const WEEKDAYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    /**
     * Returns the start of the current period at the given hour, in the site timezone.
     *
     * @param string $runmode One of self::MODES.
     * @param int $runhour Hour of the day, 0 to 23.
     * @param int $now Reference time.
     * @param int $runday Weekday 0 to 6 for weekly, day of the month for monthly (clamped to the month).
     * @return int Timestamp, or 0 when the mode is not scheduled.
     */
    public static function due_time(string $runmode, int $runhour, int $now, int $runday = 1): int {
        if (!in_array($runmode, self::SCHEDULED, true)) {
            return 0;
        }

        $date = (new DateTimeImmutable('@' . $now))
            ->setTimezone(core_date::get_server_timezone_object())
            ->setTime($runhour, 0);

        if ($runmode === 'weekly') {
            $back = ((int) $date->format('w') - $runday + 7) % 7;
            $date = $date->modify('-' . $back . ' days');
        } else if ($runmode === 'monthly') {
            $day = min($runday, (int) $date->format('t'));
            $date = $date->setDate((int) $date->format('Y'), (int) $date->format('n'), $day);
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
        $due = self::due_time($chart->runmode, (int) $chart->runhour, $now, self::runday($chart));

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
        $runday = self::runday($chart);
        $due = self::due_time($chart->runmode, (int) $chart->runhour, $now, $runday);
        if ($due === 0 || $due > $now) {
            return $due;
        }

        $anchor = (new DateTimeImmutable('@' . $due))->setTimezone(core_date::get_server_timezone_object());
        if ($chart->runmode === 'monthly') {
            $start = $anchor->setDate((int) $anchor->format('Y'), (int) $anchor->format('n'), 1)
                ->setTime(0, 0)
                ->modify('+1 month');
        } else {
            $start = $anchor->modify($chart->runmode === 'weekly' ? '+1 week' : '+1 day');
        }

        return self::due_time($chart->runmode, (int) $chart->runhour, $start->getTimestamp(), $runday);
    }

    /**
     * Whether a chart adds one point per run instead of replacing its data.
     *
     * @param stdClass $chart Chart record.
     * @return bool
     */
    public static function is_trend(stdClass $chart): bool {
        return ($chart->kind ?? '') === 'trend';
    }

    /**
     * Describes the schedule of a chart, day included.
     *
     * @param stdClass $chart Chart record.
     * @return string Human readable schedule, lower case.
     */
    public static function describe(stdClass $chart): string {
        $runday = self::runday($chart);
        if ($chart->runmode === 'weekly') {
            return get_string('runday_weekly', 'local_aicharts', self::weekday_name($runday));
        }
        if ($chart->runmode === 'monthly') {
            return $runday >= 29
                ? get_string('runday_monthlylast', 'local_aicharts')
                : get_string('runday_monthly', 'local_aicharts', $runday);
        }

        return core_text::strtolower(self::label($chart->runmode));
    }

    /**
     * Returns the translated name of a weekday.
     *
     * @param int $weekday 0 for Sunday to 6 for Saturday.
     * @return string Weekday name.
     */
    public static function weekday_name(int $weekday): string {
        return get_string(self::WEEKDAYS[$weekday % 7], 'calendar');
    }

    /**
     * Reads the run day of a chart record.
     *
     * @param stdClass $chart Chart record.
     * @return int Run day, 1 when the record carries none.
     */
    protected static function runday(stdClass $chart): int {
        return (int) ($chart->runday ?? 1);
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
