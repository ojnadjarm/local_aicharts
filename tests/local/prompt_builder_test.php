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
 * Tests for the prompt builder.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class prompt_builder_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $DB->delete_records('local_aicharts_chart');
    }

    /**
     * Saves an example chart.
     *
     * @param string $name Chart name.
     * @param string $prompt Prompt the chart was generated from.
     * @return int The chart id.
     */
    protected function create_chart(string $name, string $prompt): int {
        return chart_repository::save((object) [
            'name' => $name,
            'prompt' => $prompt,
            'queries' => [[
                'label' => $name,
                'sqltext' => 'SELECT c.fullname, COUNT(ue.id) AS total FROM {course} c',
                'params' => '{"since":123}',
            ]],
            'chartjson' => '{"type":"bar","title":"' . $name . '","labelcolumn":"fullname","series":[]}',
        ]);
    }

    /**
     * The instructions carry the catalogued tables, their hints and the SQL rules.
     *
     * @covers \local_aicharts\local\prompt_builder::system_prompt
     * @covers \local_aicharts\local\schema_catalogue::hint_block
     * @covers \local_aicharts\local\schema_catalogue::hints
     */
    public function test_system_prompt_lists_catalogued_tables_and_sql_rules(): void {
        global $DB;

        $this->resetAfterTest();
        set_config('extrainstructions', 'Ignore test accounts.', 'local_aicharts');

        $prompt = prompt_builder::system_prompt();

        $this->assertStringContainsString('- {user}: id, username', $prompt);
        $this->assertStringContainsString('- {course}: id, category', $prompt);
        $this->assertStringContainsString('- {quiz_attempts}: id, quiz', $prompt);
        $this->assertStringContainsString('- {cohort}: id, contextid', $prompt);
        $this->assertStringContainsString('One SELECT statement', $prompt);
        $this->assertStringContainsString('Never write LIMIT, OFFSET or FETCH', $prompt);
        $this->assertStringContainsString('Never write a colon inside a quoted string', $prompt);
        $this->assertStringContainsString('The database family is ' . $DB->get_dbfamily(), $prompt);
        $this->assertStringContainsString('{"status":"refused","name":null,"sql":null,"params":null,"chart":null,', $prompt);
        $this->assertStringContainsString('When status is chart, name, sql and chart must be filled.', $prompt);
        $this->assertStringContainsString('Ignore test accounts.', $prompt);
    }

    /**
     * Each matching saved chart becomes a user and an assistant message.
     *
     * @covers \local_aicharts\local\prompt_builder::build_messages
     */
    public function test_examples_become_message_pairs(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->create_chart('Enrolments per course', 'enrolments per course this year');

        $messages = prompt_builder::build_messages('enrolments per course last year');

        $this->assertCount(4, $messages);
        $this->assertSame(['system', 'user', 'assistant', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('Request: enrolments per course this year', $messages[1]['content']);

        $answer = json_decode($messages[2]['content'], true);
        $this->assertSame('chart', $answer['status']);
        $this->assertSame('Enrolments per course', $answer['name']);
        $this->assertStringContainsString('FROM {course} c', $answer['sql']);
        $this->assertSame(['since' => 123], $answer['params']);
        $this->assertSame('bar', $answer['chart']['type']);
        $this->assertStringContainsString('Request: enrolments per course last year', $messages[3]['content']);
    }

    /**
     * The query the series already holds is sent with the request.
     *
     * @covers \local_aicharts\local\prompt_builder::build_messages
     */
    public function test_hints_included(): void {
        $this->resetAfterTest();

        $messages = prompt_builder::build_messages('active users per month', 'SELECT 1');

        $request = end($messages)['content'];
        $this->assertStringStartsWith('<request>', $request);
        $this->assertStringContainsString('Request: active users per month', $request);
        $this->assertStringContainsString('Query hint: SELECT 1', $request);
        $this->assertStringEndsWith('</request>', $request);
    }

    /**
     * The answer schema requires every property, the optional ones nullable, and embeds the chart schema.
     *
     * @covers \local_aicharts\local\prompt_builder::response_schema
     */
    public function test_response_schema_requires_every_property(): void {
        $schema = prompt_builder::response_schema();

        $this->assertSame(array_keys($schema['properties']), $schema['required']);
        foreach (['name', 'sql', 'notes'] as $key) {
            $this->assertSame(['string', 'null'], $schema['properties'][$key]['type']);
        }
        $this->assertSame(['object', 'null'], $schema['properties']['params']['type']);
        $this->assertSame(['object', 'null'], $schema['properties']['chart']['type']);
        $this->assertSame(prompt_builder::CHART_SCHEMA['required'], $schema['properties']['chart']['required']);
        $this->assertSame(prompt_builder::CHART_SCHEMA['properties'], $schema['properties']['chart']['properties']);
    }

    /**
     * A chart-only request lists the columns, the sample rows and the hint, and asks for the chart schema.
     *
     * @covers \local_aicharts\local\prompt_builder::chart_messages
     */
    public function test_chart_messages_list_columns(): void {
        $rows = [['coursename' => 'Maths', 'total' => 4]];

        $messages = prompt_builder::chart_messages(['coursename', 'total'], $rows, 'stacked bars', 'oneshot');

        $this->assertSame(['system', 'user'], array_column($messages, 'role'));
        $this->assertStringContainsString('"labelcolumn"', $messages[0]['content']);
        $this->assertStringContainsString('{"status":"refused"}', $messages[0]['content']);
        $this->assertStringNotContainsString('runtime', $messages[0]['content']);
        $this->assertStringStartsWith('Columns: coursename, total', $messages[1]['content']);
        $this->assertStringContainsString('Sample rows: [{"coursename":"Maths","total":4}]', $messages[1]['content']);
        $this->assertStringEndsWith('Hint: stacked bars', $messages[1]['content']);

        $messages = prompt_builder::chart_messages(['runtime', 'Active'], [], '', 'trend');
        $this->assertStringContainsString('labelcolumn is "runtime"', $messages[0]['content']);
        $this->assertStringNotContainsString('Hint:', $messages[1]['content']);
    }
}
