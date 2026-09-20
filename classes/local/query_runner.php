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

use dml_exception;
use dml_read_exception;

/**
 * Runs a validated query under a row limit and a session statement timeout.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_runner {
    /** @var string[] Driver messages that mean the statement timeout stopped the query. */
    protected const TIMEOUT_MARKERS = ['statement timeout', 'max_statement_time', 'execution time exceeded'];

    /**
     * Validate and run a query, returning its rows or the reason there are none.
     *
     * @param string $sql The query, tables written as {name} placeholders.
     * @param array $params Named parameter values.
     * @param int $maxrows Rows to return at most; capped by the maxrowsmax setting.
     * @return query_result
     */
    public static function run(string $sql, array $params, int $maxrows): query_result {
        global $DB;

        try {
            sql_validator::validate($sql, $params);
        } catch (validation_exception $e) {
            return query_result::error('validation_failed', $e->getMessage());
        }

        $maxrows = self::row_limit($maxrows);
        $seconds = (int) get_config('local_aicharts', 'querytimeout');
        $start = microtime(true);
        self::set_timeout($seconds);
        try {
            $rows = [];
            $recordset = $DB->get_recordset_sql($sql, $params, 0, $maxrows + 1);
            foreach ($recordset as $row) {
                $rows[] = $row;
            }
            $recordset->close();
        } catch (dml_exception $e) {
            return query_result::error('db_error', self::failure_message($e, $seconds), self::elapsed($start));
        } finally {
            self::set_timeout(0);
        }

        $truncated = count($rows) > $maxrows;
        if ($truncated) {
            array_pop($rows);
        }
        return query_result::success($rows, $truncated, self::elapsed($start));
    }

    /**
     * Rows a run may return, within the site maximum.
     *
     * @param int $maxrows Requested limit.
     * @return int
     */
    protected static function row_limit(int $maxrows): int {
        $sitemax = (int) get_config('local_aicharts', 'maxrowsmax');
        if ($sitemax > 0) {
            $maxrows = min($maxrows, $sitemax);
        }
        return max(1, $maxrows);
    }

    /**
     * Apply the session statement timeout, or remove it when the number of seconds is zero.
     *
     * The value is inlined because PostgreSQL rejects a bound parameter in SET.
     *
     * @param int $seconds Seconds after which the database stops a statement.
     */
    protected static function set_timeout(int $seconds): void {
        global $DB;

        $seconds = max(0, $seconds);
        if ($DB->get_dbfamily() === 'postgres') {
            $DB->execute('SET statement_timeout = ' . ($seconds * 1000));
        } else if ($DB->get_dbfamily() === 'mysql') {
            if ($DB->get_dbvendor() === 'mariadb') {
                $DB->execute('SET SESSION max_statement_time = ' . $seconds);
            } else {
                $DB->execute('SET SESSION max_execution_time = ' . ($seconds * 1000));
            }
        }
    }

    /**
     * Message for a failed query, naming the timeout when that is what stopped it.
     *
     * @param dml_exception $e The database failure.
     * @param int $seconds The statement timeout in force.
     * @return string Sanitised message, without the query or its parameters.
     */
    protected static function failure_message(dml_exception $e, int $seconds): string {
        $error = $e instanceof dml_read_exception ? (string) $e->error : $e->getMessage();
        foreach (self::TIMEOUT_MARKERS as $marker) {
            if (stripos($error, $marker) !== false) {
                return get_string('error_dbtimeout', 'local_aicharts', $seconds);
            }
        }
        return trim(strtok($error, "\n"));
    }

    /**
     * Milliseconds since a start time.
     *
     * @param float $start Value of microtime(true) when the query started.
     * @return int
     */
    protected static function elapsed(float $start): int {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
