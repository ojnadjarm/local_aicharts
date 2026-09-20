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

namespace local_aicharts\task;

use core\task\manager;
use local_aicharts\local\chart_repository;
use local_aicharts\local\result_store;
use stdClass;

/**
 * Tests for the ad hoc chart run task.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_chart_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('resultretention', 30, 'local_aicharts');
    }

    /**
     * Saves a daily chart with the given query.
     *
     * @param string $sql The query to store.
     * @return stdClass The saved chart.
     */
    protected function create_chart(string $sql = 'SELECT shortname, 1 AS total FROM {role}'): stdClass {
        $id = chart_repository::save((object) [
            'name' => 'Roles',
            'prompt' => 'roles',
            'queries' => [[
                'label' => 'Roles',
                'sqltext' => $sql,
                'params' => '{}',
            ]],
            'chartjson' => '{"type":"bar","labels":"shortname","series":["total"]}',
            'runmode' => 'daily',
            'runhour' => 4,
            'maxrows' => 100,
        ]);

        return chart_repository::get($id);
    }

    /**
     * Runs every queued ad hoc task and returns what they traced.
     *
     * @return string
     */
    protected function run_queue(): string {
        ob_start();
        $this->run_all_adhoc_tasks();

        return ob_get_clean();
    }

    /**
     * A queued scheduled run stores a result with its CSV and updates the chart.
     *
     * @covers \local_aicharts\task\run_chart::instance
     * @covers \local_aicharts\task\run_chart::execute
     * @covers \local_aicharts\task\run_chart::run
     */
    public function test_queue_then_run_stores_scheduled_result(): void {
        $chart = $this->create_chart();

        $this->assertNotFalse(manager::queue_adhoc_task(run_chart::instance($chart->id, 'scheduled'), true));
        $trace = $this->run_queue();

        $results = result_store::list_for_chart($chart->id);
        $this->assertCount(1, $results);
        $result = reset($results);
        $this->assertSame('ok', $result->status);
        $this->assertSame('scheduled', $result->runtrigger);
        $this->assertSame('0', $result->userid);
        $this->assertNotNull(result_store::get_file($result));
        $this->assertNotEmpty(result_store::load_rows($result));

        $chart = chart_repository::get($chart->id);
        $this->assertSame((int) $result->timecreated, (int) $chart->lastrun);
        $this->assertSame((int) $result->numrows, (int) $chart->lastrowcount);
        $this->assertStringContainsString('status ok', $trace);
    }

    /**
     * A manual run records the user who asked for it.
     *
     * @covers \local_aicharts\task\run_chart::instance
     * @covers \local_aicharts\task\run_chart::execute
     */
    public function test_manual_run_records_user(): void {
        $user = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart();

        manager::queue_adhoc_task(run_chart::instance($chart->id, 'manual', $user->id), true);
        $this->run_queue();

        $results = result_store::list_for_chart($chart->id);
        $result = reset($results);
        $this->assertSame('manual', $result->runtrigger);
        $this->assertSame((string) $user->id, $result->userid);
    }

    /**
     * The trigger keeps a scheduled run out of a pending manual one.
     *
     * @covers \local_aicharts\task\run_chart::instance
     */
    public function test_scheduled_task_is_queued_while_manual_is_pending(): void {
        $user = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart();

        manager::queue_adhoc_task(run_chart::instance($chart->id, 'manual', $user->id), true);
        $queued = manager::queue_adhoc_task(run_chart::instance($chart->id, 'scheduled'), true);

        $this->assertNotFalse($queued);
        $this->assertCount(2, manager::get_adhoc_tasks(run_chart::class));
    }

    /**
     * A query the database rejects is stored as a failure and still updates the chart.
     *
     * @covers \local_aicharts\task\run_chart::run
     */
    public function test_failure_stores_db_error_and_updates_lastrun(): void {
        $chart = $this->create_chart('SELECT nosuchcolumn FROM {role}');

        manager::queue_adhoc_task(run_chart::instance($chart->id, 'scheduled'), true);
        $this->run_queue();

        $results = result_store::list_for_chart($chart->id);
        $result = reset($results);
        $this->assertSame('db_error', $result->status);
        $this->assertNotEmpty($result->errormessage);
        $this->assertNull(result_store::get_file($result));

        $chart = chart_repository::get($chart->id);
        $this->assertSame((int) $result->timecreated, (int) $chart->lastrun);
        $this->assertNull($chart->lastrowcount);
    }

    /**
     * A run queued for a chart that has since been deleted completes without failing.
     *
     * @covers \local_aicharts\task\run_chart::execute
     */
    public function test_missing_chart_completes_quietly(): void {
        global $DB;

        $chart = $this->create_chart();
        manager::queue_adhoc_task(run_chart::instance($chart->id, 'scheduled'), true);
        chart_repository::delete($chart->id);

        $trace = $this->run_queue();

        $this->assertStringContainsString('no longer exists', $trace);
        $this->assertSame(0, $DB->count_records('local_aicharts_result'));
        $this->assertSame(0, $DB->count_records('task_adhoc'));
    }

    /**
     * A paused chart is not run.
     *
     * @covers \local_aicharts\task\run_chart::execute
     */
    public function test_paused_chart_is_skipped(): void {
        $chart = $this->create_chart();
        chart_repository::set_enabled($chart->id, false);

        manager::queue_adhoc_task(run_chart::instance($chart->id, 'scheduled'), true);
        $trace = $this->run_queue();

        $this->assertStringContainsString('is paused', $trace);
        $this->assertSame([], result_store::list_for_chart($chart->id));
    }

    /**
     * A run prunes the stored results beyond the retention setting.
     *
     * @covers \local_aicharts\task\run_chart::run
     */
    public function test_prunes_beyond_retention(): void {
        set_config('resultretention', 2, 'local_aicharts');
        $chart = $this->create_chart();
        result_store::store($chart, [], 'scheduled', 0, 'older failure');
        result_store::store($chart, [], 'scheduled', 0, 'old failure');

        manager::queue_adhoc_task(run_chart::instance($chart->id, 'scheduled'), true);
        $this->run_queue();

        $results = result_store::list_for_chart($chart->id);
        $this->assertCount(2, $results);
        $latest = reset($results);
        $this->assertSame('ok', $latest->status);
    }

    /**
     * A chart with two queries stores the merged rows: the label column plus one column per query.
     *
     * @covers \local_aicharts\task\run_chart::run
     * @covers \local_aicharts\local\query_runner::run_all
     */
    public function test_two_query_chart_stores_merged_csv(): void {
        $id = chart_repository::save((object) [
            'name' => 'Roles',
            'prompt' => 'roles',
            'queries' => [
                ['label' => 'One', 'sqltext' => 'SELECT shortname, 1 AS total FROM {role} ORDER BY shortname', 'params' => '{}'],
                [
                    'label' => 'Two',
                    'sqltext' => 'SELECT shortname, 2 AS total FROM {role} WHERE shortname = :name',
                    'params' => '{"name":"student"}',
                ],
            ],
            'chartjson' => '{"type":"bar","labelcolumn":"shortname","series":[{"column":"One"},{"column":"Two"}]}',
            'runmode' => 'daily',
            'runhour' => 4,
            'maxrows' => 100,
        ]);

        $result = run_chart::run(chart_repository::get($id), 'scheduled');
        $rows = result_store::load_rows($result);

        $this->assertSame('ok', $result->status);
        $this->assertSame(['shortname', 'One', 'Two'], array_keys(reset($rows)));
        $byname = array_column($rows, null, 'shortname');
        $this->assertSame('2', $byname['student']['Two']);
        $this->assertSame('', $byname['manager']['Two']);
        $this->assertSame('1', $byname['manager']['One']);
    }

    /**
     * Saves a daily trend chart with the given queries.
     *
     * @param array $queries Query rows with label, sqltext and params.
     * @return stdClass The saved chart.
     */
    protected function create_trend_chart(array $queries): stdClass {
        $id = chart_repository::save((object) [
            'name' => 'Trend',
            'prompt' => 'trend',
            'queries' => $queries,
            'chartjson' => '{"type":"line","labelcolumn":"runtime","series":[{"column":"Roles"}]}',
            'kind' => 'trend',
            'runmode' => 'daily',
            'runhour' => 4,
            'maxrows' => 100,
        ]);
        return chart_repository::get($id);
    }

    /**
     * Every run of a trend chart adds one point per series and stores every point so far.
     *
     * @covers \local_aicharts\task\run_chart::execute
     * @covers \local_aicharts\task\run_chart::run
     * @covers \local_aicharts\local\point_store::append
     * @covers \local_aicharts\local\point_store::link_result
     */
    public function test_trend_run_appends_point_and_stores_accumulated_rows(): void {
        global $DB;

        $this->setTimezone('UTC');
        $user = $this->getDataGenerator()->create_user();
        $chart = $this->create_trend_chart([
            ['label' => 'Roles', 'sqltext' => 'SELECT COUNT(*) AS total FROM {role}', 'params' => '{}'],
            ['label' => 'Users', 'sqltext' => 'SELECT COUNT(*) AS total FROM {user}', 'params' => '{}'],
        ]);

        manager::queue_adhoc_task(run_chart::instance($chart->id, 'scheduled'), true);
        $this->run_queue();
        $DB->set_field('local_aicharts_point', 'timepoint', 1700000000, ['chartid' => $chart->id]);
        $second = run_chart::run($chart, 'manual', $user->id);

        $points = $DB->get_records('local_aicharts_point', ['chartid' => $chart->id], 'timepoint, id');
        $this->assertCount(4, $points);
        $this->assertSame([(string) $second->id, (string) $second->id], array_slice(array_column($points, 'resultid'), 2));

        $rows = result_store::load_rows($second);
        $this->assertSame('ok', $second->status);
        $this->assertSame(2, (int) $second->numrows);
        $this->assertSame(['runtime', 'Roles', 'Users'], array_keys($rows[0]));
        $this->assertSame('14/11/23, 22:13', $rows[0]['runtime']);
        $this->assertSame($DB->count_records('role'), (int) $rows[1]['Roles']);
        $this->assertSame($DB->count_records('user'), (int) $rows[1]['Users']);
        $this->assertSame(userdate($second->timecreated, get_string('strftimedatetimeshort')), $rows[1]['runtime']);
    }

    /**
     * A failing series stores a failed result and adds no point.
     *
     * @covers \local_aicharts\task\run_chart::run
     */
    public function test_trend_failed_series_adds_no_point(): void {
        global $DB;

        $chart = $this->create_trend_chart([
            ['label' => 'Roles', 'sqltext' => 'SELECT COUNT(*) AS total FROM {role}', 'params' => '{}'],
            ['label' => 'Many', 'sqltext' => 'SELECT id FROM {role}', 'params' => '{}'],
        ]);

        $result = run_chart::run($chart, 'scheduled');

        $this->assertSame('db_error', $result->status);
        $this->assertStringStartsWith("Series 'Many' returned", $result->errormessage);
        $this->assertSame(0, $DB->count_records('local_aicharts_point', ['chartid' => $chart->id]));
    }
}
