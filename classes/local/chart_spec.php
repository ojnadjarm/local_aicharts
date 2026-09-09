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

use moodle_exception;

/**
 * Parsed and validated chart definition.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_spec {
    /** @var string[] Chart types the plugin understands. */
    public const TYPES = ['bar', 'line', 'pie', 'table'];

    /** @var string[] Formats applied to the label column. */
    public const LABEL_FORMATS = ['text', 'date', 'month'];

    /**
     * Constructor. Use one of the factory methods instead.
     *
     * @param string $type One of self::TYPES.
     * @param string $title Chart title.
     * @param string $labelcolumn Column holding the labels, empty for a table.
     * @param string $labelformat One of self::LABEL_FORMATS.
     * @param array $series List of ['column' => string, 'label' => string], empty for a table.
     * @param string $xlabel X axis label.
     * @param string $ylabel Y axis label.
     * @param bool $horizontal Draw a bar chart horizontally.
     * @param bool $stacked Stack the series of a bar chart.
     * @param bool $doughnut Draw a pie chart as a doughnut.
     * @param bool $smooth Smooth the lines of a line chart.
     */
    protected function __construct(
        /** @var string One of self::TYPES. */
        public readonly string $type,
        /** @var string Chart title. */
        public readonly string $title,
        /** @var string Column holding the labels, empty for a table. */
        public readonly string $labelcolumn,
        /** @var string One of self::LABEL_FORMATS. */
        public readonly string $labelformat,
        /** @var array List of ['column' => string, 'label' => string]. */
        public readonly array $series,
        /** @var string X axis label. */
        public readonly string $xlabel,
        /** @var string Y axis label. */
        public readonly string $ylabel,
        /** @var bool Draw a bar chart horizontally. */
        public readonly bool $horizontal,
        /** @var bool Stack the series of a bar chart. */
        public readonly bool $stacked,
        /** @var bool Draw a pie chart as a doughnut. */
        public readonly bool $doughnut,
        /** @var bool Smooth the lines of a line chart. */
        public readonly bool $smooth,
    ) {
    }

    /**
     * Build a spec from the chart object of an answer.
     *
     * @param array $chart Decoded chart object.
     * @return self
     */
    public static function from_array(array $chart): self {
        $type = self::string_value($chart, 'type');
        if (!in_array($type, self::TYPES, true)) {
            self::reject('unknown chart type "' . $type . '"');
        }

        $labelformat = self::string_value($chart, 'labelformat', 'text');
        if (!in_array($labelformat, self::LABEL_FORMATS, true)) {
            self::reject('unknown label format "' . $labelformat . '"');
        }

        $labelcolumn = self::string_value($chart, 'labelcolumn');
        $series = self::parse_series($chart['series'] ?? []);

        if ($type !== 'table') {
            if ($labelcolumn === '') {
                self::reject('the label column is missing');
            }
            if (!$series) {
                self::reject('the chart has no series');
            }
        }

        return new self(
            $type,
            self::string_value($chart, 'title'),
            $labelcolumn,
            $labelformat,
            $series,
            self::string_value($chart, 'xlabel'),
            self::string_value($chart, 'ylabel'),
            !empty($chart['horizontal']),
            !empty($chart['stacked']),
            !empty($chart['doughnut']),
            !empty($chart['smooth']),
        );
    }

    /**
     * Build a spec from the stored chart JSON.
     *
     * @param string $json Either the whole answer or its chart object.
     * @return self
     */
    public static function from_json(string $json): self {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            self::reject('the chart definition is not valid JSON');
        }
        if (isset($decoded['chart']) && is_array($decoded['chart'])) {
            $decoded = $decoded['chart'];
        }
        return self::from_array($decoded);
    }

    /**
     * Whether the result is rendered as a table of rows rather than a chart.
     *
     * @return bool
     */
    public function is_table(): bool {
        return $this->type === 'table';
    }

    /**
     * Read a string member of the chart object.
     *
     * @param array $chart Decoded chart object.
     * @param string $key Member name.
     * @param string $default Value when the member is absent.
     * @return string
     */
    protected static function string_value(array $chart, string $key, string $default = ''): string {
        if (!isset($chart[$key])) {
            return $default;
        }
        if (!is_scalar($chart[$key])) {
            self::reject('"' . $key . '" must be a string');
        }
        return trim((string) $chart[$key]);
    }

    /**
     * Validate the series list.
     *
     * @param mixed $series Series member of the chart object.
     * @return array List of ['column' => string, 'label' => string].
     */
    protected static function parse_series($series): array {
        if (!is_array($series)) {
            self::reject('"series" must be a list');
        }
        $parsed = [];
        foreach ($series as $entry) {
            if (!is_array($entry)) {
                self::reject('each series must be an object');
            }
            $column = self::string_value($entry, 'column');
            if ($column === '') {
                self::reject('a series has no column');
            }
            $parsed[] = [
                'column' => $column,
                'label' => self::string_value($entry, 'label', $column) ?: $column,
            ];
        }
        return $parsed;
    }

    /**
     * Throw with a message naming what is wrong.
     *
     * @param string $detail What made the definition invalid.
     * @throws moodle_exception
     */
    protected static function reject(string $detail): void {
        throw new moodle_exception('invalidchartspec', 'local_aicharts', '', $detail);
    }
}
