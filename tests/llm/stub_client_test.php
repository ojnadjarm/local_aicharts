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
 * Tests for the offline client and the client factory.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class stub_client_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        stub_client::reset_call_count();
    }

    /**
     * Ask the offline client one prompt.
     *
     * @param string $prompt Prompt of the last user message.
     * @return array Parsed answer.
     */
    protected function ask(string $prompt): array {
        $messages = [
            ['role' => 'system', 'content' => 'Instructions.'],
            ['role' => 'user', 'content' => '<request>' . $prompt . '</request>'],
        ];
        return response_parser::parse((new stub_client())->generate($messages, [])->content);
    }

    /**
     * Any request in scope gets the sample bar chart.
     *
     * @covers \local_aicharts\llm\stub_client::generate
     */
    public function test_default_answer_is_a_bar_chart(): void {
        $parsed = $this->ask('users per course');

        $this->assertSame(response_parser::STATUS_CHART, $parsed['status']);
        $this->assertSame('bar', $parsed['chart']->type);
        $this->assertStringContainsString('{course}', $parsed['sql']);
        $this->assertSame(['siteid' => 1], $parsed['params']);
    }

    /**
     * A request for a list of rows gets a table answer.
     *
     * @covers \local_aicharts\llm\stub_client::generate
     */
    public function test_list_answer_is_a_table(): void {
        $parsed = $this->ask('list the users who never logged in');

        $this->assertTrue($parsed['chart']->is_table());
        $this->assertSame('Users who never logged in', $parsed['name']);
    }

    /**
     * The slow sample is a heavy query the validator still accepts.
     *
     * @covers \local_aicharts\llm\stub_client::generate
     */
    public function test_slow_answer_passes_the_validator(): void {
        $parsed = $this->ask('a slow query of users');

        $this->assertStringContainsString('{course}', $parsed['sql']);
        \local_aicharts\local\sql_validator::validate($parsed['sql'], $parsed['params']);
        $this->assertSame(['deleted' => 0], $parsed['params']);
    }

    /**
     * The refusal keyword and a prompt about nothing in the data are both refused.
     *
     * @covers \local_aicharts\llm\stub_client::generate
     */
    public function test_out_of_scope_prompts_are_refused(): void {
        $this->assertSame(response_parser::STATUS_REFUSED, $this->ask('please refuse this one')['status']);
        $this->assertSame(response_parser::STATUS_REFUSED, $this->ask('qwerty asdfgh zxcvbn')['status']);
    }

    /**
     * Every answer produced is counted.
     *
     * @covers \local_aicharts\llm\stub_client::get_call_count
     */
    public function test_calls_are_counted(): void {
        $this->ask('users per course');
        $this->ask('users per course');

        $this->assertSame(2, stub_client::get_call_count());
    }

    /**
     * The factory builds the offline client while the site is configured for it.
     *
     * @covers \local_aicharts\llm\client_factory::create
     */
    public function test_factory_creates_the_configured_client(): void {
        $this->resetAfterTest();
        set_config('clienttype', 'stub', 'local_aicharts');

        $this->assertInstanceOf(stub_client::class, client_factory::create());
    }
}
