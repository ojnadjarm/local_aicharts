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
 * Tests for the mapping between the chart JSON and the Chart step controls.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chart_builder_test extends \advanced_testcase {
    /**
     * Every key of a chart definition survives the trip to the controls and back, the shipped charts included.
     *
     * @covers \local_aicharts\local\chart_builder::to_controls
     * @covers \local_aicharts\local\chart_builder::from_controls
     */
    public function test_round_trip_of_every_spec_key(): void {
        $charts = [json_encode([
            'type' => 'bar',
            'title' => 'Enrolments per month',
            'labelcolumn' => 'month',
            'labelformat' => 'month',
            'series' => [['column' => 'thisyear', 'label' => 'This year'], ['column' => 'lastyear', 'label' => 'Last year']],
            'xlabel' => 'Month',
            'ylabel' => 'Enrolments',
            'horizontal' => true,
            'stacked' => true,
            'doughnut' => true,
            'smooth' => true,
        ])];
        foreach (default_charts::CHARTS as $definition) {
            $charts[] = json_encode($definition['chart']);
        }

        foreach ($charts as $chartjson) {
            $controls = chart_builder::to_controls($chartjson);
            $this->assertEquals(chart_spec::from_json($chartjson), chart_spec::from_json(chart_builder::from_controls($controls)));
        }

        $controls = chart_builder::to_controls($charts[0]);
        $this->assertSame(['thisyear' => 'This year', 'lastyear' => 'Last year'], $controls['series']);
        $this->assertTrue($controls['horizontal']);
        $this->assertSame('month', $controls['labelformat']);

        $table = chart_builder::from_controls(['type' => 'table', 'labelcolumn' => 'month', 'series' => ['thisyear' => '']]);
        $this->assertSame(
            ['type' => 'table', 'title' => '', 'labelcolumn' => '', 'labelformat' => 'text', 'series' => []],
            json_decode($table, true)
        );
    }

    /**
     * A single query starts as a bar chart of its numeric columns over the first column.
     *
     * @covers \local_aicharts\local\chart_builder::defaults
     */
    public function test_defaults_for_one_query(): void {
        $controls = chart_builder::defaults(['coursename', 'total', 'category'], 'oneshot', [
            'coursename' => 'Maths',
            'total' => '4',
            'category' => 'Science',
        ]);

        $this->assertSame('bar', $controls['type']);
        $this->assertSame('coursename', $controls['labelcolumn']);
        $this->assertSame('text', $controls['labelformat']);
        $this->assertSame(['total' => 'total'], $controls['series']);
        $this->assertFalse($controls['stacked']);

        $controls = chart_builder::defaults(['label', 'This year', 'Last year'], 'oneshot');
        $this->assertSame(['This year' => 'This year', 'Last year' => 'Last year'], $controls['series']);

        $spec = chart_spec::from_json(chart_builder::from_controls($controls));
        $this->assertSame('label', $spec->labelcolumn);
        $this->assertCount(2, $spec->series);
    }

    /**
     * A trend starts as a line over the run time with one series per query.
     *
     * @covers \local_aicharts\local\chart_builder::defaults
     */
    public function test_defaults_for_trend(): void {
        $controls = chart_builder::defaults(['runtime', 'Active', 'Suspended'], 'trend');

        $this->assertSame('line', $controls['type']);
        $this->assertSame('runtime', $controls['labelcolumn']);
        $this->assertSame('text', $controls['labelformat']);
        $this->assertSame(['Active' => 'Active', 'Suspended' => 'Suspended'], $controls['series']);
    }
}
