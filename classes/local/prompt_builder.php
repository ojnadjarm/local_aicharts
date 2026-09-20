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

use stdClass;

/**
 * Builds the messages and the answer schema sent to a client.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class prompt_builder {
    /** @var array Schema the chart object must match. */
    public const CHART_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['type', 'title', 'labelcolumn', 'series'],
        'properties' => [
            'type' => ['type' => 'string', 'enum' => ['bar', 'line', 'pie', 'table']],
            'title' => ['type' => 'string'],
            'labelcolumn' => ['type' => 'string'],
            'labelformat' => ['type' => 'string', 'enum' => ['text', 'date', 'month']],
            'series' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['column', 'label'],
                    'properties' => [
                        'column' => ['type' => 'string'],
                        'label' => ['type' => 'string'],
                    ],
                ],
            ],
            'xlabel' => ['type' => 'string'],
            'ylabel' => ['type' => 'string'],
            'horizontal' => ['type' => 'boolean'],
            'stacked' => ['type' => 'boolean'],
            'doughnut' => ['type' => 'boolean'],
            'smooth' => ['type' => 'boolean'],
        ],
    ];

    /** @var array Schema every answer must match. */
    public const RESPONSE_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['status', 'name', 'sql', 'params', 'chart', 'notes'],
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['chart', 'refused']],
            'name' => ['type' => ['string', 'null']],
            'sql' => ['type' => ['string', 'null']],
            'params' => ['type' => ['object', 'null']],
            'chart' => ['type' => ['object', 'null']] + self::CHART_SCHEMA,
            'notes' => ['type' => ['string', 'null']],
        ],
    ];

    /**
     * Returns the schema an answer must match.
     *
     * @return array
     */
    public static function response_schema(): array {
        return self::RESPONSE_SCHEMA;
    }

    /**
     * Returns the full message list: instructions, example pairs, then the request.
     *
     * @param string $prompt What the user asked for.
     * @param string $sqlhint A starting query the model may reuse or improve.
     * @return array List of ['role' => string, 'content' => string].
     */
    public static function build_messages(string $prompt, string $sqlhint = ''): array {
        $messages = [['role' => 'system', 'content' => self::system_prompt()]];

        foreach (chart_repository::find_examples($prompt) as $example) {
            $messages[] = ['role' => 'user', 'content' => self::request_block($example->prompt, '')];
            $messages[] = ['role' => 'assistant', 'content' => self::example_answer($example)];
        }

        $messages[] = ['role' => 'user', 'content' => self::request_block($prompt, $sqlhint)];

        return $messages;
    }

    /**
     * Returns the messages asking how to draw the known columns of a result.
     *
     * @param string[] $columns The result columns, the label column first.
     * @param array $samplerows A few result rows.
     * @param string $charthint How the user wants the result drawn.
     * @param string $kind oneshot or trend.
     * @return array List of ['role' => string, 'content' => string].
     */
    public static function chart_messages(array $columns, array $samplerows, string $charthint, string $kind): array {
        $system = "You describe how to draw the columns of a query result on a Moodle site.\n" .
            "Return exactly one JSON object matching this schema, and nothing else: no prose, no Markdown fence.\n" .
            json_encode(self::CHART_SCHEMA, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n" .
            self::chart_section() . "\n" .
            "- labelcolumn and every series column must be one of the columns listed in the request.\n" .
            ($kind === 'trend'
                ? "- This is a trend chart: labelcolumn is \"runtime\", labelformat is \"text\" and every other column"
                    . " is a series.\n"
                : '') .
            "- If the request is not about how to draw these columns, answer exactly {\"status\":\"refused\"}.";

        $user = 'Columns: ' . implode(', ', $columns) . "\n" .
            'Sample rows: ' . json_encode(array_values($samplerows), JSON_UNESCAPED_SLASHES);
        $charthint = trim($charthint);
        if ($charthint !== '') {
            $user .= "\nHint: " . $charthint;
        }

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * Returns the instructions sent as the system message.
     *
     * @return string
     */
    public static function system_prompt(): string {
        global $DB;

        $sections = [
            self::role_section(),
            self::json_section(),
            self::sql_section($DB->get_dbfamily()),
            self::tables_section(),
            self::chart_section(),
        ];

        $extra = trim((string) get_config('local_aicharts', 'extrainstructions'));
        if ($extra !== '') {
            $sections[] = $extra;
        }

        return implode("\n\n", $sections);
    }

    /**
     * Role and scope policy.
     *
     * @return string
     */
    protected static function role_section(): string {
        return "You turn a request into a chart or query definition for a Moodle database.\n" .
            "You only produce chart or query definitions for a Moodle database. If the request is not a request " .
            "for a chart, list or report from Moodle data, answer exactly {\"status\":\"refused\",\"name\":null," .
            "\"sql\":null,\"params\":null,\"chart\":null,\"notes\":\"one sentence why\"}.\n" .
            "Answer with chart.type = \"table\" when the user asks for a list or report of rows, or when the result " .
            "is not chartable (more than one text column, no numeric series).";
    }

    /**
     * The answer format.
     *
     * @return string
     */
    protected static function json_section(): string {
        return "Return exactly one JSON object matching this schema, and nothing else: no prose, no Markdown fence, " .
            "no explanation. When status is chart, name, sql and chart must be filled.\n" .
            json_encode(self::RESPONSE_SCHEMA, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The rules a generated statement must follow.
     *
     * @param string $dbfamily Family reported by the database driver.
     * @return string
     */
    protected static function sql_section(string $dbfamily): string {
        return "SQL rules:\n" .
            "- One SELECT statement. No semicolon, no comments, no DDL and no DML.\n" .
            "- Write every table as a {tablename} placeholder. Any Moodle table may be read; the ones below are\n" .
            "  described for you.\n" .
            "- Pass values as named parameters :name and put each value in \"params\".\n" .
            "- Never write a colon inside a quoted string: ':00' is read as a parameter. Pass such a value " .
            "as a parameter instead.\n" .
            "- Never write LIMIT, OFFSET or FETCH; the server appends its own row limit.\n" .
            "- UNION and subqueries are fine.\n" .
            "- The database family is " . $dbfamily . "; use only functions it supports.\n" .
            "- All timestamps are unix epoch integers. Bucket them portably, for example " .
            "FLOOR(col / 86400) * 86400 for a day.\n" .
            "- The first selected column holds the labels, the following columns hold the series.";
    }

    /**
     * The catalogued tables and their relations.
     *
     * @return string
     */
    protected static function tables_section(): string {
        return "Tables and their key columns:\n" . schema_catalogue::hint_block();
    }

    /**
     * The rules the chart object must follow.
     *
     * @return string
     */
    protected static function chart_section(): string {
        return "Chart rules:\n" .
            "- \"type\" is bar, line, pie or table.\n" .
            "- Every series column must be one of the columns the SELECT returns, and so must labelcolumn.\n" .
            "- For a table set labelcolumn to \"\" and series to [].\n" .
            "- Set labelformat to date or month when the label column is a bucketed timestamp.\n" .
            "- \"notes\" is one sentence the user will read, for example the assumption you made.";
    }

    /**
     * Wraps a request and its hints in the delimiters the model is trained on.
     *
     * @param string $prompt What is asked for.
     * @param string $sqlhint A starting query the model may reuse or improve.
     * @return string
     */
    protected static function request_block(string $prompt, string $sqlhint): string {
        $lines = ['Request: ' . trim($prompt)];
        if (trim($sqlhint) !== '') {
            $lines[] = 'Query hint: ' . trim($sqlhint);
        }
        return "<request>\n" . implode("\n", $lines) . "\n</request>";
    }

    /**
     * Rebuilds the answer that produced a saved chart.
     *
     * @param stdClass $example Saved chart record.
     * @return string JSON answer.
     */
    protected static function example_answer(stdClass $example): string {
        $query = $example->queries[0];
        $params = json_decode((string) $query->params, true);
        $chart = json_decode((string) $example->chartjson, true);

        return json_encode([
            'status' => 'chart',
            'name' => $example->name,
            'sql' => $query->sqltext,
            'params' => (object) (is_array($params) ? $params : []),
            'chart' => is_array($chart) ? $chart : [],
        ], JSON_UNESCAPED_SLASHES);
    }
}
