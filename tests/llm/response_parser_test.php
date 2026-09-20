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

    /**
     * Every recorded provider answer.
     *
     * @return array Fixture name to its raw text.
     */
    public static function provider_answers_provider(): array {
        $cases = [];
        foreach (glob(__DIR__ . '/../fixtures/provider_answers/*.json') as $file) {
            $cases[basename($file, '.json')] = [file_get_contents($file)];
        }
        return $cases;
    }

    /**
     * Every recorded provider answer is read as a named chart.
     *
     * @dataProvider provider_answers_provider
     * @covers \local_aicharts\llm\response_parser::parse
     * @param string $content Raw answer text.
     */
    public function test_provider_answers_are_charts(string $content): void {
        $parsed = response_parser::parse($content, 'users per course');

        $this->assertSame(response_parser::STATUS_CHART, $parsed['status']);
        $this->assertNotSame('', $parsed['name']);
    }

    /**
     * An answer without a name takes the chart title.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_name_falls_back_to_chart_title(): void {
        $content = file_get_contents(__DIR__ . '/../fixtures/provider_answers/name_omitted.json');

        $this->assertSame('Users per course', response_parser::parse($content)['name']);
    }

    /**
     * An answer with a null name and no title takes the start of the request.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_null_name_falls_back_to_request(): void {
        $content = file_get_contents(__DIR__ . '/../fixtures/provider_answers/name_null.json');
        $request = str_repeat('users per course ', 5);

        $parsed = response_parser::parse($content, $request);

        $this->assertSame(\core_text::substr(trim($request), 0, response_parser::FALLBACK_NAME_LENGTH), $parsed['name']);
        $this->assertSame('', $parsed['notes']);
    }

    /**
     * An answer wrapped under the schema name is unwrapped.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_wrapped_answer_is_unwrapped(): void {
        $content = file_get_contents(__DIR__ . '/../fixtures/provider_answers/wrapped.json');

        $parsed = response_parser::parse($content);

        $this->assertSame(response_parser::STATUS_CHART, $parsed['status']);
        $this->assertSame('Users per course', $parsed['name']);
    }

    /**
     * A chart answer naming nothing anywhere is invalid.
     *
     * @covers \local_aicharts\llm\response_parser::parse
     */
    public function test_answer_without_any_name_is_invalid(): void {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/the name is missing/');

        response_parser::parse(json_encode([
            'status' => 'chart',
            'sql' => 'SELECT 1 AS total FROM {course} c',
            'chart' => ['type' => 'table', 'title' => '', 'labelcolumn' => '', 'series' => []],
        ]));
    }

    /**
     * A chart-only answer is read with or without a wrapper and a fence, a refusal gives null, an invalid chart throws.
     *
     * @covers \local_aicharts\llm\response_parser::parse_chart
     */
    public function test_parse_chart_unwraps_and_validates(): void {
        $chart = ['type' => 'pie', 'title' => 'Users', 'labelcolumn' => 'coursename', 'series' => [['column' => 'total']]];

        $spec = response_parser::parse_chart(json_encode($chart));
        $this->assertSame('pie', $spec->type);
        $this->assertSame([['column' => 'total', 'label' => 'total']], $spec->series);

        $wrapped = response_parser::FENCE . "json\n" . json_encode(['chart' => $chart]) . "\n" . response_parser::FENCE;
        $this->assertSame('Users', response_parser::parse_chart($wrapped)->title);

        $this->assertNull(response_parser::parse_chart('{"status":"refused"}'));
        $this->assertNull(response_parser::parse_chart('I cannot help with that.'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/unknown chart type/');
        response_parser::parse_chart('{"type":"scatter","labelcolumn":"x","series":[]}');
    }
}
