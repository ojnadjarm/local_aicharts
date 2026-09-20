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

use local_aicharts\llm\client_factory;
use local_aicharts\llm\response_parser;
use moodle_exception;

/**
 * Turns a request into a query, runs it and logs each attempt; a rejected answer gets one corrected retry.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_generator {
    /** @var string[] Statuses that earn one more attempt with the rejection fed back. */
    protected const RETRY_STATUSES = ['invalid_json', 'validation_failed'];

    /** @var string Message sent back to the model with the rejection; %s is the sanitised error. */
    protected const RETRY_FEEDBACK = 'Your answer was rejected: %s Reply again in the same JSON format with a corrected answer.';

    /** @var int Characters of an unreadable answer kept in the log. */
    protected const LOGGED_ANSWER_LENGTH = 300;

    /** @var generation_result[] Results of this request, keyed by the hash of their input. */
    protected static array $results = [];

    /**
     * Generate a chart for a request, reusing the result of an identical request in this process.
     *
     * @param string $prompt What the user asked for.
     * @param string $sqlhint A starting query the model may reuse or improve.
     * @param int $maxrows Rows to return at most.
     * @param int $userid Who asked.
     * @return generation_result
     */
    public static function generate(string $prompt, string $sqlhint, int $maxrows, int $userid): generation_result {
        $maxrows = self::row_limit($maxrows);
        $key = sha1(implode('|', [$prompt, $sqlhint, $maxrows, $userid]));
        if (!isset(self::$results[$key])) {
            $messages = prompt_builder::build_messages($prompt, $sqlhint);
            [$result, $content] = self::attempt($messages, $prompt, $maxrows);
            self::log($result, $prompt, $userid, $content);
            if (in_array($result->status, self::RETRY_STATUSES, true)) {
                $messages[] = ['role' => 'assistant', 'content' => $content];
                $messages[] = ['role' => 'user', 'content' => sprintf(self::RETRY_FEEDBACK, $result->errormessage)];
                [$result, $content] = self::attempt($messages, $prompt, $maxrows);
                self::log($result, $prompt, $userid, $content);
            }
            self::$results[$key] = $result;
        }
        return self::$results[$key];
    }

    /**
     * Ask how to draw the known columns of a result; one attempt, logged without a query.
     *
     * @param string[] $columns The result columns, the label column first.
     * @param array $samplerows A few result rows.
     * @param string $charthint How the user wants the result drawn.
     * @param string $kind oneshot or trend.
     * @param int $userid Who asked.
     * @return generation_result Status chart, refused, invalid_json or llm_error.
     */
    public static function generate_chart(
        array $columns,
        array $samplerows,
        string $charthint,
        string $kind,
        int $userid
    ): generation_result {
        $messages = prompt_builder::chart_messages($columns, $samplerows, $charthint, $kind);
        $response = client_factory::create()->generate($messages, prompt_builder::CHART_SCHEMA);
        if ($response->has_error()) {
            $result = new generation_result(
                'llm_error',
                errormessage: $response->errormessage ?: $response->errorcode,
                errorcode: $response->errorcode,
            );
        } else {
            try {
                $spec = response_parser::parse_chart($response->content);
                $result = new generation_result('refused');
                if ($spec) {
                    $chartjson = chart_builder::from_controls(chart_builder::controls($spec));
                    $result = new generation_result('chart', $spec->title, chartjson: $chartjson);
                }
            } catch (moodle_exception $e) {
                $result = new generation_result('invalid_json', errormessage: $e->getMessage());
            }
        }
        self::log($result, $charthint, $userid, $response->content);
        return $result;
    }

    /**
     * Forget the results of this request.
     */
    public static function reset_caches(): void {
        self::$results = [];
    }

    /**
     * Ask the model, parse its answer and run the query.
     *
     * @param array $messages Conversation to send.
     * @param string $prompt What the user asked for; names the chart when the answer does not.
     * @param int $maxrows Rows to return at most.
     * @return array The generation_result and the raw answer text.
     */
    protected static function attempt(array $messages, string $prompt, int $maxrows): array {
        $response = client_factory::create()->generate($messages, prompt_builder::response_schema());
        if ($response->has_error()) {
            return [new generation_result(
                'llm_error',
                errormessage: $response->errormessage ?: $response->errorcode,
                errorcode: $response->errorcode,
            ), ''];
        }

        try {
            $answer = response_parser::parse($response->content, $prompt);
        } catch (moodle_exception $e) {
            return [new generation_result('invalid_json', errormessage: $e->getMessage()), $response->content];
        }
        if ($answer['status'] === response_parser::STATUS_REFUSED) {
            return [new generation_result('refused'), $response->content];
        }

        $query = query_runner::run($answer['sql'], $answer['params'], $maxrows);
        $status = $query->status;
        $errormessage = $query->errormessage;
        if (!$query->has_error()) {
            $status = $answer['chart']->is_table() ? 'table' : 'chart';
            try {
                if ($query->rows) {
                    $columns = count((array) $query->rows[0]);
                    sql_validator::check_column_count($columns, $answer['chart']->is_table());
                }
            } catch (validation_exception $e) {
                $status = 'validation_failed';
                $errormessage = $e->getMessage();
            }
        }

        $ok = in_array($status, generation_result::OK_STATUSES, true);
        return [new generation_result(
            $status,
            $answer['name'],
            $answer['sql'],
            $answer['params'],
            $answer['chartjson'],
            $ok ? $query->rows : [],
            $ok ? $query->rowcount : 0,
            $query->truncated,
            $query->durationms,
            $errormessage,
            $answer['notes'],
        ), $response->content];
    }

    /**
     * Record the attempt.
     *
     * @param generation_result $result What the attempt produced.
     * @param string $prompt What the user asked for.
     * @param int $userid Who asked.
     * @param string $content Raw answer text, kept after the reason when it could not be read.
     */
    protected static function log(generation_result $result, string $prompt, int $userid, string $content = ''): void {
        global $DB;

        $errormessage = $result->errormessage;
        if ($result->status === 'invalid_json' && $content !== '') {
            $errormessage .= ' Answer: ' . \core_text::substr($content, 0, self::LOGGED_ANSWER_LENGTH);
        }

        $DB->insert_record('local_aicharts_run', (object) [
            'chartid' => null,
            'userid' => $userid,
            'prompt' => $prompt,
            'sqltext' => $result->sql !== '' ? $result->sql : null,
            'status' => $result->has_error() ? $result->status : 'ok',
            'errormessage' => $errormessage !== '' ? $errormessage : null,
            'numrows' => $result->has_error() ? null : $result->rowcount,
            'durationms' => $result->durationms,
            'timecreated' => time(),
        ]);
    }

    /**
     * Rows a generation may return, within the site maximum.
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
}
