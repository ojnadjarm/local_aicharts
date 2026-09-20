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
 * Tests for the shipped default charts.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class default_charts_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $DB->delete_records('local_aicharts_chart');
        set_config('maxrowsdefault', 500, 'local_aicharts');
        set_config('maxrowsmax', 5000, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
    }

    /**
     * Seeding twice leaves one row per shipped chart.
     *
     * @covers \local_aicharts\local\default_charts::seed
     */
    public function test_seed_is_idempotent(): void {
        global $DB;

        $expected = count(default_charts::CHARTS);

        $this->assertSame($expected, default_charts::seed());
        $this->assertSame($expected, $DB->count_records('local_aicharts_chart'));

        $this->assertSame(0, default_charts::seed());
        $this->assertSame($expected, $DB->count_records('local_aicharts_chart'));

        $charts = $DB->get_records('local_aicharts_chart');
        $tables = array_filter($charts, fn($chart) => chart_spec::from_json($chart->chartjson)->is_table());
        $this->assertCount(1, $tables);
        $this->assertSame(500, (int) reset($charts)->maxrows);
        $this->assertSame(1, (int) reset($charts)->isdefault);
    }

    /**
     * Each seeded chart gets one query named after the chart.
     *
     * @covers \local_aicharts\local\default_charts::seed
     */
    public function test_seed_creates_one_query_per_chart(): void {
        global $DB;

        default_charts::seed();

        foreach ($DB->get_records('local_aicharts_chart') as $chart) {
            $queries = $DB->get_records('local_aicharts_query', ['chartid' => $chart->id]);
            $this->assertCount(1, $queries);
            $query = reset($queries);
            $definition = default_charts::CHARTS[$chart->idnumber];
            $this->assertSame($chart->name, $query->label);
            $this->assertSame($definition['sql'], $query->sqltext);
            $this->assertSame(array_keys($definition['params']), array_keys(json_decode($query->params, true)));
            $this->assertSame($definition['kind'] ?? 'oneshot', $chart->kind);
            $this->assertSame($definition['runmode'] ?? 'live', $chart->runmode);
        }
    }

    /**
     * The trend default runs daily and its since parameter is set from the seed time.
     *
     * @covers \local_aicharts\local\default_charts::seed
     * @covers \local_aicharts\local\default_charts::params
     */
    public function test_trend_default_since_parameter(): void {
        global $DB;

        default_charts::seed();
        $chart = $DB->get_record('local_aicharts_chart', ['idnumber' => 'activeuserstrend']);
        $query = $DB->get_record('local_aicharts_query', ['chartid' => $chart->id]);
        $params = json_decode($query->params, true);

        $this->assertSame('trend', $chart->kind);
        $this->assertSame('daily', $chart->runmode);
        $this->assertEqualsWithDelta(time() - 30 * DAYSECS, $params['since'], 5);
    }

    /**
     * Every shipped query passes the validator and runs on this database.
     *
     * @covers \local_aicharts\local\default_charts::seed
     */
    public function test_default_sql_passes_validator_and_runs(): void {
        foreach (default_charts::CHARTS as $idnumber => $definition) {
            sql_validator::validate($definition['sql'], $definition['params']);
            $spec = chart_spec::from_json(json_encode($definition['chart']));

            $result = query_runner::run($definition['sql'], $definition['params'], 500);
            $this->assertSame('ok', $result->status, $idnumber . ': ' . $result->errormessage);

            $columns = $result->rows ? count((array) $result->rows[0]) : 0;
            if ($columns) {
                sql_validator::check_column_count($columns, $spec->is_table());
            }
        }
    }

    /**
     * Every shipped chart builds a chart core accepts once the site holds the data it counts.
     *
     * @covers \local_aicharts\local\default_charts::seed
     */
    public function test_default_charts_build_from_site_data(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $user = $generator->create_user();
        $generator->enrol_user($user->id, $course->id);
        $DB->insert_record('course_completions', (object) [
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time(),
            'timestarted' => time(),
            'timecompleted' => time(),
        ]);

        default_charts::seed();
        foreach (default_charts::CHARTS as $idnumber => $definition) {
            $spec = chart_spec::from_json(json_encode($definition['chart']));
            if ($spec->is_table()) {
                continue;
            }

            if (($definition['kind'] ?? 'oneshot') === 'trend') {
                $record = $DB->get_record('local_aicharts_chart', ['idnumber' => $idnumber]);
                $values = query_runner::run_points([(object) [
                    'label' => $definition['name'],
                    'sqltext' => $definition['sql'],
                    'params' => $definition['params'],
                ]], 500);
                point_store::append($record, $values->rows[0], time(), null);
                $rows = point_store::format_rows(point_store::rows($record->id));
            } else {
                $rows = query_runner::run($definition['sql'], $definition['params'], 500)->rows;
            }
            $chart = chart_factory::create($spec, $rows);

            foreach ($chart->get_series() as $series) {
                $this->assertGreaterThan(0, $series->get_count(), $idnumber . ' has an empty series');
            }
        }
    }
}
