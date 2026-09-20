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

namespace local_aicharts\external;

use core\task\manager;
use stdClass;

/**
 * Tests for the external function that queues a manual run.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class run_chart_now_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->delete_records('local_aicharts_chart');
    }

    /**
     * Saves one chart.
     *
     * @param string $runmode Run mode of the chart.
     * @return stdClass The saved chart.
     */
    protected function create_chart(string $runmode = 'daily'): stdClass {
        $id = \local_aicharts\local\chart_repository::save((object) [
            'name' => 'Roles',
            'prompt' => 'roles',
            'sqltext' => 'SELECT shortname, 1 AS total FROM {role}',
            'params' => '{}',
            'chartjson' => '{"type":"bar","labels":"shortname","series":["total"]}',
            'runmode' => $runmode,
            'runhour' => 0,
            'maxrows' => 100,
        ]);

        return \local_aicharts\local\chart_repository::get($id);
    }

    /**
     * The first call queues a manual ad hoc task for the chart.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_queues_manual_task(): void {
        $chart = $this->create_chart();

        $result = run_chart_now::execute((int) $chart->id);

        $this->assertTrue($result['queued']);
        $tasks = manager::get_adhoc_tasks('\\local_aicharts\\task\\run_chart');
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertSame('manual', $task->get_custom_data()->trigger);
        $this->assertSame((int) $chart->id, (int) $task->get_custom_data()->chartid);
    }

    /**
     * A second call while the first task waits reports that nothing new was queued.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_second_call_reports_already_queued(): void {
        $chart = $this->create_chart();

        run_chart_now::execute((int) $chart->id);
        $result = run_chart_now::execute((int) $chart->id);

        $this->assertFalse($result['queued']);
        $this->assertCount(1, manager::get_adhoc_tasks('\\local_aicharts\\task\\run_chart'));
    }

    /**
     * A user without the manage capability may not queue a run.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_requires_manage(): void {
        $chart = $this->create_chart();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        run_chart_now::execute((int) $chart->id);
    }

    /**
     * A live chart has nothing to queue.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_live_chart_is_rejected(): void {
        $chart = $this->create_chart('live');

        $this->expectException(\moodle_exception::class);
        run_chart_now::execute((int) $chart->id);
    }

    /**
     * The queued run is really the one the ad hoc task runner picks up.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_queued_task_runs_the_chart(): void {
        global $DB;

        set_config('allowedtables', "role\n", 'local_aicharts');
        set_config('maxrowsmax', 500, 'local_aicharts');
        $chart = $this->create_chart();

        run_chart_now::execute((int) $chart->id);
        $tasks = manager::get_adhoc_tasks('\\local_aicharts\\task\\run_chart');
        $task = reset($tasks);
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertTrue($DB->record_exists('local_aicharts_result', [
            'chartid' => $chart->id,
            'runtrigger' => 'manual',
        ]));
    }
}
