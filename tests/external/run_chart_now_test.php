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
 * Tests for the external function that runs a chart at once.
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
        set_config('maxrowsmax', 500, 'local_aicharts');
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

        return \local_aicharts\local\chart_repository::get($id);
    }

    /**
     * A live chart that has never run stores its result before the call returns, without cron.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_run_is_stored_at_once(): void {
        global $DB, $USER;

        $chart = $this->create_chart('live');

        $result = run_chart_now::execute((int) $chart->id);

        $this->assertSame('ok', $result['status']);
        $this->assertGreaterThan(0, $result['runid']);
        $stored = $DB->get_record('local_aicharts_result', ['id' => $result['runid']]);
        $this->assertSame('manual', $stored->runtrigger);
        $this->assertSame((int) $USER->id, (int) $stored->userid);
        $this->assertEquals(0, $stored->emailed);
        $this->assertEmpty(manager::get_adhoc_tasks('\\local_aicharts\\task\\run_chart'));
        $this->assertEquals(
            $stored->timecreated,
            $DB->get_field('local_aicharts_chart', 'lastrun', ['id' => $chart->id])
        );
    }

    /**
     * Pressing the button twice leaves two runs in the history, newest first.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_two_runs_are_two_records(): void {
        $chart = $this->create_chart('live');

        $first = run_chart_now::execute((int) $chart->id);
        $second = run_chart_now::execute((int) $chart->id);

        $results = \local_aicharts\local\result_store::list_for_chart((int) $chart->id);
        $this->assertCount(2, $results);
        $this->assertSame((int) $second['runid'], (int) reset($results)->id);
        $this->assertNotSame($first['runid'], $second['runid']);
        $this->assertEmpty(manager::get_adhoc_tasks('\\local_aicharts\\task\\run_chart'));
    }

    /**
     * A scheduled chart runs at once as well and keeps its stored file.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_scheduled_chart_runs_at_once_with_its_file(): void {
        $chart = $this->create_chart();

        $result = run_chart_now::execute((int) $chart->id);

        $stored = \local_aicharts\local\result_store::get((int) $result['runid']);
        $this->assertSame('ok', $stored->status);
        $this->assertNotEmpty(\local_aicharts\local\result_store::load_rows($stored));
    }

    /**
     * A paused chart is reported as paused and stores nothing.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_paused_chart_is_not_run(): void {
        global $DB;

        $chart = $this->create_chart('live');
        \local_aicharts\local\chart_repository::set_enabled((int) $chart->id, false);

        $result = run_chart_now::execute((int) $chart->id);

        $this->assertSame('paused', $result['status']);
        $this->assertSame(0, $result['runid']);
        $this->assertFalse($DB->record_exists('local_aicharts_result', ['chartid' => $chart->id]));
    }

    /**
     * A user without the manage capability may not run a chart.
     *
     * @covers \local_aicharts\external\run_chart_now::execute
     */
    public function test_requires_manage(): void {
        $chart = $this->create_chart();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        run_chart_now::execute((int) $chart->id);
    }
}
