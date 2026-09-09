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

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for the OpenAI-compatible client, against a mocked HTTP layer.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class openai_client_test extends \advanced_testcase {
    /** @var string Key used by the tests; must never appear in an error message. */
    protected const KEY = 'sk-test-secret-key';

    /** @var array Messages sent in every test. */
    protected const MESSAGES = [
        ['role' => 'system', 'content' => 'Instructions.'],
        ['role' => 'user', 'content' => '<request>users per course</request>'],
    ];

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('clienttype', 'openai', 'local_aicharts');
        set_config('baseurl', 'https://llm.example.com/v1/', 'local_aicharts');
        set_config('apikey', self::KEY, 'local_aicharts');
        set_config('model', 'test-model', 'local_aicharts');
        set_config('timeout', 7, 'local_aicharts');
        set_config('temperature', 0.5, 'local_aicharts');
    }

    /**
     * The factory builds this client when the provider is openai.
     *
     * @covers \local_aicharts\llm\client_factory::create
     */
    public function test_factory_builds_openai_client(): void {
        $this->assertInstanceOf(openai_client::class, client_factory::create());
    }

    /**
     * The request carries the key, the model, the messages and the schema; the content comes back.
     *
     * @covers \local_aicharts\llm\openai_client::generate
     */
    public function test_sends_schema_and_parses_content(): void {
        $history = [];
        ['mock' => $mock] = $this->get_mocked_http_client($history);
        $answer = '{"status":"chart","name":"Users per course"}';
        $mock->append(new Response(200, [], json_encode([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $answer]]],
        ])));
        $schema = ['type' => 'object', 'required' => ['status']];

        $response = (new openai_client())->generate(self::MESSAGES, $schema);

        $this->assertFalse($response->has_error());
        $this->assertSame($answer, $response->content);
        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://llm.example.com/v1/chat/completions', (string) $request->getUri());
        $this->assertSame('Bearer ' . self::KEY, $request->getHeaderLine('Authorization'));
        $this->assertSame(7, $history[0]['options']['timeout']);
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('test-model', $body['model']);
        $this->assertSame(self::MESSAGES, $body['messages']);
        $this->assertSame(0.5, $body['temperature']);
        $this->assertSame('json_schema', $body['response_format']['type']);
        $this->assertSame($schema, $body['response_format']['json_schema']['schema']);
    }

    /**
     * An error status becomes an llm error that names the status but not the key.
     *
     * @covers \local_aicharts\llm\openai_client::generate
     */
    public function test_http_error_becomes_llm_error(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(401, [], json_encode([
            'error' => ['message' => 'Incorrect API key provided: ' . self::KEY],
        ])));

        $response = (new openai_client())->generate(self::MESSAGES, []);

        $this->assertTrue($response->has_error());
        $this->assertSame(openai_client::ERROR_HTTP, $response->errorcode);
        $this->assertStringContainsString('HTTP 401', $response->errormessage);
        $this->assertStringContainsString('Incorrect API key', $response->errormessage);
        $this->assertStringNotContainsString(self::KEY, $response->errormessage);
    }

    /**
     * A transport failure becomes an unreachable error.
     *
     * @covers \local_aicharts\llm\openai_client::generate
     */
    public function test_transport_failure_is_unreachable(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new ConnectException('Connection timed out', new Request('POST', 'https://llm.example.com')));

        $response = (new openai_client())->generate(self::MESSAGES, []);

        $this->assertSame(openai_client::ERROR_UNREACHABLE, $response->errorcode);
        $this->assertSame('Connection timed out', $response->errormessage);
    }

    /**
     * A 2xx answer without a message content is reported, not parsed.
     *
     * @covers \local_aicharts\llm\openai_client::generate
     */
    public function test_answer_without_content_is_reported(): void {
        ['mock' => $mock] = $this->get_mocked_http_client();
        $mock->append(new Response(200, [], '{"choices":[]}'));

        $response = (new openai_client())->generate(self::MESSAGES, []);

        $this->assertSame(openai_client::ERROR_BADANSWER, $response->errorcode);
    }

    /**
     * Without a key nothing is sent and the nokey code is returned.
     *
     * @covers \local_aicharts\llm\openai_client::generate
     */
    public function test_missing_key_is_reported_without_request(): void {
        set_config('apikey', ' ', 'local_aicharts');
        $history = [];
        $this->get_mocked_http_client($history);

        $response = (new openai_client())->generate(self::MESSAGES, []);

        $this->assertSame(openai_client::ERROR_NOKEY, $response->errorcode);
        $this->assertSame(get_string('error_llm_nokey', 'local_aicharts'), $response->errormessage);
        $this->assertCount(0, $history);
    }
}
