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
    /** @var array Schema every answer must match. */
    public const RESPONSE_SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['status'],
        'properties' => [
            'status' => ['type' => 'string', 'enum' => ['chart', 'refused']],
            'name' => ['type' => 'string'],
            'sql' => ['type' => 'string'],
            'params' => ['type' => 'object'],
            'chart' => [
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
            ],
            'notes' => ['type' => 'string'],
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
     * @param string $schemahint Tables or columns the user pointed at.
     * @param string $charthint How the user wants the result drawn.
     * @param string $sqlhint Filters or joins the user wants applied.
     * @return array List of ['role' => string, 'content' => string].
     */
    public static function build_messages(
        string $prompt,
        string $schemahint = '',
        string $charthint = '',
        string $sqlhint = ''
    ): array {
        $messages = [['role' => 'system', 'content' => self::system_prompt()]];

        foreach (chart_repository::find_examples($prompt) as $example) {
            $messages[] = [
                'role' => 'user',
                'content' => self::request_block(
                    $example->prompt,
                    $example->schemahint ?? '',
                    $example->charthint ?? '',
                    $example->sqlhint ?? ''
                ),
            ];
            $messages[] = ['role' => 'assistant', 'content' => self::example_answer($example)];
        }

        $messages[] = [
            'role' => 'user',
            'content' => self::request_block($prompt, $schemahint, $charthint, $sqlhint),
        ];

        return $messages;
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
            "for a chart, list or report from Moodle data, answer exactly {\"status\":\"refused\"}.\n" .
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
            "no explanation.\n" . json_encode(self::RESPONSE_SCHEMA, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
            "- Write every table as a {tablename} placeholder and use only the allowed tables below.\n" .
            "- Pass values as named parameters :name and put each value in \"params\".\n" .
            "- Never write a colon inside a quoted string: ':00' is read as a parameter. Pass such a value " .
            "as a parameter instead.\n" .
            "- Never write LIMIT, OFFSET or FETCH; the server appends its own row limit.\n" .
            "- UNION and subqueries are fine as long as every table is allowed.\n" .
            "- The database family is " . $dbfamily . "; use only functions it supports.\n" .
            "- All timestamps are unix epoch integers. Bucket them portably, for example " .
            "FLOOR(col / 86400) * 86400 for a day.\n" .
            "- The first selected column holds the labels, the following columns hold the series.";
    }

    /**
     * The allowed tables and their relations.
     *
     * @return string
     */
    protected static function tables_section(): string {
        return "Allowed tables and their key columns:\n" . schema_catalogue::hint_block();
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
     * @param string $schemahint Tables or columns the user pointed at.
     * @param string $charthint How the user wants the result drawn.
     * @param string $sqlhint Filters or joins the user wants applied.
     * @return string
     */
    protected static function request_block(
        string $prompt,
        string $schemahint,
        string $charthint,
        string $sqlhint
    ): string {
        $lines = ['Request: ' . trim($prompt)];
        foreach (['Data hint' => $schemahint, 'Chart hint' => $charthint, 'Query hint' => $sqlhint] as $label => $hint) {
            $hint = trim((string) $hint);
            if ($hint !== '') {
                $lines[] = $label . ': ' . $hint;
            }
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
        $params = json_decode((string) $example->params, true);
        $chart = json_decode((string) $example->chartjson, true);

        return json_encode([
            'status' => 'chart',
            'name' => $example->name,
            'sql' => $example->sqltext,
            'params' => (object) (is_array($params) ? $params : []),
            'chart' => is_array($chart) ? $chart : [],
        ], JSON_UNESCAPED_SLASHES);
    }
}
