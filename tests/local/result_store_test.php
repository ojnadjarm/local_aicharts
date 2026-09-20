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

use stdClass;

/**
 * Tests for the result store.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_store_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Saves a chart to attach results to.
     *
     * @return stdClass
     */
    protected function create_chart(): stdClass {
        $id = chart_repository::save((object) [
            'name' => 'Users per role',
            'prompt' => 'users per role',
            'queries' => [[
                'label' => 'Users per role',
                'sqltext' => 'SELECT shortname, 1 AS total FROM {role}',
                'params' => '{}',
            ]],
            'chartjson' => '{"type":"bar","labels":"shortname","series":["total"]}',
        ]);

        return chart_repository::get($id);
    }

    /**
     * A successful run becomes a row and a CSV file.
     *
     * @covers \local_aicharts\local\result_store::store
     * @covers \local_aicharts\local\result_store::get
     * @covers \local_aicharts\local\result_store::get_file
     * @covers \local_aicharts\local\result_store::download_url
     */
    public function test_store_writes_row_and_csv(): void {
        $chart = $this->create_chart();

        $result = result_store::store($chart, [
            ['role' => 'student', 'total' => 12],
            ['role' => 'teacher', 'total' => 3],
        ], 'scheduled', 0, null, 58);

        $stored = result_store::get($result->id);
        $this->assertSame('ok', $stored->status);
        $this->assertEquals(2, $stored->numrows);
        $this->assertEquals(58, $stored->durationms);
        $this->assertSame('scheduled', $stored->runtrigger);
        $this->assertEquals(0, $stored->userid);
        $this->assertEquals(0, $stored->emailed);
        $this->assertNull($stored->errormessage);

        $file = result_store::get_file($stored);
        $this->assertNotNull($file);
        $this->assertSame('chart-' . $chart->id . '-' . date('Ymd-His', $stored->timecreated) . '.csv', $file->get_filename());
        $this->assertSame("role,total\nstudent,12\nteacher,3\n", $file->get_content());
        $url = result_store::download_url($stored)->out(false);
        $this->assertStringContainsString('/local_aicharts/result/' . $stored->id . '/', $url);
    }

    /**
     * Rows survive the round trip through the CSV file, newlines included.
     *
     * @covers \local_aicharts\local\result_store::load_rows
     */
    public function test_load_rows_roundtrip_with_multiline_value(): void {
        $chart = $this->create_chart();
        $rows = [
            ['name' => "First line\nSecond line", 'total' => '4'],
            ['name' => 'Comma, quote "here"', 'total' => '7'],
        ];

        $result = result_store::store($chart, $rows, 'manual', 2);

        $this->assertSame($rows, result_store::load_rows($result));
    }

    /**
     * Retention deletes the oldest runs with their files.
     *
     * @covers \local_aicharts\local\result_store::prune
     * @covers \local_aicharts\local\result_store::list_for_chart
     * @covers \local_aicharts\local\result_store::get_latest_ok
     */
    public function test_prune_keeps_newest(): void {
        global $DB;

        $chart = $this->create_chart();
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $result = result_store::store($chart, [['total' => $i]], 'scheduled', 0);
            $DB->set_field('local_aicharts_result', 'timecreated', 1000 + $i, ['id' => $result->id]);
            $ids[] = $result->id;
        }

        result_store::prune($chart->id, 2);

        $kept = array_keys(result_store::list_for_chart($chart->id));
        $this->assertSame([$ids[3], $ids[2]], $kept);
        $this->assertNull(result_store::get_file((object) ['id' => $ids[0]]));
        $this->assertEquals($ids[3], result_store::get_latest_ok($chart->id)->id);
    }

    /**
     * A failed run is recorded without a file.
     *
     * @covers \local_aicharts\local\result_store::store
     */
    public function test_failed_result_has_no_file(): void {
        $chart = $this->create_chart();

        $result = result_store::store($chart, [], 'scheduled', 0, 'Relation does not exist');

        $this->assertSame('db_error', $result->status);
        $this->assertNull($result->numrows);
        $this->assertSame('Relation does not exist', $result->errormessage);
        $this->assertNull(result_store::get_file($result));
        $this->assertNull(result_store::download_url($result));
        $this->assertSame([], result_store::load_rows($result));
    }

    /**
     * Deleting a chart's results removes their files too.
     *
     * @covers \local_aicharts\local\result_store::delete_for_chart
     */
    public function test_delete_for_chart(): void {
        $chart = $this->create_chart();
        $result = result_store::store($chart, [['total' => 1]], 'save', 2);

        result_store::delete_for_chart($chart->id);

        $this->assertSame([], result_store::list_for_chart($chart->id));
        $this->assertNull(result_store::get_file($result));
    }

    /**
     * The file serving callback refuses a user holding neither capability.
     *
     * @covers ::local_aicharts_pluginfile
     */
    public function test_pluginfile_requires_capability(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/aicharts/lib.php');

        $chart = $this->create_chart();
        $result = result_store::store($chart, [['total' => 1]], 'manual', 2);
        $file = result_store::get_file($result);
        $context = \context_system::instance();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        local_aicharts_pluginfile(
            null,
            null,
            $context,
            result_store::FILEAREA,
            [$result->id, $file->get_filename()],
            true
        );
    }

    /**
     * A chart with recipients also stores the PNG of its result.
     *
     * @covers \local_aicharts\local\result_store::get_image
     */
    public function test_store_writes_image_for_a_chart_with_recipients(): void {
        $chart = $this->create_chart();
        $chart->chartjson = '{"type":"bar","labelcolumn":"role","series":[{"column":"total","label":"Total"}]}';
        $rows = [['role' => 'student', 'total' => 12], ['role' => 'teacher', 'total' => 3]];

        $withoutrecipients = result_store::store($chart, $rows, 'scheduled', 0);
        $this->assertNull(result_store::get_image($withoutrecipients));

        $chart->emailto = '2,3';
        $result = result_store::store($chart, $rows, 'scheduled', 0);
        $image = result_store::get_image($result);

        $this->assertNotNull($image);
        $this->assertStringEndsWith('.png', $image->get_filename());
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $image->get_content());
    }
}
