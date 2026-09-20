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
use stdClass;

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
            $detail = self::database_error($e);
            $message = self::failure_message($detail, $seconds);
            return query_result::error(
                'db_error',
                $message,
                self::elapsed($start),
                $message === $detail ? '' : $detail
            );
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
     * Run the queries of a chart and return one result, one series per query.
     *
     * A single query passes through run() unchanged. With several, each must return two columns
     * (label, value); the rows are merged on the first column and each value column takes the
     * label of its query.
     *
     * @param stdClass[] $queries Records with label, sqltext and params (JSON object or array).
     * @param int $maxrows Rows each query may return at most.
     * @return query_result
     */
    public static function run_all(array $queries, int $maxrows): query_result {
        $queries = array_values($queries);
        if (!$queries) {
            return query_result::error('validation_failed', get_string('error_noqueries', 'local_aicharts'));
        }
        if (count($queries) === 1) {
            return self::run($queries[0]->sqltext, self::query_params($queries[0]), $maxrows);
        }

        $results = [];
        $truncated = false;
        $durationms = 0;
        foreach ($queries as $query) {
            $result = self::run($query->sqltext, self::query_params($query), $maxrows);
            $durationms += $result->durationms;
            if ($result->has_error()) {
                $a = (object) ['label' => $query->label, 'message' => $result->errormessage];
                return query_result::error(
                    $result->status,
                    get_string('error_queryfailed', 'local_aicharts', $a),
                    $durationms,
                    $result->errordetail
                );
            }
            $rows = $result->rows;
            if ($rows && count((array) reset($rows)) !== 2) {
                return query_result::error(
                    'validation_failed',
                    get_string('error_querycolumns', 'local_aicharts', $query->label),
                    $durationms
                );
            }
            $truncated = $truncated || $result->truncated;
            $results[$query->label] = $rows;
        }

        return query_result::success(self::merge($results), $truncated, $durationms);
    }

    /**
     * Run the queries of a trend chart and return one value per series.
     *
     * Each query must return at most one row; its last column is the value of the series and
     * no row means null. The result holds a single row of values keyed by query label.
     *
     * @param stdClass[] $queries Records with label, sqltext and params (JSON object or array).
     * @param int $maxrows Rows each query may return at most.
     * @return query_result
     */
    public static function run_points(array $queries, int $maxrows): query_result {
        $queries = array_values($queries);
        if (!$queries) {
            return query_result::error('validation_failed', get_string('error_noqueries', 'local_aicharts'));
        }

        $values = [];
        $durationms = 0;
        foreach ($queries as $query) {
            $result = self::run($query->sqltext, self::query_params($query), $maxrows);
            $durationms += $result->durationms;
            if ($result->has_error()) {
                $a = (object) ['label' => $query->label, 'message' => $result->errormessage];
                return query_result::error(
                    $result->status,
                    get_string('error_queryfailed', 'local_aicharts', $a),
                    $durationms,
                    $result->errordetail
                );
            }
            if ($result->rowcount > 1 || $result->truncated) {
                $a = (object) ['label' => $query->label, 'rows' => $result->rowcount + (int) $result->truncated];
                return query_result::error(
                    'validation_failed',
                    get_string('error_trendrows', 'local_aicharts', $a),
                    $durationms
                );
            }
            $row = $result->rows ? (array) $result->rows[0] : [null];
            $value = end($row);
            if ($value !== null && !is_numeric($value)) {
                return query_result::error(
                    'validation_failed',
                    get_string('error_trendvalue', 'local_aicharts', $query->label),
                    $durationms
                );
            }
            $values[$query->label] = $value === null ? null : (float) $value;
        }

        return query_result::success([$values], false, $durationms);
    }

    /**
     * Merge two-column row sets on their first column, one value column per label.
     *
     * @param array $results Rows of each query, keyed by query label, in query order.
     * @return stdClass[] Merged rows in order of first appearance of each key.
     */
    public static function merge(array $results): array {
        $labelcolumn = null;
        $values = [];
        foreach ($results as $label => $rows) {
            foreach ($rows as $row) {
                $row = array_values((array) $row);
                $labelcolumn ??= array_keys((array) reset($rows))[0];
                $key = (string) $row[0];
                $values[$key] ??= [];
                $values[$key][$label] = $row[1];
            }
        }

        $merged = [];
        foreach ($values as $key => $byseries) {
            $row = [$labelcolumn => $key];
            foreach (array_keys($results) as $label) {
                $row[$label] = $byseries[$label] ?? null;
            }
            $merged[] = (object) $row;
        }
        return $merged;
    }

    /**
     * Named parameters of a query record.
     *
     * @param stdClass $query Record with params as a JSON object or an array.
     * @return array
     */
    protected static function query_params(stdClass $query): array {
        $params = $query->params ?? [];
        if (!is_array($params)) {
            $params = json_decode((string) $params, true);
        }
        return is_array($params) ? $params : [];
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
     * @param string $error What the database reported.
     * @param int $seconds The statement timeout in force.
     * @return string Sanitised message, without the query or its parameters.
     */
    protected static function failure_message(string $error, int $seconds): string {
        foreach (self::TIMEOUT_MARKERS as $marker) {
            if (stripos($error, $marker) !== false) {
                return get_string('error_dbtimeout', 'local_aicharts', $seconds);
            }
        }
        return $error;
    }

    /**
     * The first line of what the database reported, never the query or its parameters.
     *
     * @param dml_exception $e The failure.
     * @return string
     */
    protected static function database_error(dml_exception $e): string {
        $error = $e instanceof dml_read_exception ? (string) $e->error : $e->getMessage();
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
