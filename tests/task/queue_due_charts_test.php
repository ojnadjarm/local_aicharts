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
 * Tests for the scheduled task that queues due charts.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class queue_due_charts_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->delete_records('local_aicharts_chart');
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('resultretention', 30, 'local_aicharts');
    }

    /**
     * Saves one chart.
     *
     * @param string $runmode Run mode of the chart.
     * @param int $lastrun Time of its last run.
     * @return stdClass The saved chart.
     */
    protected function create_chart(string $runmode = 'daily', int $lastrun = 0): stdClass {
        global $DB;

        $id = chart_repository::save((object) [
            'name' => 'Roles',
            'prompt' => 'roles',
            'queries' => [[
                'label' => 'Roles',
                'sqltext' => 'SELECT shortname, 1 AS total FROM {role}',
                'params' => '{}',
            ]],
            'chartjson' => '{"type":"bar","labels":"shortname","series":["total"]}',
            'runmode' => $runmode,
            'runhour' => 0,
            'maxrows' => 100,
        ]);
        if ($lastrun) {
            $DB->set_field('local_aicharts_chart', 'lastrun', $lastrun, ['id' => $id]);
        }

        return chart_repository::get($id);
    }

    /**
     * Runs the scheduled task and returns what it traced.
     *
     * @return string
     */
    protected function run_task(): string {
        ob_start();
        (new queue_due_charts())->execute();

        return ob_get_clean();
    }

    /**
     * A due chart gets one ad hoc task and is marked as handled.
     *
     * @covers \local_aicharts\task\queue_due_charts::execute
     */
    public function test_queues_one_adhoc_task_per_due_chart(): void {
        $chart = $this->create_chart();

        $trace = $this->run_task();

        $tasks = manager::get_adhoc_tasks(run_chart::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame((int) $chart->id, (int) $task->get_custom_data()->chartid);
        $this->assertSame('scheduled', $task->get_custom_data()->trigger);
        $this->assertStringContainsString('Queued 1 chart runs', $trace);
        $this->assertGreaterThan(0, (int) chart_repository::get($chart->id)->lastrun);
    }

    /**
     * A chart that already ran in the current period is left alone.
     *
     * @covers \local_aicharts\task\queue_due_charts::execute
     */
    public function test_chart_not_due_is_skipped(): void {
        $this->create_chart('daily', time());
        $this->create_chart('live');

        $this->run_task();

        $this->assertSame([], manager::get_adhoc_tasks(run_chart::class));
    }

    /**
     * A second pass in the same period queues nothing more.
     *
     * @covers \local_aicharts\task\queue_due_charts::execute
     */
    public function test_second_pass_queues_nothing(): void {
        $this->create_chart();

        $this->run_task();
        $this->run_task();

        $this->assertCount(1, manager::get_adhoc_tasks(run_chart::class));
    }

    /**
     * A paused chart is never queued.
     *
     * @covers \local_aicharts\task\queue_due_charts::execute
     */
    public function test_paused_chart_is_skipped(): void {
        $chart = $this->create_chart();
        chart_repository::set_enabled($chart->id, false);

        $this->run_task();

        $this->assertSame([], manager::get_adhoc_tasks(run_chart::class));
        $this->assertSame(0, (int) chart_repository::get($chart->id)->lastrun);
    }

    /**
     * The scheduler queues only; the query runs in the ad hoc task.
     *
     * @covers \local_aicharts\task\queue_due_charts::execute
     */
    public function test_queue_then_run_end_to_end(): void {
        $chart = $this->create_chart();

        $this->run_task();
        $this->assertSame([], result_store::list_for_chart($chart->id));

        ob_start();
        $this->run_all_adhoc_tasks();
        ob_end_clean();

        $results = result_store::list_for_chart($chart->id);
        $this->assertCount(1, $results);
        $result = reset($results);
        $this->assertSame('ok', $result->status);
        $this->assertSame('scheduled', $result->runtrigger);
    }
}
