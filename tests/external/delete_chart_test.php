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
use local_aicharts\local\result_store;
use stdClass;

/**
 * Tests for the external function that deletes a chart.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class delete_chart_test extends \advanced_testcase {
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
     * @return stdClass The saved chart.
     */
    protected function create_chart(): stdClass {
        $id = chart_repository::save((object) [
            'name' => 'Roles',
            'prompt' => 'roles',
            'queries' => [[
                'label' => 'Roles',
                'sqltext' => 'SELECT shortname, 1 AS total FROM {role}',
                'params' => '{}',
            ]],
            'chartjson' => '{"type":"bar","labels":"shortname","series":["total"]}',
            'runmode' => 'daily',
            'runhour' => 0,
            'maxrows' => 100,
        ]);

        return chart_repository::get($id);
    }

    /**
     * Deleting a chart takes its stored results and their files with it.
     *
     * @covers \local_aicharts\external\delete_chart::execute
     * @covers \local_aicharts\local\chart_repository::delete
     */
    public function test_deletes_results_and_files(): void {
        global $DB;

        $chart = $this->create_chart();
        $result = result_store::store($chart, [['shortname' => 'student', 'total' => 1]], 'scheduled', 0);
        $file = result_store::get_file($result);
        $this->assertNotNull($file);
        $fileid = $file->get_id();

        $returned = delete_chart::execute((int) $chart->id);

        $this->assertTrue($returned['deleted']);
        $this->assertFalse($DB->record_exists('local_aicharts_chart', ['id' => $chart->id]));
        $this->assertFalse($DB->record_exists('local_aicharts_result', ['chartid' => $chart->id]));
        $this->assertFalse($DB->record_exists('files', ['id' => $fileid]));
    }

    /**
     * A user without the manage capability may not delete a chart.
     *
     * @covers \local_aicharts\external\delete_chart::execute
     */
    public function test_requires_manage(): void {
        $chart = $this->create_chart();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        delete_chart::execute((int) $chart->id);
    }

    /**
     * An unknown chart id is reported instead of deleting nothing quietly.
     *
     * @covers \local_aicharts\external\delete_chart::execute
     */
    public function test_unknown_chart_is_rejected(): void {
        $this->expectException(\moodle_exception::class);
        delete_chart::execute(-1);
    }
}
