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
 * Checks a generated query before it reaches the database.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql_validator {
    /** @var int Most series a chart may draw. */
    public const MAX_SERIES = 10;

    /** @var int Most columns a table result may have. */
    public const MAX_COLUMNS = 20;

    /** @var string Character sequences never allowed in a query. */
    protected const FORBIDDEN_SEQUENCES = ['@@', ';', '--', '/*'];

    /** @var string Whole words never allowed in a query. */
    protected const FORBIDDEN_WORDS = 'insert|update|delete|drop|alter|create|truncate|grant|revoke|into|exec|call|lock|'
        . 'limit|offset|fetch|sleep|benchmark|load_file|information_schema|pg_\w+|xp_\w+';

    /** @var string A table placeholder as the DML rewrites it. */
    protected const PLACEHOLDER = '\{([a-z][a-z0-9_]*)\}';

    /** @var string A named parameter as the DML collects it. */
    protected const NAMED_PARAM = '(?<!:):[a-z][a-z0-9_]*';

    /**
     * Validate a query and its parameters.
     *
     * @param string $sql The query, tables written as {name} placeholders.
     * @param array $params Named parameter values.
     * @throws validation_exception When a rule is broken; the message names the offending part.
     */
    public static function validate(string $sql, array $params): void {
        $sql = trim($sql);
        if (!preg_match('/^select\b/i', $sql)) {
            throw new validation_exception('sqlnotselect');
        }
        self::check_forbidden($sql);
        self::check_braces($sql);
        self::check_from_targets($sql);
        self::check_params($sql, $params);
    }

    /**
     * Check the number of columns a query returned.
     *
     * @param int $count Columns in the result.
     * @param bool $istable Whether the result is shown as a table rather than a chart.
     * @throws validation_exception When there are more columns than allowed.
     */
    public static function check_column_count(int $count, bool $istable): void {
        $max = $istable ? self::MAX_COLUMNS : 1 + self::MAX_SERIES;
        if ($count > $max) {
            throw new validation_exception('sqltoomanycolumns', (object) ['count' => $count, 'max' => $max]);
        }
    }

    /**
     * Reject statement separators, comments and write or system keywords.
     *
     * @param string $sql The query.
     */
    protected static function check_forbidden(string $sql): void {
        foreach (self::FORBIDDEN_SEQUENCES as $sequence) {
            if (strpos($sql, $sequence) !== false) {
                throw new validation_exception('sqlforbidden', $sequence);
            }
        }
        if (preg_match('/\b(' . self::FORBIDDEN_WORDS . ')\b/i', $sql, $match)) {
            throw new validation_exception('sqlforbidden', $match[1]);
        }
    }

    /**
     * No brace may appear outside a table placeholder.
     *
     * @param string $sql The query.
     */
    protected static function check_braces(string $sql): void {
        $stripped = preg_replace('/' . self::PLACEHOLDER . '/', '', $sql);
        if (strpbrk($stripped, '{}') !== false) {
            throw new validation_exception('sqlforbidden', $stripped[strcspn($stripped, '{}')]);
        }
    }

    /**
     * Every FROM or JOIN target must be a placeholder or a derived table.
     *
     * A FROM list may hold several comma-separated targets; a JOIN holds one.
     * Quoted values are masked and a keyword after ':' or '.' is a parameter or a column, not a clause.
     *
     * @param string $sql The query.
     */
    protected static function check_from_targets(string $sql): void {
        $sql = self::mask_literals($sql);
        $offset = 0;
        while (preg_match('/(?<![:.])\b(from|join)\b/i', $sql, $match, PREG_OFFSET_CAPTURE, $offset)) {
            $pos = $match[0][1] + strlen($match[0][0]);
            $offset = $pos;
            $islist = strtolower($match[1][0]) === 'from';
            do {
                $pos += strspn($sql, " \t\r\n", $pos);
                $pos = self::skip_target($sql, $pos);
            } while ($islist && self::skip_alias_and_comma($sql, $pos));
        }
    }

    /**
     * Move past a table target, which is a placeholder or a parenthesised derived table.
     *
     * @param string $sql The query.
     * @param int $pos Offset where the target starts.
     * @return int Offset after the target.
     */
    protected static function skip_target(string $sql, int $pos): int {
        if (preg_match('/\G' . self::PLACEHOLDER . '/', $sql, $match, 0, $pos)) {
            return $pos + strlen($match[0]);
        }
        if (($sql[$pos] ?? '') === '(') {
            $depth = 0;
            $length = strlen($sql);
            for ($i = $pos; $i < $length; $i++) {
                if ($sql[$i] === '(') {
                    $depth++;
                } else if ($sql[$i] === ')' && --$depth === 0) {
                    return $i + 1;
                }
            }
        }
        preg_match('/\G[^\s,()]*/', $sql, $match, 0, $pos);
        throw new validation_exception('sqltablenotplaceholder', $match[0] === '' ? '(' : $match[0]);
    }

    /**
     * Move past an optional alias followed by a comma, when there is one.
     *
     * @param string $sql The query.
     * @param int $pos Offset after a target; advanced past the comma on success.
     * @return bool Whether a comma followed and another target is expected.
     */
    protected static function skip_alias_and_comma(string $sql, int &$pos): bool {
        if (preg_match('/\G\s*(?:as\s+)?(?:[a-z_][a-z0-9_]*\s*)?,/i', $sql, $match, 0, $pos)) {
            $pos += strlen($match[0]);
            return true;
        }
        return false;
    }

    /**
     * Named parameters in the query and the values given must match one to one.
     *
     * @param string $sql The query.
     * @param array $params Named parameter values.
     */
    protected static function check_params(string $sql, array $params): void {
        $literals = self::literal_ranges($sql);
        $names = [];
        preg_match_all('/' . self::NAMED_PARAM . '/', $sql, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$match, $position]) {
            foreach ($literals as [$start, $end]) {
                if ($position > $start && $position < $end) {
                    throw new validation_exception('sqlcoloninliteral', substr($sql, $start, $end - $start + 1));
                }
            }
            $names[substr($match, 1)] = true;
        }
        foreach (array_keys($names) as $name) {
            if (!array_key_exists($name, $params)) {
                throw new validation_exception('sqlparammissing', $name);
            }
        }
        foreach ($params as $name => $value) {
            if (!isset($names[$name])) {
                throw new validation_exception('sqlparamunused', $name);
            }
            if (!is_scalar($value)) {
                throw new validation_exception('sqlparamnotscalar', $name);
            }
        }
    }

    /**
     * Replace the contents of every single-quoted literal with filler of the same length.
     *
     * @param string $sql The query.
     * @return string The query with literal contents masked, offsets unchanged.
     */
    protected static function mask_literals(string $sql): string {
        return preg_replace_callback("/'(?:[^']|'')*'/", function (array $match): string {
            return "'" . str_repeat('x', strlen($match[0]) - 2) . "'";
        }, $sql);
    }

    /**
     * Offsets of the opening and closing quote of every single-quoted literal.
     *
     * @param string $sql The query.
     * @return int[][] List of [start, end] pairs.
     */
    protected static function literal_ranges(string $sql): array {
        $ranges = [];
        preg_match_all("/'(?:[^']|'')*'/", $sql, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$literal, $start]) {
            $ranges[] = [$start, $start + strlen($literal) - 1];
        }
        return $ranges;
    }
}
