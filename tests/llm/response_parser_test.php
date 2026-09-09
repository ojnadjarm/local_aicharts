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

namespace local_aicharts\llm;

/**
 * Tests for the response parser.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class response_parser_test extends \advanced_testcase {
    /**
     * A chart answer yields the name, the query, the parameters and a chart spec.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_parses_chart_answer(): void {
        $parsed = response_parser::parse(json_encode([
            'status' => 'chart',
            'name' => 'Users per course',
            'sql' => 'SELECT c.fullname AS coursename, 1 AS total FROM {course} c WHERE c.id <> :siteid',
            'params' => ['siteid' => 1],
            'chart' => [
                'type' => 'bar',
                'title' => 'Users per course',
                'labelcolumn' => 'coursename',
                'series' => [['column' => 'total', 'label' => 'Users']],
            ],
            'notes' => 'The site course is excluded.',
        ]));

        $this->assertSame(response_parser::STATUS_CHART, $parsed['status']);
        $this->assertSame('Users per course', $parsed['name']);
        $this->assertStringStartsWith('SELECT', $parsed['sql']);
        $this->assertSame(['siteid' => 1], $parsed['params']);
        $this->assertSame('bar', $parsed['chart']->type);
        $this->assertSame('The site course is excluded.', $parsed['notes']);
        $this->assertSame('bar', json_decode($parsed['chartjson'], true)['type']);
    }

    /**
     * A table answer keeps the empty label column and series.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_parses_table_answer(): void {
        $parsed = response_parser::parse(json_encode([
            'status' => 'chart',
            'name' => 'Users who never logged in',
            'sql' => 'SELECT u.email AS email FROM {user} u',
            'chart' => [
                'type' => 'table',
                'title' => 'Users who never logged in',
                'labelcolumn' => '',
                'series' => [],
            ],
        ]));

        $this->assertTrue($parsed['chart']->is_table());
        $this->assertSame([], $parsed['params']);
    }

    /**
     * An answer wrapped in a Markdown code fence is still read.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_parses_answer_inside_a_code_fence(): void {
        $answer = response_parser::FENCE . "json\n{\"status\":\"refused\"}\n" . response_parser::FENCE;

        $this->assertSame(response_parser::STATUS_REFUSED, response_parser::parse($answer)['status']);
    }

    /**
     * A refusal, prose and anything that is not JSON are all refusals.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_non_json_and_refusal_are_refused(): void {
        $this->assertSame(response_parser::STATUS_REFUSED, response_parser::parse('{"status":"refused"}')['status']);
        $this->assertSame(response_parser::STATUS_REFUSED, response_parser::parse('I cannot help with that.')['status']);
        $this->assertSame(response_parser::STATUS_REFUSED, response_parser::parse('')['status']);
    }

    /**
     * A chart answer missing the name, the query or the chart is invalid.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_missing_members_are_invalid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/the chart definition is missing/');

        response_parser::parse(json_encode([
            'status' => 'chart',
            'name' => 'Users per course',
            'sql' => 'SELECT 1 AS total FROM {course} c',
        ]));
    }

    /**
     * A parameter that is not a scalar is invalid and is named.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_non_scalar_parameter_is_invalid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/"since"/');

        response_parser::parse(json_encode([
            'status' => 'chart',
            'name' => 'Users per course',
            'sql' => 'SELECT 1 AS total FROM {course} c WHERE c.id > :since',
            'params' => ['since' => ['a']],
            'chart' => [
                'type' => 'bar',
                'title' => 'Users per course',
                'labelcolumn' => 'coursename',
                'series' => [['column' => 'total', 'label' => 'Users']],
            ],
        ]));
    }
}
