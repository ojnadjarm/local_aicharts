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

use local_aicharts\local\chart_repository;
use stdClass;

/**
 * Tests for the external function that pauses and resumes a chart.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_chart_enabled_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $DB->delete_records('local_aicharts_chart');
    }

    /**
     * Saves one chart due right now.
     *
     * @param string $runmode The run mode of the chart.
     * @return stdClass The saved chart.
     */
    protected function create_chart(string $runmode = 'daily'): stdClass {
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

        return chart_repository::get($id);
    }

    /**
     * A paused chart is no longer picked up by the scheduler.
     *
     * @covers \local_aicharts\external\set_chart_enabled::execute
     */
    public function test_pause_hides_the_chart_from_the_scheduler(): void {
        $chart = $this->create_chart();
        $now = mktime(1, 0, 0, 6, 1, 2026);
        $this->assertArrayHasKey($chart->id, chart_repository::list_due($now));

        $returned = set_chart_enabled::execute((int) $chart->id, false);

        $this->assertFalse($returned['enabled']);
        $this->assertEquals(0, chart_repository::get((int) $chart->id)->enabled);
        $this->assertArrayNotHasKey($chart->id, chart_repository::list_due($now));
    }

    /**
     * Resuming puts the chart back on its schedule.
     *
     * @covers \local_aicharts\external\set_chart_enabled::execute
     */
    public function test_resume_puts_the_chart_back_on_its_schedule(): void {
        $chart = $this->create_chart();
        $now = mktime(1, 0, 0, 6, 1, 2026);
        set_chart_enabled::execute((int) $chart->id, false);

        $returned = set_chart_enabled::execute((int) $chart->id, true);

        $this->assertTrue($returned['enabled']);
        $this->assertEquals(1, chart_repository::get((int) $chart->id)->enabled);
        $this->assertArrayHasKey($chart->id, chart_repository::list_due($now));
    }

    /**
     * A user without the manage capability may not pause a chart.
     *
     * @covers \local_aicharts\external\set_chart_enabled::execute
     */
    public function test_requires_manage(): void {
        $chart = $this->create_chart();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        set_chart_enabled::execute((int) $chart->id, false);
    }

    /**
     * A live chart can be paused and resumed too.
     *
     * @covers \local_aicharts\external\set_chart_enabled::execute
     */
    public function test_live_chart_can_be_paused(): void {
        $chart = $this->create_chart('live');

        $returned = set_chart_enabled::execute((int) $chart->id, false);

        $this->assertFalse($returned['enabled']);
        $this->assertEquals(0, chart_repository::get((int) $chart->id)->enabled);

        set_chart_enabled::execute((int) $chart->id, true);
        $this->assertEquals(1, chart_repository::get((int) $chart->id)->enabled);
    }

    /**
     * An unknown chart id is reported instead of changing nothing quietly.
     *
     * @covers \local_aicharts\external\set_chart_enabled::execute
     */
    public function test_unknown_chart_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        set_chart_enabled::execute(-1, false);
    }
}
