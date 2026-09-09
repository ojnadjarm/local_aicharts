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
 * Tests for the chart repository.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chart_repository_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $DB->delete_records('local_aicharts_chart');
    }

    /**
     * Saves a chart and reads it back.
     *
     * @covers \local_aicharts\local\chart_repository::save
     * @covers \local_aicharts\local\chart_repository::get
     */
    public function test_save_and_get(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = chart_repository::save((object) [
            'name' => 'Enrolments per course',
            'prompt' => 'enrolments per course this year',
            'sqltext' => 'SELECT c.fullname, COUNT(ue.id) FROM {course} c',
            'params' => '{}',
            'chartjson' => '{"type":"bar"}',
        ]);

        $chart = chart_repository::get($id);

        $this->assertSame('Enrolments per course', $chart->name);
        $this->assertSame('', $chart->emailto);
        $this->assertSame('live', $chart->runmode);
        $this->assertEquals(1, $chart->enabled);
        $this->assertEquals($id, $chart->id);
        $this->assertGreaterThan(0, $chart->timecreated);
        $this->assertSame($chart->timecreated, $chart->timemodified);
        $this->assertNull(chart_repository::get($id + 100));
    }

    /**
     * Saving a record that carries an id updates it instead of inserting.
     *
     * @covers \local_aicharts\local\chart_repository::save
     */
    public function test_save_updates_existing(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->create_chart(['name' => 'Old name']);
        $chart = chart_repository::get($id);
        $chart->name = 'New name';

        $this->assertSame($id, chart_repository::save($chart));
        $this->assertSame('New name', $DB->get_field('local_aicharts_chart', 'name', ['id' => $id]));
        $this->assertEquals(1, $DB->count_records('local_aicharts_chart'));
    }

    /**
     * Scheduled charts come first, then the live ones, each group by name.
     *
     * @covers \local_aicharts\local\chart_repository::list_all
     */
    public function test_list_all_orders_scheduled_first_then_name(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_chart(['name' => 'Alpha live', 'runmode' => 'live']);
        $this->create_chart(['name' => 'Zulu live', 'runmode' => 'live']);
        $this->create_chart(['name' => 'Weekly report', 'runmode' => 'weekly']);
        $this->create_chart(['name' => 'Daily report', 'runmode' => 'daily']);

        $names = array_column(chart_repository::list_all(), 'name');

        $this->assertSame(['Daily report', 'Weekly report', 'Alpha live', 'Zulu live'], $names);
    }

    /**
     * Pausing and resuming a chart flips the enabled flag.
     *
     * @covers \local_aicharts\local\chart_repository::set_enabled
     */
    public function test_set_enabled(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $id = $this->create_chart();

        chart_repository::set_enabled($id, false);
        $this->assertEquals(0, chart_repository::get($id)->enabled);

        chart_repository::set_enabled($id, true);
        $this->assertEquals(1, chart_repository::get($id)->enabled);
    }

    /**
     * Deleting a chart removes only that chart.
     *
     * @covers \local_aicharts\local\chart_repository::delete
     */
    public function test_delete(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $keep = $this->create_chart(['name' => 'Keep']);
        $drop = $this->create_chart(['name' => 'Drop']);

        chart_repository::delete($drop);

        $this->assertNull(chart_repository::get($drop));
        $this->assertNotNull(chart_repository::get($keep));
    }

    /**
     * The charts sharing the most words with the prompt come first.
     *
     * @covers \local_aicharts\local\chart_repository::find_examples
     */
    public function test_find_examples_ranks_by_overlap(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_chart(['name' => 'Logins', 'prompt' => 'logins per day']);
        $this->create_chart(['name' => 'Enrolments', 'prompt' => 'enrolments per course this year']);
        $this->create_chart(['name' => 'Completions', 'prompt' => 'completions per course']);
        $this->create_chart(['name' => 'Paused', 'prompt' => 'enrolments per course', 'enabled' => 0]);

        $examples = chart_repository::find_examples('enrolments per course last year');

        $this->assertSame(['Enrolments', 'Completions'], array_column($examples, 'name'));
    }

    /**
     * A chart with a very long statement is never sent as an example.
     *
     * @covers \local_aicharts\local\chart_repository::find_examples
     */
    public function test_find_examples_skips_long_sql(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $this->create_chart([
            'name' => 'Enrolments',
            'prompt' => 'enrolments per course',
            'sqltext' => 'SELECT ' . str_repeat('x', 2000),
        ]);
        $this->create_chart(['name' => 'Short', 'prompt' => 'enrolments per course']);

        $examples = chart_repository::find_examples('enrolments per course');

        $this->assertSame(['Short'], array_column($examples, 'name'));
    }

    /**
     * When almost nothing matches, the newest default charts pad the set.
     *
     * @covers \local_aicharts\local\chart_repository::find_examples
     */
    public function test_find_examples_pads_with_defaults(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('examplelimit', 3, 'local_aicharts');

        $this->create_chart(['name' => 'Older default', 'prompt' => 'logins per day', 'isdefault' => 1]);
        $this->create_chart(['name' => 'Newer default', 'prompt' => 'grades per course', 'isdefault' => 1,
            'timemodified' => time() + 100]);
        $this->create_chart(['name' => 'Plain chart', 'prompt' => 'sessions per week']);

        $examples = chart_repository::find_examples('badges awarded');

        $this->assertSame(['Newer default', 'Older default'], array_column($examples, 'name'));
    }

    /**
     * A queued chart drops out of the due list until the next period.
     *
     * @covers \local_aicharts\local\chart_repository::list_due
     * @covers \local_aicharts\local\chart_repository::mark_queued
     */
    public function test_mark_queued_removes_chart_from_list_due(): void {
        $this->setTimezone('UTC');
        $now = strtotime('2026-09-09 10:00 UTC');

        $this->create_chart(['name' => 'Live', 'runmode' => 'live', 'runhour' => 6]);
        $id = $this->create_chart(['name' => 'Daily', 'runmode' => 'daily', 'runhour' => 6]);

        $this->assertSame([$id], array_keys(chart_repository::list_due($now)));

        chart_repository::mark_queued($id, $now);

        $this->assertSame([], chart_repository::list_due($now));
        $this->assertSame([$id], array_keys(chart_repository::list_due(strtotime('2026-09-10 06:00 UTC'))));
    }

    /**
     * A paused chart is never listed as due.
     *
     * @covers \local_aicharts\local\chart_repository::list_due
     */
    public function test_paused_chart_is_never_due(): void {
        $this->setTimezone('UTC');

        $this->create_chart(['name' => 'Paused', 'runmode' => 'daily', 'runhour' => 6, 'enabled' => 0]);

        $this->assertSame([], chart_repository::list_due(strtotime('2026-09-09 10:00 UTC')));
    }

    /**
     * Creates a saved chart.
     *
     * @param array $overrides Fields overriding the defaults.
     * @return int The chart id.
     */
    private function create_chart(array $overrides = []): int {
        global $DB;

        $chart = (object) array_merge([
            'name' => 'Chart',
            'prompt' => 'a prompt',
            'sqltext' => 'SELECT 1',
            'params' => '{}',
            'chartjson' => '{"type":"bar"}',
            'emailto' => '',
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides);

        return (int) $DB->insert_record('local_aicharts_chart', $chart);
    }
}
