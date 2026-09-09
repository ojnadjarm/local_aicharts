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
        set_config('allowedtables', implode("\n", [
            'user',
            'course',
            'course_categories',
            'enrol',
            'user_enrolments',
            'role',
            'role_assignments',
            'course_completions',
        ]), 'local_aicharts');
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

        foreach (default_charts::CHARTS as $idnumber => $definition) {
            $spec = chart_spec::from_json(json_encode($definition['chart']));
            if ($spec->is_table()) {
                continue;
            }

            $result = query_runner::run($definition['sql'], $definition['params'], 500);
            $chart = chart_factory::create($spec, $result->rows);

            foreach ($chart->get_series() as $series) {
                $this->assertGreaterThan(0, $series->get_count(), $idnumber . ' has an empty series');
            }
        }
    }
}
