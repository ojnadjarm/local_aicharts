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
 * Tests for the query runner.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class query_runner_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('allowedtables', "user\ncourse\n", 'local_aicharts');
        set_config('maxrowsmax', 5000, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
    }

    /**
     * A valid query returns its rows.
     *
     * @covers \local_aicharts\local\query_runner::run
     */
    public function test_runs_query(): void {
        $first = $this->getDataGenerator()->create_user(['firstname' => 'Ada']);
        $second = $this->getDataGenerator()->create_user(['firstname' => 'Grace']);

        $result = query_runner::run(
            'SELECT id, firstname FROM {user} WHERE id >= :minid ORDER BY id',
            ['minid' => $first->id],
            100
        );

        $this->assertSame('ok', $result->status);
        $this->assertFalse($result->has_error());
        $this->assertFalse($result->truncated);
        $this->assertSame(2, $result->rowcount);
        $this->assertSame('Ada', $result->rows[0]->firstname);
        $this->assertSame((string) $second->id, (string) $result->rows[1]->id);
        $this->assertGreaterThanOrEqual(0, $result->durationms);
    }

    /**
     * More rows than the limit are cut and flagged.
     *
     * @covers \local_aicharts\local\query_runner::run
     */
    public function test_row_limit_sets_truncated(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();

        $result = query_runner::run(
            'SELECT id, firstname FROM {user} WHERE id >= :minid ORDER BY id',
            ['minid' => $user->id],
            1
        );

        $this->assertSame('ok', $result->status);
        $this->assertTrue($result->truncated);
        $this->assertSame(1, $result->rowcount);
    }

    /**
     * A broken query comes back as a result, not an exception.
     *
     * @covers \local_aicharts\local\query_runner::run
     */
    public function test_db_error_is_returned_not_thrown(): void {
        $result = query_runner::run('SELECT nosuchcolumn FROM {user}', [], 100);

        $this->assertSame('db_error', $result->status);
        $this->assertTrue($result->has_error());
        $this->assertNotEmpty($result->errormessage);
        $this->assertSame([], $result->rows);
    }

    /**
     * A rejected query comes back as a result, not an exception.
     *
     * @covers \local_aicharts\local\query_runner::run
     */
    public function test_invalid_query_is_returned_not_thrown(): void {
        $result = query_runner::run('SELECT id FROM {config}', [], 100);

        $this->assertSame('validation_failed', $result->status);
        $this->assertStringContainsString('config', $result->errormessage);
    }

    /**
     * The statement timeout is in force during the query and gone afterwards.
     *
     * @covers \local_aicharts\local\query_runner::run
     * @covers \local_aicharts\local\query_runner::set_timeout
     */
    public function test_timeout_is_set_and_reset(): void {
        global $DB;

        $family = $DB->get_dbfamily();
        if ($family === 'postgres') {
            $result = query_runner::run("SELECT current_setting('statement_timeout') AS value", [], 10);
            $this->assertSame('ok', $result->status);
            $this->assertSame('20s', $result->rows[0]->value);
            $this->assertSame('0', $DB->get_field_sql("SELECT current_setting('statement_timeout')"));
        } else if ($family === 'mysql') {
            $result = query_runner::run('SELECT id FROM {user}', [], 10);
            $this->assertSame('ok', $result->status);
            $setting = $DB->get_dbvendor() === 'mariadb' ? 'max_statement_time' : 'max_execution_time';
            $this->assertEquals(0, $DB->get_field_sql('SELECT @@SESSION.' . $setting));
        } else {
            $this->markTestSkipped('No statement timeout is applied on the ' . $family . ' family.');
        }
    }
}
