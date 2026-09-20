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
 * Tests for the SQL validator.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class sql_validator_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('allowedtables', "user\ncourse\nuser_enrolments\nenrol\n", 'local_aicharts');
    }

    /**
     * Message of the exception a query raises, or null when it is accepted.
     *
     * @param string $sql The query.
     * @param array $params Named parameter values.
     * @return string|null
     */
    protected function rejection(string $sql, array $params = []): ?string {
        try {
            sql_validator::validate($sql, $params);
            return null;
        } catch (validation_exception $e) {
            return $e->getMessage();
        }
    }

    /**
     * A plain SELECT with matching parameters passes.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_accepts_single_select(): void {
        $this->assertNull($this->rejection(
            "  select u.id, u.firstname\nFROM {user} u WHERE u.deleted = :deleted AND u.timecreated > :since",
            ['deleted' => 0, 'since' => 1700000000]
        ));
        $this->assertNull($this->rejection("SELECT COUNT(id) AS total FROM {course}"));
    }

    /**
     * UNION and subqueries are fine while every table is allowed.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_accepts_union_and_subquery_on_allowed_tables(): void {
        $this->assertNull($this->rejection(
            "SELECT 'course' AS kind, COUNT(id) AS total FROM {course}
             UNION ALL
             SELECT 'user' AS kind, COUNT(id) AS total FROM {user}
             WHERE id IN (SELECT userid FROM {user_enrolments} WHERE status = :status)",
            ['status' => 0]
        ));
    }

    /**
     * Anything but a SELECT, and write keywords inside one, are rejected naming the keyword.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_rejects_non_select_and_dml(): void {
        $this->assertStringContainsString('must start with SELECT', $this->rejection('DELETE FROM {user}'));
        $this->assertStringContainsString('must start with SELECT', $this->rejection('WITH x AS (SELECT 1) SELECT 1'));
        $this->assertStringContainsString('"DROP"', $this->rejection('SELECT id FROM {user} WHERE name = DROP'));
        $this->assertStringContainsString('";"', $this->rejection('SELECT id FROM {user};'));
        $this->assertStringContainsString('"--"', $this->rejection("SELECT id FROM {user} -- all"));
        $this->assertStringContainsString('"/*"', $this->rejection("SELECT /* x */ id FROM {user}"));
        $this->assertStringContainsString('"INTO"', $this->rejection('SELECT id INTO x FROM {user}'));
        $this->assertStringContainsString('"information_schema"', $this->rejection(
            'SELECT id FROM {user} WHERE username IN (SELECT table_name FROM information_schema.tables)'
        ));
        $this->assertNull($this->rejection('SELECT id, deleted, timecreated FROM {user}'));
    }

    /**
     * The runner appends its own row limit, so LIMIT, OFFSET and FETCH are rejected.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_rejects_limit_offset_fetch(): void {
        $this->assertStringContainsString('"LIMIT"', $this->rejection('SELECT id FROM {user} LIMIT 10'));
        $this->assertStringContainsString('"OFFSET"', $this->rejection('SELECT id FROM {user} OFFSET 10'));
        $this->assertStringContainsString('"FETCH"', $this->rejection('SELECT id FROM {user} FETCH FIRST 10 ROWS ONLY'));
    }

    /**
     * Database-specific functions that sleep, read files or expose system state are rejected.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_rejects_pg_functions_and_sleep(): void {
        $this->assertStringContainsString('"pg_sleep"', $this->rejection('SELECT pg_sleep(10) FROM {user}'));
        $this->assertStringContainsString('"pg_stat_activity"', $this->rejection('SELECT id FROM {user}, pg_stat_activity'));
        $this->assertStringContainsString('"SLEEP"', $this->rejection('SELECT SLEEP(10) FROM {user}'));
        $this->assertStringContainsString('"BENCHMARK"', $this->rejection('SELECT BENCHMARK(1000000, 1) FROM {user}'));
        $this->assertStringContainsString('"LOAD_FILE"', $this->rejection("SELECT LOAD_FILE('/etc/passwd') FROM {user}"));
        $this->assertStringContainsString('"xp_cmdshell"', $this->rejection("SELECT id FROM {user} WHERE xp_cmdshell('x')"));
        $this->assertStringContainsString('"@@"', $this->rejection('SELECT @@version FROM {user}'));
    }

    /**
     * A table outside the allow-list, a prefixed name and a stray brace are rejected naming them.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_rejects_unlisted_table_and_names_it(): void {
        $this->assertStringContainsString('"logstore_standard_log"', $this->rejection(
            'SELECT id FROM {user} u JOIN {logstore_standard_log} l ON l.userid = u.id'
        ));
        $this->assertStringContainsString('"mdl_user"', $this->rejection('SELECT id FROM mdl_user'));
        $this->assertStringContainsString('"user_enrolments"', $this->rejection(
            'SELECT id FROM {user} u LEFT JOIN user_enrolments ue ON ue.userid = u.id'
        ));
        $this->assertStringContainsString('"{"', $this->rejection('SELECT id FROM {User}'));
    }

    /**
     * Derived tables and comma joins are accepted when every item is a placeholder.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_accepts_derived_table_and_comma_join(): void {
        $this->assertNull($this->rejection(
            "SELECT t.courseid, COUNT(t.userid) AS total
             FROM (SELECT e.courseid, ue.userid FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid) t
             GROUP BY t.courseid"
        ));
        $this->assertNull($this->rejection(
            'SELECT u.id, c.id FROM {user} u, {course} c, (SELECT id FROM {enrol}) e WHERE u.id IN (1, 2)'
        ));
        $this->assertStringContainsString('"mdl_course"', $this->rejection(
            'SELECT u.id FROM {user} u, mdl_course c WHERE c.id = 1'
        ));
        $this->assertStringContainsString('"mdl_course"', $this->rejection(
            'SELECT u.id FROM {user} u, (SELECT id FROM mdl_course) c'
        ));
    }

    /**
     * A colon inside a quoted value is reported with the literal, as the DML would read it as a parameter.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_rejects_colon_inside_literal(): void {
        $message = $this->rejection("SELECT id FROM {user} WHERE username = 'it''s:here' AND id = :id", ['id' => 1]);
        $this->assertStringContainsString("'it''s:here'", $message);
        $this->assertStringContainsString('parameter', $message);
        $this->assertNull($this->rejection("SELECT id FROM {user} WHERE username = 'a::b' AND id = :id", ['id' => 1]));
        $this->assertNull($this->rejection("SELECT id FROM {user} WHERE username = '%H:%i'"));
    }

    /**
     * Parameters in the query and the values given must match, and values must be scalar.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_rejects_missing_param(): void {
        $sql = 'SELECT id FROM {user} WHERE id = :userid';
        $this->assertStringContainsString(':userid', $this->rejection($sql));
        $this->assertStringContainsString('"extra"', $this->rejection($sql, ['userid' => 1, 'extra' => 2]));
        $this->assertStringContainsString('"userid"', $this->rejection($sql, ['userid' => [1]]));
        $this->assertNull($this->rejection($sql . ' OR id = :userid', ['userid' => 1]));
    }

    /**
     * A parameter or column called "from" or "join" is not a clause keyword.
     *
     * @covers \local_aicharts\local\sql_validator::validate
     */
    public function test_param_named_from_is_accepted(): void {
        $this->assertNull($this->rejection(
            'SELECT u.id FROM {user} u WHERE u.timecreated > :from AND u.timemodified < :join',
            ['from' => 1, 'join' => 2]
        ));
        $this->assertNull($this->rejection(
            "SELECT c.id FROM {course} c WHERE c.shortname = 'from mdl_course join'"
        ));
    }

    /**
     * A chart may return one label column plus ten series; a table twenty columns.
     *
     * @covers \local_aicharts\local\sql_validator::check_column_count
     */
    public function test_check_column_count(): void {
        sql_validator::check_column_count(11, false);
        sql_validator::check_column_count(20, true);
        $this->expectException(validation_exception::class);
        $this->expectExceptionMessage('12 columns');
        sql_validator::check_column_count(12, false);
    }
}
