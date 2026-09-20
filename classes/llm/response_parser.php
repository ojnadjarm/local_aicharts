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

namespace local_aicharts\llm;

use local_aicharts\local\chart_spec;
use moodle_exception;

/**
 * Turns the raw answer text into the fields of a chart definition.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class response_parser {
    /** @var string The answer holds a chart or query definition. */
    public const STATUS_CHART = 'chart';

    /** @var string The answer is out of scope, or is not a definition at all. */
    public const STATUS_REFUSED = 'refused';

    /** @var string Markdown code fence some models wrap the answer in. */
    public const FENCE = '```';

    /** @var int Characters of the request kept as the chart name when the answer names nothing. */
    public const FALLBACK_NAME_LENGTH = 60;

    /**
     * Parse an answer.
     *
     * Anything that is not a JSON object, and any object that does not claim a chart, is a refusal;
     * an object that claims a chart but does not carry one is invalid.
     *
     * @param string $content Raw answer text.
     * @param string $fallbackname Request text used to name the chart when the answer names nothing.
     * @return array ['status' => string] for a refusal, otherwise plus 'name', 'sql', 'params',
     *               'chart' (chart_spec), 'chartjson', 'notes'.
     * @throws moodle_exception When the answer claims a chart but does not match the schema.
     */
    public static function parse(string $content, string $fallbackname = ''): array {
        $decoded = self::unwrap(json_decode(self::strip_fence($content), true));
        if (!is_array($decoded) || !isset($decoded['status']) || $decoded['status'] !== self::STATUS_CHART) {
            return ['status' => self::STATUS_REFUSED];
        }

        $sql = self::string_value($decoded, 'sql');
        if ($sql === '') {
            self::reject('the query is missing');
        }
        if (!isset($decoded['chart']) || !is_array($decoded['chart'])) {
            self::reject('the chart definition is missing');
        }
        $name = self::string_value($decoded, 'name');
        if ($name === '') {
            $name = self::string_value($decoded['chart'], 'title');
        }
        if ($name === '') {
            $name = \core_text::substr(trim($fallbackname), 0, self::FALLBACK_NAME_LENGTH);
        }
        if ($name === '') {
            self::reject('the name is missing');
        }

        return [
            'status' => self::STATUS_CHART,
            'name' => $name,
            'sql' => $sql,
            'params' => self::parse_params($decoded['params'] ?? []),
            'chart' => chart_spec::from_array($decoded['chart']),
            'chartjson' => json_encode($decoded['chart']),
            'notes' => self::string_value($decoded, 'notes'),
        ];
    }

    /**
     * Parse an answer that holds only a chart object.
     *
     * @param string $content Raw answer text.
     * @return chart_spec|null Null when the answer is a refusal or not a JSON object.
     * @throws moodle_exception When the object is not a valid chart definition.
     */
    public static function parse_chart(string $content): ?chart_spec {
        $decoded = json_decode(self::strip_fence($content), true);
        if (!is_array($decoded) || ($decoded['status'] ?? '') === self::STATUS_REFUSED) {
            return null;
        }
        if (isset($decoded['chart']) && is_array($decoded['chart'])) {
            $decoded = $decoded['chart'];
        }
        return chart_spec::from_array($decoded);
    }

    /**
     * Remove a Markdown code fence around the answer.
     *
     * @param string $content Raw answer text.
     * @return string
     */
    protected static function strip_fence(string $content): string {
        $content = trim($content);
        if (!str_starts_with($content, self::FENCE)) {
            return $content;
        }
        $content = preg_replace('/^' . self::FENCE . '[a-z]*\s*/i', '', $content);
        return trim(preg_replace('/' . self::FENCE . '$/', '', trim($content)));
    }

    /**
     * Take the answer out of a wrapper object with a single key, such as the schema name.
     *
     * @param mixed $decoded Decoded answer.
     * @return mixed
     */
    protected static function unwrap($decoded) {
        if (is_array($decoded) && count($decoded) === 1 && !isset($decoded['status'])) {
            $inner = reset($decoded);
            if (is_array($inner) && isset($inner['status'])) {
                return $inner;
            }
        }
        return $decoded;
    }

    /**
     * Validate the query parameters.
     *
     * @param mixed $params Params member of the answer.
     * @return array Scalar value per parameter name.
     */
    protected static function parse_params($params): array {
        if (!is_array($params)) {
            self::reject('"params" must be an object');
        }
        $parsed = [];
        foreach ($params as $name => $value) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                self::reject('"' . $name . '" is not a valid parameter name');
            }
            if (!is_scalar($value) || is_bool($value)) {
                self::reject('parameter "' . $name . '" is not a string or a number');
            }
            $parsed[$name] = $value;
        }
        return $parsed;
    }

    /**
     * Throw with a message naming what is wrong.
     *
     * @param string $detail What made the answer invalid.
     * @throws moodle_exception
     */
    protected static function reject(string $detail): void {
        throw new moodle_exception('invalidresponse', 'local_aicharts', '', $detail);
    }

    /**
     * Read a string member of the answer.
     *
     * @param array $decoded Decoded answer.
     * @param string $key Member name.
     * @return string
     */
    protected static function string_value(array $decoded, string $key): string {
        if (!isset($decoded[$key])) {
            return '';
        }
        if (!is_scalar($decoded[$key])) {
            self::reject('"' . $key . '" must be a string');
        }
        return trim((string) $decoded[$key]);
    }
}
