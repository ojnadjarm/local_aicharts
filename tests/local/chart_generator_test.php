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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use local_aicharts\llm\stub_client;

/**
 * Tests for the chart generator.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chart_generator_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('clienttype', 'stub', 'local_aicharts');
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
        stub_client::reset_call_count();
        chart_generator::reset_caches();
    }

    /**
     * Generate with only a prompt.
     *
     * @param string $prompt What is asked for.
     * @return generation_result
     */
    protected function generate(string $prompt): generation_result {
        global $USER;

        return chart_generator::generate($prompt, '', 100, (int) $USER->id);
    }

    /**
     * A request in scope produces a chart with rows and an ok log row.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     * @covers \local_aicharts\local\generation_result::has_error
     */
    public function test_generates_chart(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $course->id);

        $result = $this->generate('users per course');

        $this->assertSame('chart', $result->status);
        $this->assertFalse($result->has_error());
        $this->assertSame('Users per course', $result->name);
        $this->assertStringContainsString('{course}', $result->sql);
        $this->assertSame(['siteid' => 1], $result->params);
        $this->assertSame('bar', json_decode($result->chartjson)->type);
        $this->assertSame(1, $result->rowcount);
        $this->assertSame('1', (string) $result->rows[0]->total);
        $this->assertSame('', $result->errormessage);
        $this->assertNotEmpty($result->notes);

        $run = $DB->get_record('local_aicharts_run', [], '*', MUST_EXIST);
        $this->assertSame('ok', $run->status);
        $this->assertSame('1', $run->numrows);
        $this->assertSame($result->sql, $run->sqltext);
    }

    /**
     * A table answer is reported as a table.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_generates_table(): void {
        $result = $this->generate('list of users who never logged in');

        $this->assertSame('table', $result->status);
        $this->assertSame('table', json_decode($result->chartjson)->type);
        $this->assertGreaterThan(0, $result->rowcount);
    }

    /**
     * A refusal keeps its status and carries no query.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_refusal_returns_fixed_status(): void {
        global $DB;

        $result = $this->generate('tell me a joke');

        $this->assertSame('refused', $result->status);
        $this->assertTrue($result->has_error());
        $this->assertSame('', $result->sql);
        $this->assertSame('refused', $DB->get_field('local_aicharts_run', 'status', []));
    }

    /**
     * A query the validator keeps refusing is retried once, then fails with both attempts logged.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_invalid_sql_is_validation_failed(): void {
        global $DB;

        $result = $this->generate('limit users per course');

        $this->assertSame('validation_failed', $result->status);
        $this->assertStringContainsString('LIMIT', $result->errormessage);
        $this->assertSame([], $result->rows);
        $this->assertSame(2, stub_client::get_call_count());
        $runs = array_values($DB->get_records('local_aicharts_run', [], 'id'));
        $this->assertCount(2, $runs);
        $this->assertSame('validation_failed', $runs[0]->status);
        $this->assertSame('validation_failed', $runs[1]->status);
        $this->assertSame($result->errormessage, $runs[1]->errormessage);
    }

    /**
     * A rejected first answer is sent back with the validator message and the corrected answer is used.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_retries_once_on_invalid_sql(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $course->id);

        $result = $this->generate('wrongtable users per course');

        $this->assertSame('chart', $result->status);
        $this->assertSame('Users per course', $result->name);
        $this->assertSame(2, stub_client::get_call_count());
        $runs = array_values($DB->get_records('local_aicharts_run', [], 'id'));
        $this->assertCount(2, $runs);
        $this->assertSame('validation_failed', $runs[0]->status);
        $this->assertStringContainsString('mdl_user', $runs[0]->errormessage);
        $this->assertStringContainsString('mdl_user', $runs[0]->sqltext);
        $this->assertSame('ok', $runs[1]->status);
        $this->assertSame($result->sql, $runs[1]->sqltext);
    }

    /**
     * A refusal is final: no second call, one log row.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_no_retry_on_refusal(): void {
        global $DB;

        $result = $this->generate('tell me a joke');

        $this->assertSame('refused', $result->status);
        $this->assertSame(1, stub_client::get_call_count());
        $this->assertSame(1, $DB->count_records('local_aicharts_run'));
    }

    /**
     * A provider without a key reports the nokey code without any retry.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_nokey_error_has_code(): void {
        global $DB;

        set_config('clienttype', 'openai', 'local_aicharts');
        set_config('apikey', '', 'local_aicharts');

        $result = $this->generate('users per course');

        $this->assertSame('llm_error', $result->status);
        $this->assertSame('nokey', $result->errorcode);
        $this->assertSame(get_string('error_llm_nokey', 'local_aicharts'), $result->errormessage);
        $run = $DB->get_record('local_aicharts_run', [], '*', MUST_EXIST);
        $this->assertSame('llm_error', $run->status);
        $this->assertSame($result->errormessage, $run->errormessage);
    }

    /**
     * A transport failure reports the unreachable code with a sanitised detail.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_unreachable_error_has_code(): void {
        global $DB;

        set_config('clienttype', 'openai', 'local_aicharts');
        set_config('baseurl', 'https://llm.example.com/v1', 'local_aicharts');
        set_config('apikey', 'sk-test-secret-key', 'local_aicharts');
        set_config('model', 'test-model', 'local_aicharts');
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new ConnectException(
            'Could not resolve host with sk-test-secret-key',
            new Request('POST', 'https://llm.example.com/v1/chat/completions')
        ));

        $result = $this->generate('users per course');

        $this->assertSame('llm_error', $result->status);
        $this->assertSame('unreachable', $result->errorcode);
        $this->assertStringContainsString('Could not resolve host', $result->errormessage);
        $this->assertStringNotContainsString('sk-test-secret-key', $result->errormessage);
        $this->assertSame(1, $DB->count_records('local_aicharts_run'));
    }

    /**
     * The same request twice in one process calls the client once and logs once.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     * @covers \local_aicharts\local\chart_generator::reset_caches
     */
    public function test_generate_calls_client_once(): void {
        global $DB;

        $first = $this->generate('users per course');
        $second = $this->generate('users per course');

        $this->assertSame($first, $second);
        $this->assertSame(1, stub_client::get_call_count());
        $this->assertSame(1, $DB->count_records('local_aicharts_run'));

        chart_generator::reset_caches();
        $this->generate('users per course');
        $this->assertSame(2, stub_client::get_call_count());
    }

    /**
     * Every distinct attempt gets its own log row.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_logs_each_attempt(): void {
        global $DB, $USER;

        $this->generate('users per course');
        $this->generate('tell me a joke');

        $runs = array_values($DB->get_records('local_aicharts_run', [], 'id'));
        $this->assertCount(2, $runs);
        $this->assertSame('users per course', $runs[0]->prompt);
        $this->assertSame('ok', $runs[0]->status);
        $this->assertSame('tell me a joke', $runs[1]->prompt);
        $this->assertSame('refused', $runs[1]->status);
        $this->assertNull($runs[1]->sqltext);
        $this->assertEquals($USER->id, $runs[1]->userid);
        $this->assertGreaterThan(0, $runs[1]->timecreated);
    }

    /**
     * Point the generator at a mocked provider answering the given texts in turn.
     *
     * @param string ...$contents Answer texts the provider returns.
     */
    protected function mock_provider_answers(string ...$contents): void {
        set_config('clienttype', 'openai', 'local_aicharts');
        set_config('baseurl', 'https://llm.example.com/v1', 'local_aicharts');
        set_config('apikey', 'sk-test-secret-key', 'local_aicharts');
        set_config('model', 'test-model', 'local_aicharts');
        ['mock' => $mock] = $this->get_mocked_http_client();
        foreach ($contents as $content) {
            $mock->append(new Response(200, [], json_encode([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            ])));
        }
    }

    /**
     * A provider answer without a name is a chart named after its title.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_answer_without_name_is_a_chart(): void {
        global $DB;

        $this->mock_provider_answers(file_get_contents(__DIR__ . '/../fixtures/provider_answers/name_omitted.json'));

        $result = $this->generate('users per course');

        $this->assertSame('chart', $result->status);
        $this->assertSame('Users per course', $result->name);
        $this->assertSame('ok', $DB->get_field('local_aicharts_run', 'status', []));
    }

    /**
     * An answer that cannot be read is logged with its text after the reason.
     *
     * @covers \local_aicharts\local\chart_generator::generate
     */
    public function test_invalid_answer_is_logged_with_its_text(): void {
        global $DB;

        $content = '{"status":"chart","name":"Users per course","chart":{"type":"bar"}}';
        $this->mock_provider_answers($content, $content);

        $result = $this->generate('users per course');

        $this->assertSame('invalid_json', $result->status);
        $this->assertStringNotContainsString($content, $result->errormessage);
        $runs = $DB->get_records('local_aicharts_run', ['status' => 'invalid_json']);
        $this->assertCount(2, $runs);
        $run = reset($runs);
        $this->assertStringContainsString('the query is missing', $run->errormessage);
        $this->assertStringContainsString($content, $run->errormessage);
    }

    /**
     * A chart-only request logs one attempt with the hint and no query, and a refusal is logged too.
     *
     * @covers \local_aicharts\local\chart_generator::generate_chart
     */
    public function test_generate_chart_logs_without_sql(): void {
        global $DB, $USER;

        $rows = [['coursename' => 'Maths', 'total' => 4]];
        $result = chart_generator::generate_chart(['coursename', 'total'], $rows, 'stacked bars', 'oneshot', (int) $USER->id);

        $this->assertSame('chart', $result->status);
        $this->assertSame('Users per course', $result->name);
        $spec = chart_spec::from_json($result->chartjson);
        $this->assertSame('bar', $spec->type);
        $this->assertSame('coursename', $spec->labelcolumn);
        $this->assertSame([], $result->rows);
        $this->assertSame(1, stub_client::get_call_count());
        $run = $DB->get_record('local_aicharts_run', [], '*', MUST_EXIST);
        $this->assertSame('ok', $run->status);
        $this->assertSame('stacked bars', $run->prompt);
        $this->assertNull($run->sqltext);

        $result = chart_generator::generate_chart(['coursename', 'total'], $rows, 'tell me a joke', 'oneshot', (int) $USER->id);
        $this->assertSame('refused', $result->status);
        $this->assertSame(2, stub_client::get_call_count());
        $this->assertSame(1, $DB->count_records('local_aicharts_run', ['status' => 'refused']));
    }
}
