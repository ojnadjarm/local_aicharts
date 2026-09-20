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
            'queries' => [['label' => 'Enrolments', 'sqltext' => 'SELECT c.fullname, COUNT(ue.id) FROM {course} c']],
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
     * The queries are stored in the order given and the first one is mirrored into the chart.
     *
     * @covers \local_aicharts\local\chart_repository::save
     */
    public function test_save_writes_queries_in_order(): void {
        global $DB;

        $this->setAdminUser();

        $id = chart_repository::save((object) [
            'name' => 'Enrolments',
            'prompt' => 'enrolments this year vs last year',
            'chartjson' => '{"type":"bar"}',
            'queries' => [
                ['label' => 'This year', 'sqltext' => 'SELECT 1', 'params' => '{"year": 2026}'],
                ['label' => 'Last year', 'sqltext' => 'SELECT 2', 'params' => ''],
            ],
        ]);

        $rows = array_values($DB->get_records('local_aicharts_query', ['chartid' => $id], 'sortorder'));
        $this->assertCount(2, $rows);
        $this->assertSame(['This year', 'Last year'], array_column($rows, 'label'));
        $this->assertSame(['0', '1'], array_column($rows, 'sortorder'));
        $this->assertSame('{}', $rows[1]->params);
    }

    /**
     * The kind, the points retention and each series hint are stored and read back.
     *
     * @covers \local_aicharts\local\chart_repository::save
     * @covers \local_aicharts\local\chart_repository::get
     */
    public function test_save_keeps_hint_and_kind(): void {
        $this->setAdminUser();

        $id = chart_repository::save((object) [
            'name' => 'Active users',
            'prompt' => 'active users',
            'chartjson' => '{"type":"line"}',
            'kind' => 'trend',
            'pointsretention' => 90,
            'queries' => [
                ['label' => 'Active', 'hint' => 'users active in the last month', 'sqltext' => 'SELECT 1'],
                ['label' => 'All', 'sqltext' => 'SELECT 2'],
            ],
        ]);

        $chart = chart_repository::get($id);

        $this->assertSame('trend', $chart->kind);
        $this->assertSame('90', $chart->pointsretention);
        $this->assertSame('users active in the last month', $chart->queries[0]->hint);
        $this->assertNull($chart->queries[1]->hint);
        $this->assertSame('oneshot', chart_repository::get($this->create_chart())->kind);
    }

    /**
     * A chart saved without the queries property keeps its query rows.
     *
     * @covers \local_aicharts\local\chart_repository::save
     */
    public function test_save_without_queries_keeps_rows(): void {
        global $DB;

        $this->setAdminUser();

        $id = chart_repository::save((object) [
            'name' => 'Courses',
            'prompt' => 'courses',
            'queries' => [['label' => 'Courses', 'sqltext' => 'SELECT 3', 'params' => '']],
            'chartjson' => '{"type":"bar"}',
        ]);

        chart_repository::save((object) ['id' => $id, 'name' => 'Renamed']);

        $rows = array_values($DB->get_records('local_aicharts_query', ['chartid' => $id]));
        $this->assertCount(1, $rows);
        $this->assertSame('Courses', $rows[0]->label);
        $this->assertSame('SELECT 3', $rows[0]->sqltext);
        $this->assertSame('{}', $rows[0]->params);
        $this->assertSame('Renamed', $DB->get_field('local_aicharts_chart', 'name', ['id' => $id]));
    }

    /**
     * Every loaded chart carries its queries in sort order.
     *
     * @covers \local_aicharts\local\chart_repository::get
     * @covers \local_aicharts\local\chart_repository::list_all
     * @covers \local_aicharts\local\chart_repository::attach_queries
     */
    public function test_get_attaches_queries(): void {
        global $DB;

        $this->setAdminUser();

        $id = $this->create_chart(['name' => 'Two series'], false);
        $other = $this->create_chart(['name' => 'No series'], false);
        $DB->insert_record('local_aicharts_query', (object) ['chartid' => $id, 'label' => 'B', 'sqltext' => 'SELECT 2',
            'params' => '{}', 'sortorder' => 1]);
        $DB->insert_record('local_aicharts_query', (object) ['chartid' => $id, 'label' => 'A', 'sqltext' => 'SELECT 1',
            'params' => '{}', 'sortorder' => 0]);

        $chart = chart_repository::get($id);
        $this->assertSame(['A', 'B'], array_column($chart->queries, 'label'));
        $this->assertSame('SELECT 1', $chart->queries[0]->sqltext);
        $this->assertSame([], chart_repository::get($other)->queries);

        $all = chart_repository::list_all();
        $this->assertSame(['A', 'B'], array_column($all[$id]->queries, 'label'));
        $this->assertSame([], $all[$other]->queries);
    }

    /**
     * Saving a chart again replaces its queries instead of adding to them.
     *
     * @covers \local_aicharts\local\chart_repository::save
     */
    public function test_save_replaces_queries(): void {
        global $DB;

        $this->setAdminUser();

        $id = chart_repository::save((object) [
            'name' => 'Chart',
            'prompt' => 'a prompt',
            'chartjson' => '{"type":"bar"}',
            'queries' => [
                ['label' => 'One', 'sqltext' => 'SELECT 1', 'params' => '{}'],
                ['label' => 'Two', 'sqltext' => 'SELECT 2', 'params' => '{}'],
            ],
        ]);
        $chart = chart_repository::get($id);
        $chart->queries = [['label' => 'Only', 'sqltext' => 'SELECT 9', 'params' => '{}']];

        chart_repository::save($chart);

        $rows = $DB->get_records('local_aicharts_query', ['chartid' => $id]);
        $this->assertCount(1, $rows);
        $this->assertSame('Only', reset($rows)->label);
    }

    /**
     * Deleting a chart removes its queries and leaves the other charts' ones.
     *
     * @covers \local_aicharts\local\chart_repository::delete
     */
    public function test_delete_removes_queries(): void {
        global $DB;

        $this->setAdminUser();

        $keep = chart_repository::save((object) ['name' => 'Keep', 'prompt' => 'p', 'chartjson' => '{}',
            'queries' => [['label' => 'Keep', 'sqltext' => 'SELECT 1']]]);
        $drop = chart_repository::save((object) ['name' => 'Drop', 'prompt' => 'p', 'chartjson' => '{}',
            'queries' => [['label' => 'Drop', 'sqltext' => 'SELECT 2']]]);

        chart_repository::delete($drop);

        $this->assertSame(0, $DB->count_records('local_aicharts_query', ['chartid' => $drop]));
        $this->assertSame(1, $DB->count_records('local_aicharts_query', ['chartid' => $keep]));
    }

    /**
     * Deleting a chart removes its points and leaves the other charts' ones.
     *
     * @covers \local_aicharts\local\chart_repository::delete
     */
    public function test_delete_removes_points(): void {
        global $DB;

        $this->setAdminUser();

        $keep = $this->create_chart(['name' => 'Keep']);
        $drop = $this->create_chart(['name' => 'Drop']);
        foreach ([$keep, $drop] as $chartid) {
            $DB->insert_record('local_aicharts_point', (object) ['chartid' => $chartid, 'serieslabel' => 'Users',
                'timepoint' => time(), 'value' => 3]);
        }

        chart_repository::delete($drop);

        $this->assertSame(0, $DB->count_records('local_aicharts_point', ['chartid' => $drop]));
        $this->assertSame(1, $DB->count_records('local_aicharts_point', ['chartid' => $keep]));
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
     * @param bool $withquery Whether the chart gets its single query row.
     * @return int The chart id.
     */
    private function create_chart(array $overrides = [], bool $withquery = true): int {
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

        $query = (object) [
            'label' => $chart->name,
            'sqltext' => $chart->sqltext,
            'params' => $chart->params,
            'sortorder' => 0,
        ];
        unset($chart->sqltext, $chart->params);
        $id = (int) $DB->insert_record('local_aicharts_chart', $chart);
        if (!$withquery) {
            return $id;
        }
        $query->chartid = $id;
        $DB->insert_record('local_aicharts_query', $query);

        return $id;
    }
}
