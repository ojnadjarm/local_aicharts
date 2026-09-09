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
     * A weekly chart is due at the first day of the site calendar week.
     *
     * @covers \local_aicharts\local\schedule::due_time
     */
    public function test_weekly_due_at_week_start(): void {
        $wednesday = strtotime('2026-09-09 10:00 UTC');

        $this->assertSame(strtotime('2026-09-07 06:00 UTC'), schedule::due_time('weekly', 6, $wednesday));
        $this->assertTrue(schedule::is_due(self::chart('weekly', 6), $wednesday));
    }

    /**
     * A monthly chart is due on the first of the month.
     *
     * @covers \local_aicharts\local\schedule::due_time
     */
    public function test_monthly_due_on_first(): void {
        $now = strtotime('2026-09-09 10:00 UTC');

        $this->assertSame(strtotime('2026-09-01 06:00 UTC'), schedule::due_time('monthly', 6, $now));
        $this->assertSame(0, schedule::due_time('live', 6, $now));
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
        $this->assertSame(strtotime('2026-10-01 06:00 UTC'), schedule::next_run(self::chart('monthly', 6), $now));
        $this->assertSame(0, schedule::next_run(self::chart('live', 6), $now));
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
     * @return \stdClass Chart record.
     */
    private static function chart(string $runmode, int $runhour, ?int $lastrun = null): \stdClass {
        return (object) ['runmode' => $runmode, 'runhour' => $runhour, 'lastrun' => $lastrun];
    }
}
