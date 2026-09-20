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
 * Tests for the point store.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\local\point_store
 */
final class point_store_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Saves a trend chart with the given retention.
     *
     * @param int $pointsretention Points kept, 0 for the site setting.
     * @return stdClass
     */
    protected function create_chart(int $pointsretention = 0): stdClass {
        $id = chart_repository::save((object) [
            'name' => 'Active users',
            'prompt' => 'active users',
            'queries' => [['label' => 'Active', 'sqltext' => 'SELECT COUNT(*) AS total FROM {user}', 'params' => '{}']],
            'chartjson' => '{"type":"line","labelcolumn":"runtime","series":[{"column":"Active"}]}',
            'kind' => 'trend',
            'pointsretention' => $pointsretention,
            'runmode' => 'daily',
        ]);
        return chart_repository::get($id);
    }

    /**
     * Appended points come back as one row per run time with a float per series.
     *
     * @covers \local_aicharts\local\point_store::append
     * @covers \local_aicharts\local\point_store::rows
     */
    public function test_append_and_rows_one_row_per_time(): void {
        $chart = $this->create_chart();
        point_store::append($chart, ['Active' => 12, 'New' => 3.5], 1000, null);
        point_store::append($chart, ['Active' => 14, 'New' => null], 2000, null);

        $this->assertSame([
            ['runtime' => 1000, 'Active' => 12.0, 'New' => 3.5],
            ['runtime' => 2000, 'Active' => 14.0, 'New' => null],
        ], point_store::rows($chart->id));
    }

    /**
     * Two runs within the same second leave one point per series.
     *
     * @covers \local_aicharts\local\point_store::append
     */
    public function test_append_twice_at_the_same_time_keeps_one_point(): void {
        global $DB;

        $chart = $this->create_chart();
        point_store::append($chart, ['Active' => 12], 1000, null);
        point_store::append($chart, ['Active' => 14], 1000, null);

        $this->assertSame(1, $DB->count_records('local_aicharts_point', ['chartid' => $chart->id]));
        $this->assertSame([['runtime' => 1000, 'Active' => 14.0]], point_store::rows($chart->id));
    }

    /**
     * A series missing from one run reads null there, and the run time formats as a date.
     *
     * @covers \local_aicharts\local\point_store::rows
     * @covers \local_aicharts\local\point_store::format_rows
     */
    public function test_rows_fill_missing_series_with_null(): void {
        $this->setTimezone('UTC');
        $chart = $this->create_chart();
        point_store::append($chart, ['Active' => 1], 1700000000, null);
        point_store::append($chart, ['Active' => 2, 'New' => 5], 1700086400, null);

        $rows = point_store::format_rows(point_store::rows($chart->id));

        $this->assertSame(['14/11/23, 22:13', 1.0, null], array_values($rows[0]));
        $this->assertSame(['15/11/23, 22:13', 2.0, 5.0], array_values($rows[1]));
    }

    /**
     * Pruning keeps the newest timepoints and never fewer than the floor.
     *
     * @covers \local_aicharts\local\point_store::prune
     * @covers \local_aicharts\local\point_store::retention
     */
    public function test_prune_keeps_newest_timepoints_at_least_the_floor(): void {
        global $DB;

        set_config('pointsretention', 5, 'local_aicharts');
        $chart = $this->create_chart(2);
        for ($i = 1; $i <= 35; $i++) {
            point_store::append($chart, ['Active' => $i], $i * 100, null);
        }

        $this->assertSame(30, point_store::retention($chart));
        point_store::prune($chart->id, point_store::retention($chart));

        $rows = point_store::rows($chart->id);
        $this->assertCount(30, $rows);
        $this->assertSame(600, $rows[0]['runtime']);
        $this->assertSame(3500, $rows[29]['runtime']);
        $this->assertSame(30, $DB->count_records('local_aicharts_point', ['chartid' => $chart->id]));
    }

    /**
     * The chart setting wins over the site setting, and the site default applies when both are unset.
     *
     * @covers \local_aicharts\local\point_store::retention
     */
    public function test_retention_prefers_chart_then_site_setting(): void {
        set_config('pointsretention', 40, 'local_aicharts');
        $this->assertSame(40, point_store::retention($this->create_chart()));
        $this->assertSame(50, point_store::retention($this->create_chart(50)));

        unset_config('pointsretention', 'local_aicharts');
        $this->assertSame(365, point_store::retention($this->create_chart()));
    }

    /**
     * Points of removed series go, and unlinking a run clears its id from the points it produced.
     *
     * @covers \local_aicharts\local\point_store::delete_series
     * @covers \local_aicharts\local\point_store::link_result
     * @covers \local_aicharts\local\point_store::unlink_result
     */
    public function test_delete_series_and_result_links(): void {
        global $DB;

        $chart = $this->create_chart();
        point_store::append($chart, ['Active' => 1, 'Old' => 2], 1000, null);
        point_store::link_result($chart->id, 1000, 77);
        $this->assertSame(2, $DB->count_records('local_aicharts_point', ['resultid' => 77]));

        point_store::delete_series($chart->id, ['Old']);
        $this->assertSame([['runtime' => 1000, 'Active' => 1.0]], point_store::rows($chart->id));

        point_store::unlink_result(77);
        $this->assertSame(0, $DB->count_records('local_aicharts_point', ['resultid' => 77]));
        $this->assertSame(1, $DB->count_records('local_aicharts_point', ['chartid' => $chart->id]));
    }

    /**
     * The timepoints of several charts are counted in one query, zero for a chart with none.
     *
     * @covers \local_aicharts\local\point_store::timepoints_for
     */
    public function test_timepoints_for_counts_each_chart(): void {
        $this->resetAfterTest();

        $first = $this->create_chart();
        $second = $this->create_chart();
        point_store::append($first, ['Active' => 1, 'New' => 2], 100, null);
        point_store::append($first, ['Active' => 3, 'New' => 4], 200, null);
        point_store::append($second, ['Active' => 5], 100, null);

        $this->assertSame(
            [$first->id => 2, $second->id => 1, 0 => 0],
            point_store::timepoints_for([$first->id, $second->id, 0])
        );
    }
}
