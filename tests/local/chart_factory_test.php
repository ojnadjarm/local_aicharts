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
 * Tests for the chart spec and the chart factory.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chart_factory_test extends \advanced_testcase {
    /**
     * A bar spec becomes a bar chart with labels, series and axis labels.
     *
     * @covers \local_aicharts\local\chart_factory::create
     */
    public function test_bar_from_rows(): void {
        $spec = chart_spec::from_array([
            'type' => 'bar',
            'title' => 'Enrolments per course',
            'labelcolumn' => 'coursename',
            'series' => [['column' => 'total', 'label' => 'Enrolments']],
            'xlabel' => 'Course',
            'ylabel' => 'Enrolments',
            'horizontal' => true,
            'stacked' => true,
        ]);
        $rows = [
            ['coursename' => 'Maths', 'total' => 4],
            ['coursename' => 'History', 'total' => 7],
        ];

        $chart = chart_factory::create($spec, $rows);

        $this->assertInstanceOf(\core\chart_bar::class, $chart);
        $this->assertSame('Enrolments per course', $chart->get_title());
        $this->assertSame(['Maths', 'History'], $chart->get_labels());
        $this->assertTrue($chart->get_horizontal());
        $this->assertTrue($chart->get_stacked());
        $this->assertSame('Course', $chart->get_xaxis()->get_label());
        $this->assertSame('Enrolments', $chart->get_yaxis()->get_label());
        $series = $chart->get_series();
        $this->assertCount(1, $series);
        $this->assertSame('Enrolments', $series[0]->get_label());
        $this->assertSame([4.0, 7.0], $series[0]->get_values());
    }

    /**
     * A pie spec becomes a doughnut chart with a single series.
     *
     * @covers \local_aicharts\local\chart_factory::create
     */
    public function test_pie_single_series(): void {
        $spec = chart_spec::from_array([
            'type' => 'pie',
            'labelcolumn' => 'method',
            'series' => [['column' => 'total']],
            'doughnut' => true,
        ]);
        $rows = [
            (object) ['method' => 'manual', 'total' => 3],
            (object) ['method' => 'self', 'total' => 9],
        ];

        $chart = chart_factory::create($spec, $rows);

        $this->assertInstanceOf(\core\chart_pie::class, $chart);
        $this->assertTrue($chart->get_doughnut());
        $this->assertSame(['manual', 'self'], $chart->get_labels());
        $series = $chart->get_series();
        $this->assertSame('total', $series[0]->get_label());
        $this->assertSame([3.0, 9.0], $series[0]->get_values());
    }

    /**
     * Timestamp labels are formatted according to the label format.
     *
     * @covers \local_aicharts\local\chart_factory::create
     */
    public function test_date_labels_formatted(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');
        $rows = [['day' => '1700000000', 'total' => '1']];

        $date = chart_factory::create(chart_spec::from_array([
            'type' => 'line',
            'labelcolumn' => 'day',
            'labelformat' => 'date',
            'series' => [['column' => 'total']],
        ]), $rows);
        $month = chart_factory::create(chart_spec::from_array([
            'type' => 'line',
            'labelcolumn' => 'day',
            'labelformat' => 'month',
            'series' => [['column' => 'total']],
        ]), $rows);

        $this->assertSame(['Tuesday, 14 November 2023'], $date->get_labels());
        $this->assertSame(['November 2023'], $month->get_labels());
    }

    /**
     * String values coming from a CSV are cast to floats, empty ones to null.
     *
     * @covers \local_aicharts\local\chart_factory::create
     */
    public function test_string_values_are_cast(): void {
        $spec = chart_spec::from_array([
            'type' => 'line',
            'labelcolumn' => 'day',
            'series' => [['column' => 'total']],
            'smooth' => true,
        ]);
        $rows = [
            ['day' => 'Mon', 'total' => '12'],
            ['day' => 'Tue', 'total' => '3.5'],
            ['day' => 'Wed', 'total' => ''],
        ];

        $chart = chart_factory::create($spec, $rows);

        $this->assertTrue($chart->get_smooth());
        $this->assertSame([12.0, 3.5, null], $chart->get_series()[0]->get_values());
    }

    /**
     * A query that matched nothing has no chart: core rejects a series with no values.
     *
     * @covers \local_aicharts\local\chart_factory::create
     */
    public function test_empty_result_throws(): void {
        $spec = chart_spec::from_array([
            'type' => 'bar',
            'labelcolumn' => 'day',
            'series' => [['column' => 'total']],
        ]);

        $this->assert_throws(fn() => chart_factory::create($spec, []), 'No rows returned');
    }

    /**
     * An unusable definition is rejected with a message naming the problem.
     *
     * @covers \local_aicharts\local\chart_spec::from_array
     * @covers \local_aicharts\local\chart_spec::from_json
     * @covers \local_aicharts\local\chart_factory::create
     */
    public function test_invalid_spec_throws(): void {
        $this->assert_throws(
            fn() => chart_spec::from_array(['type' => 'radar', 'labelcolumn' => 'a', 'series' => []]),
            'radar'
        );
        $this->assert_throws(
            fn() => chart_spec::from_array(['type' => 'bar', 'labelcolumn' => '', 'series' => []]),
            'label column'
        );
        $this->assert_throws(fn() => chart_spec::from_json('not json'), 'valid JSON');
        $this->assert_throws(
            fn() => chart_factory::create(
                chart_spec::from_array([
                    'type' => 'bar',
                    'labelcolumn' => 'day',
                    'series' => [['column' => 'missing']],
                ]),
                [['day' => 'Mon']]
            ),
            'missing'
        );
    }

    /**
     * A table spec parses with no label column and no series, and has no chart.
     *
     * @covers \local_aicharts\local\chart_spec::from_json
     * @covers \local_aicharts\local\chart_spec::is_table
     * @covers \local_aicharts\local\chart_factory::create
     */
    public function test_table_spec_parses(): void {
        $spec = chart_spec::from_json(json_encode([
            'status' => 'chart',
            'chart' => [
                'type' => 'table',
                'title' => 'Users who never logged in',
                'labelcolumn' => '',
                'series' => [],
            ],
        ]));

        $this->assertTrue($spec->is_table());
        $this->assertSame('Users who never logged in', $spec->title);
        $this->assertSame([], $spec->series);

        $this->expectException(\coding_exception::class);
        chart_factory::create($spec, [['fullname' => 'Ann']]);
    }

    /**
     * Assert that a callable throws with a message containing the given text.
     *
     * @param callable $callable Code expected to throw.
     * @param string $needle Text the message must contain.
     */
    protected function assert_throws(callable $callable, string $needle): void {
        try {
            $callable();
            $this->fail('Expected an exception mentioning "' . $needle . '".');
        } catch (\moodle_exception $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
