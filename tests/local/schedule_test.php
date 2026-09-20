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

/**
 * Tests for the schedule helper.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schedule_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('UTC');
        $CFG->calendar_startwday = 1;
    }

    /**
     * A daily chart becomes due once its hour has passed.
     *
     * @covers \local_aicharts\local\schedule::due_time
     * @covers \local_aicharts\local\schedule::is_due
     */
    public function test_daily_due_after_run_hour(): void {
        $chart = self::chart('daily', 6);

        $before = strtotime('2026-09-09 05:30 UTC');
        $after = strtotime('2026-09-09 06:30 UTC');

        $this->assertSame(strtotime('2026-09-09 06:00 UTC'), schedule::due_time('daily', 6, $after));
        $this->assertFalse(schedule::is_due($chart, $before));
        $this->assertTrue(schedule::is_due($chart, $after));
    }

    /**
     * A weekly chart is due on its own weekday, one by default.
     *
     * @covers \local_aicharts\local\schedule::due_time
     */
    public function test_weekly_due_at_week_start(): void {
        $wednesday = strtotime('2026-09-09 10:00 UTC');

        $this->assertSame(strtotime('2026-09-07 06:00 UTC'), schedule::due_time('weekly', 6, $wednesday));
        $this->assertTrue(schedule::is_due(self::chart('weekly', 6), $wednesday));
    }

    /**
     * A weekly chart is due on the most recent occurrence of its weekday.
     *
     * @covers \local_aicharts\local\schedule::due_time
     * @covers \local_aicharts\local\schedule::is_due
     */
    public function test_weekly_due_on_chosen_day(): void {
        $wednesday = 3;
        $friday = strtotime('2026-09-11 10:00 UTC');
        $tuesday = strtotime('2026-09-08 10:00 UTC');

        $this->assertSame(strtotime('2026-09-09 06:00 UTC'), schedule::due_time('weekly', 6, $friday, $wednesday));
        $this->assertSame(strtotime('2026-09-02 06:00 UTC'), schedule::due_time('weekly', 6, $tuesday, $wednesday));
        $this->assertTrue(schedule::is_due(self::chart('weekly', 6, null, $wednesday), $friday));
        $ran = self::chart('weekly', 6, strtotime('2026-09-09 06:05 UTC'), $wednesday);
        $this->assertFalse(schedule::is_due($ran, $friday));
    }

    /**
     * A weekly chart on its own day is not due before its hour.
     *
     * @covers \local_aicharts\local\schedule::is_due
     * @covers \local_aicharts\local\schedule::next_run
     */
    public function test_weekly_not_due_before_hour_on_its_day(): void {
        $chart = self::chart('weekly', 6, null, 3);
        $early = strtotime('2026-09-09 05:00 UTC');

        $this->assertFalse(schedule::is_due($chart, $early));
        $this->assertSame(strtotime('2026-09-09 06:00 UTC'), schedule::next_run($chart, $early));
    }

    /**
     * Weekly runs keep their wall-clock hour across a DST change.
     *
     * @covers \local_aicharts\local\schedule::due_time
     */
    public function test_weekly_keeps_hour_across_dst(): void {
        $this->setTimezone('Europe/Madrid');
        $sunday = 0;
        $tuesday = strtotime('2026-03-31 10:00 Europe/Madrid');

        $this->assertSame(strtotime('2026-03-29 06:00 Europe/Madrid'), schedule::due_time('weekly', 6, $tuesday, $sunday));
        $this->assertSame(
            strtotime('2026-04-05 06:00 Europe/Madrid'),
            schedule::next_run(self::chart('weekly', 6, null, $sunday), $tuesday)
        );
    }

    /**
     * A monthly chart is due on the first of the month by default.
     *
     * @covers \local_aicharts\local\schedule::due_time
     */
    public function test_monthly_due_on_first(): void {
        $now = strtotime('2026-09-09 10:00 UTC');

        $this->assertSame(strtotime('2026-09-01 06:00 UTC'), schedule::due_time('monthly', 6, $now));
        $this->assertSame(0, schedule::due_time('live', 6, $now));
    }

    /**
     * A monthly chart is due on its chosen day of the month.
     *
     * @covers \local_aicharts\local\schedule::due_time
     * @covers \local_aicharts\local\schedule::is_due
     */
    public function test_monthly_due_on_chosen_day(): void {
        $chart = self::chart('monthly', 6, null, 15);

        $later = strtotime('2026-09-20 10:00 UTC');

        $this->assertSame(strtotime('2026-09-15 06:00 UTC'), schedule::due_time('monthly', 6, $later, 15));
        $this->assertFalse(schedule::is_due($chart, strtotime('2026-09-09 10:00 UTC')));
        $this->assertTrue(schedule::is_due($chart, strtotime('2026-09-15 06:00 UTC')));
    }

    /**
     * Day 31 means the last day of the month, so February is never skipped.
     *
     * @covers \local_aicharts\local\schedule::due_time
     * @covers \local_aicharts\local\schedule::next_run
     */
    public function test_monthly_last_day_never_skips_short_months(): void {
        $chart = self::chart('monthly', 6, null, schedule::LAST_DAY);

        $february = strtotime('2027-02-28 10:00 UTC');

        $this->assertSame(strtotime('2027-02-28 06:00 UTC'), schedule::due_time('monthly', 6, $february, 31));
        $this->assertSame(strtotime('2027-02-28 06:00 UTC'), schedule::next_run($chart, strtotime('2027-01-31 10:00 UTC')));
        $this->assertSame(strtotime('2027-03-31 06:00 UTC'), schedule::next_run($chart, $february));
        $this->assertSame(strtotime('2027-02-28 06:00 UTC'), schedule::next_run($chart, strtotime('2027-02-10 10:00 UTC')));
    }

    /**
     * A chart already run in the current period is not due again.
     *
     * @covers \local_aicharts\local\schedule::is_due
     */
    public function test_not_due_when_lastrun_after_due(): void {
        $now = strtotime('2026-09-09 10:00 UTC');
        $chart = self::chart('daily', 6, strtotime('2026-09-09 06:01 UTC'));

        $this->assertFalse(schedule::is_due($chart, $now));

        $chart->lastrun = strtotime('2026-09-08 06:01 UTC');
        $this->assertTrue(schedule::is_due($chart, $now));
    }

    /**
     * Two charts with different hours become due at their own hour.
     *
     * @covers \local_aicharts\local\schedule::is_due
     */
    public function test_two_charts_with_different_hours(): void {
        $now = strtotime('2026-09-09 05:00 UTC');
        $early = self::chart('daily', 3);
        $late = self::chart('daily', 22);

        $this->assertTrue(schedule::is_due($early, $now));
        $this->assertFalse(schedule::is_due($late, $now));
    }

    /**
     * The next run is the first due time after the given moment.
     *
     * @covers \local_aicharts\local\schedule::next_run
     */
    public function test_next_run(): void {
        $now = strtotime('2026-09-09 10:00 UTC');

        $this->assertSame(strtotime('2026-09-10 06:00 UTC'), schedule::next_run(self::chart('daily', 6), $now));
        $this->assertSame(strtotime('2026-09-09 22:00 UTC'), schedule::next_run(self::chart('daily', 22), $now));
        $this->assertSame(strtotime('2026-09-14 06:00 UTC'), schedule::next_run(self::chart('weekly', 6), $now));
        $this->assertSame(strtotime('2026-09-11 06:00 UTC'), schedule::next_run(self::chart('weekly', 6, null, 5), $now));
        $this->assertSame(strtotime('2026-09-16 06:00 UTC'), schedule::next_run(self::chart('weekly', 6, null, 3), $now));
        $this->assertSame(strtotime('2026-10-01 06:00 UTC'), schedule::next_run(self::chart('monthly', 6), $now));
        $this->assertSame(strtotime('2026-09-15 06:00 UTC'), schedule::next_run(self::chart('monthly', 6, null, 15), $now));
        $later = strtotime('2026-09-20 10:00 UTC');
        $this->assertSame(strtotime('2026-10-15 06:00 UTC'), schedule::next_run(self::chart('monthly', 6, null, 15), $later));
        $this->assertSame(0, schedule::next_run(self::chart('live', 6), $now));
    }

    /**
     * The schedule description names the day of a weekly or monthly chart.
     *
     * @covers \local_aicharts\local\schedule::describe
     * @covers \local_aicharts\local\schedule::weekday_name
     */
    public function test_describe(): void {
        $this->assertSame('daily', schedule::describe(self::chart('daily', 6)));
        $this->assertSame('weekly on Monday', schedule::describe(self::chart('weekly', 6)));
        $this->assertSame('weekly on Sunday', schedule::describe(self::chart('weekly', 6, null, 0)));
        $this->assertSame('monthly on day 15', schedule::describe(self::chart('monthly', 6, null, 15)));
        $this->assertSame('monthly on the last day', schedule::describe(self::chart('monthly', 6, null, 31)));
        $this->assertSame('live', schedule::describe(self::chart('live', 6)));
    }

    /**
     * Each run mode has a label.
     *
     * @covers \local_aicharts\local\schedule::label
     */
    public function test_label(): void {
        $this->assertSame('Weekly', schedule::label('weekly'));
        $this->assertSame('Live', schedule::label(schedule::MODE_LIVE));
    }

    /**
     * Builds a chart record for the schedule helper.
     *
     * @param string $runmode Run mode.
     * @param int $runhour Hour of the day.
     * @param int|null $lastrun Time of the last run.
     * @param int $runday Weekday or day of the month.
     * @return \stdClass Chart record.
     */
    private static function chart(string $runmode, int $runhour, ?int $lastrun = null, int $runday = 1): \stdClass {
        return (object) ['runmode' => $runmode, 'runhour' => $runhour, 'lastrun' => $lastrun, 'runday' => $runday];
    }
}
