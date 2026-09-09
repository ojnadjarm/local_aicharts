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

use coding_exception;
use core\chart_bar;
use core\chart_base;
use core\chart_line;
use core\chart_pie;
use core\chart_series;
use moodle_exception;

/**
 * Builds a core chart from result rows and a chart spec.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_factory {
    /**
     * Build the chart described by the spec.
     *
     * @param chart_spec $spec Parsed chart definition.
     * @param array $rows Result rows, as arrays or objects; values may be strings.
     * @return chart_base
     * @throws moodle_exception When the result is empty or misses a column of the spec.
     */
    public static function create(chart_spec $spec, array $rows): chart_base {
        if ($spec->is_table()) {
            throw new coding_exception('A table spec has no chart; render it with output\result_table.');
        }

        $rows = array_map(fn($row) => (array) $row, array_values($rows));
        if (!$rows) {
            throw new moodle_exception('norows', 'local_aicharts');
        }
        self::require_columns($spec, $rows);

        $chart = match ($spec->type) {
            'bar' => new chart_bar(),
            'line' => new chart_line(),
            'pie' => new chart_pie(),
        };

        $labels = [];
        foreach ($rows as $row) {
            $labels[] = self::format_label($row[$spec->labelcolumn], $spec->labelformat);
        }
        $chart->set_labels($labels);

        foreach ($spec->series as $series) {
            $values = [];
            foreach ($rows as $row) {
                $value = $row[$series['column']];
                $values[] = ($value === null || $value === '') ? null : (float) $value;
            }
            $chart->add_series(new chart_series($series['label'], $values));
        }

        if ($spec->title !== '') {
            $chart->set_title($spec->title);
        }

        if ($chart instanceof chart_bar) {
            $chart->set_horizontal($spec->horizontal);
            $chart->set_stacked($spec->stacked);
        } else if ($chart instanceof chart_line) {
            $chart->set_smooth($spec->smooth);
        } else if ($chart instanceof chart_pie) {
            $chart->set_doughnut($spec->doughnut);
        }

        if (!($chart instanceof chart_pie)) {
            if ($spec->xlabel !== '') {
                $chart->get_xaxis(0, true)->set_label($spec->xlabel);
            }
            if ($spec->ylabel !== '') {
                $chart->get_yaxis(0, true)->set_label($spec->ylabel);
            }
        }

        return $chart;
    }

    /**
     * Check that the rows carry the label and series columns.
     *
     * @param chart_spec $spec Parsed chart definition.
     * @param array $rows Result rows as arrays.
     * @throws moodle_exception
     */
    protected static function require_columns(chart_spec $spec, array $rows): void {
        $columns = array_keys(reset($rows));
        $needed = array_merge([$spec->labelcolumn], array_column($spec->series, 'column'));
        foreach ($needed as $column) {
            if (!in_array($column, $columns, true)) {
                throw new moodle_exception(
                    'invalidchartspec',
                    'local_aicharts',
                    '',
                    'the result has no column "' . $column . '"'
                );
            }
        }
    }

    /**
     * Format one label value.
     *
     * @param mixed $value Raw value from the row.
     * @param string $labelformat One of chart_spec::LABEL_FORMATS.
     * @return string
     */
    protected static function format_label($value, string $labelformat): string {
        if ($labelformat === 'date') {
            return userdate((int) $value, get_string('strftimedaydate'));
        }
        if ($labelformat === 'month') {
            return userdate((int) $value, get_string('strftimemonthyear'));
        }
        return (string) $value;
    }
}
