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
 * Tests for the chart a finished wizard writes.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\local\chart_saver
 */
final class chart_saver_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('maxrowsdefault', 100, 'local_aicharts');
    }

    /**
     * The points of a series that no longer belongs to the chart are dropped on save.
     */
    public function test_removed_series_drops_its_points(): void {
        $state = wizard_state::open(wizard_state::start(null));
        $state->data->kind = 'trend';
        $state->data->name = 'Two series';
        $state->data->chartjson = '{"type":"line","labelcolumn":"runtime","series":[{"column":"Kept","label":"Kept"}]}';
        $state->data->runmode = 'daily';
        $state->data->queries = [
            wizard_state::query('Kept', '', 'SELECT COUNT(id) AS total FROM {course}', '{}'),
            wizard_state::query('Gone', '', 'SELECT COUNT(id) AS total FROM {user}', '{}'),
        ];
        $id = chart_saver::save($state);

        $chart = chart_repository::get($id);
        point_store::append($chart, ['Kept' => 3.0, 'Gone' => 7.0], time(), null);
        $this->assertSame(1, point_store::timepoints($id));
        $this->assertSame(['runtime', 'Kept', 'Gone'], array_keys(point_store::rows($id)[0]));

        $state->data->id = $id;
        $state->remove(1);
        $this->assertSame($id, chart_saver::save($state));

        $rows = point_store::rows($id);
        $this->assertCount(1, $rows);
        $this->assertSame(['runtime', 'Kept'], array_keys($rows[0]));
        $this->assertCount(1, chart_repository::get($id)->queries);
    }
}
