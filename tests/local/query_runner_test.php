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
        $result = query_runner::run('SELECT id FROM mdl_config', [], 100);

        $this->assertSame('validation_failed', $result->status);
        $this->assertStringContainsString('mdl_config', $result->errormessage);
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

    /**
     * Two-column query records the merge tests share.
     *
     * @param int $minid Lowest user id the queries return.
     * @return \stdClass[] Records with label, sqltext and params.
     */
    protected function queries(int $minid): array {
        $sql = 'SELECT firstname AS name, id AS total FROM {user} WHERE id >= :minid AND lastname = :group ORDER BY id';
        return [
            (object) ['label' => 'First', 'sqltext' => $sql, 'params' => ['minid' => $minid, 'group' => 'a']],
            (object) ['label' => 'Second', 'sqltext' => $sql, 'params' => json_encode(['minid' => $minid, 'group' => 'b'])],
        ];
    }

    /**
     * One query passes through unchanged, whatever its columns.
     *
     * @covers \local_aicharts\local\query_runner::run_all
     */
    public function test_run_all_single_query_is_unchanged(): void {
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Ada']);
        $query = (object) [
            'label' => 'Users',
            'sqltext' => 'SELECT id, firstname, lastname FROM {user} WHERE id >= :minid ORDER BY id',
            'params' => '{"minid":' . $user->id . '}',
        ];

        $result = query_runner::run_all([$query], 100);
        $expected = query_runner::run($query->sqltext, ['minid' => $user->id], 100);

        $this->assertSame('ok', $result->status);
        $this->assertEquals($expected->rows, $result->rows);
        $this->assertSame(['id', 'firstname', 'lastname'], array_keys((array) $result->rows[0]));
    }

    /**
     * Several queries merge on the first column, keys in order of first appearance.
     *
     * @covers \local_aicharts\local\query_runner::run_all
     * @covers \local_aicharts\local\query_runner::merge
     */
    public function test_run_all_merges_on_first_column_in_order(): void {
        $ada = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'a']);
        $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'a']);
        $this->getDataGenerator()->create_user(['firstname' => 'Grace', 'lastname' => 'b']);
        $this->getDataGenerator()->create_user(['firstname' => 'Linus', 'lastname' => 'b']);

        $result = query_runner::run_all($this->queries($ada->id), 100);

        $this->assertSame('ok', $result->status);
        $this->assertSame(3, $result->rowcount);
        $this->assertSame(['name', 'First', 'Second'], array_keys((array) $result->rows[0]));
        $this->assertSame(['Ada', 'Grace', 'Linus'], array_column($result->rows, 'name'));
        $this->assertNotNull($result->rows[1]->First);
        $this->assertNotNull($result->rows[1]->Second);
        $this->assertNotEquals($result->rows[1]->First, $result->rows[1]->Second);
    }

    /**
     * A key one query does not return gets null in that series.
     *
     * @covers \local_aicharts\local\query_runner::run_all
     * @covers \local_aicharts\local\query_runner::merge
     */
    public function test_run_all_fills_missing_keys_with_null(): void {
        $ada = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'a']);
        $this->getDataGenerator()->create_user(['firstname' => 'Linus', 'lastname' => 'b']);

        $result = query_runner::run_all($this->queries($ada->id), 100);

        $this->assertSame('ok', $result->status);
        $this->assertNull($result->rows[0]->Second);
        $this->assertNull($result->rows[1]->First);
        $this->assertNotNull($result->rows[0]->First);
        $this->assertNotNull($result->rows[1]->Second);
    }

    /**
     * With several queries, a query returning more than two columns is rejected by name.
     *
     * @covers \local_aicharts\local\query_runner::run_all
     */
    public function test_run_all_rejects_three_columns_when_several_queries(): void {
        $ada = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'a']);
        $this->getDataGenerator()->create_user(['firstname' => 'Linus', 'lastname' => 'b']);
        $queries = $this->queries($ada->id);
        $queries[1]->sqltext = 'SELECT firstname, id, lastname FROM {user} WHERE id >= :minid AND lastname = :group';

        $result = query_runner::run_all($queries, 100);

        $this->assertSame('validation_failed', $result->status);
        $this->assertStringContainsString("'Second'", $result->errormessage);
        $this->assertStringContainsString('two columns', $result->errormessage);
    }

    /**
     * The first failing query stops the run and is named in the message.
     *
     * @covers \local_aicharts\local\query_runner::run_all
     */
    public function test_run_all_names_failing_query(): void {
        $ada = $this->getDataGenerator()->create_user(['firstname' => 'Ada', 'lastname' => 'a']);
        $queries = $this->queries($ada->id);
        $queries[1]->sqltext = 'SELECT name, value FROM mdl_config';

        $result = query_runner::run_all($queries, 100);

        $this->assertSame('validation_failed', $result->status);
        $this->assertStringStartsWith("Query 'Second':", $result->errormessage);
        $this->assertStringContainsString('mdl_config', $result->errormessage);
    }

    /**
     * A trend series takes the last column of its single row, or null when there is no row.
     *
     * @covers \local_aicharts\local\query_runner::run_points
     */
    public function test_run_points_takes_last_column_of_single_row(): void {
        $ada = $this->getDataGenerator()->create_user(['firstname' => 'Ada']);
        $queries = [
            (object) ['label' => 'Count', 'sqltext' => "SELECT 'Users' AS what, COUNT(*) AS total FROM {user}", 'params' => '{}'],
            (object) ['label' => 'None', 'sqltext' => 'SELECT id FROM {user} WHERE id > :minid', 'params' => ['minid' => $ada->id]],
        ];

        $result = query_runner::run_points($queries, 100);

        $this->assertSame('ok', $result->status);
        $this->assertSame([['Count' => 3.0, 'None' => null]], $result->rows);
    }

    /**
     * A trend series returning several rows or a non-numeric value is refused by name.
     *
     * @covers \local_aicharts\local\query_runner::run_points
     */
    public function test_run_points_rejects_two_rows_and_text(): void {
        $rows = query_runner::run_points([
            (object) ['label' => 'Many', 'sqltext' => 'SELECT id FROM {user}', 'params' => '{}'],
        ], 100);
        $this->assertSame('validation_failed', $rows->status);
        $this->assertSame("Series 'Many' returned 2 rows; a trend series returns one.", $rows->errormessage);

        $text = query_runner::run_points([
            (object) [
                'label' => 'Text',
                'sqltext' => 'SELECT COUNT(*) AS total, username FROM {user} WHERE id = 1 GROUP BY username',
                'params' => '{}',
            ],
        ], 100);
        $this->assertSame('validation_failed', $text->status);
        $this->assertSame("Series 'Text' did not return a number in its last column.", $text->errormessage);
    }
}
