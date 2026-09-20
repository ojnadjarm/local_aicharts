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
 * Two-way mapping between the chart JSON and the plain controls of the Chart step.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_builder {
    /** @var string[] Options of the chart object that are on or off. */
    public const FLAGS = ['horizontal', 'stacked', 'doughnut', 'smooth'];

    /**
     * The chart JSON of a set of controls.
     *
     * @param array $controls type, title, labelcolumn, labelformat, series (column => label), xlabel, ylabel and the flags.
     * @return string
     */
    public static function from_controls(array $controls): string {
        $type = $controls['type'] ?? '';
        $table = $type === 'table';
        $labelcolumn = $table ? '' : trim((string) ($controls['labelcolumn'] ?? ''));

        $series = [];
        foreach ($table ? [] : ($controls['series'] ?? []) as $column => $label) {
            if ((string) $column === $labelcolumn) {
                continue;
            }
            $label = trim((string) $label);
            $series[] = ['column' => (string) $column, 'label' => $label === '' ? (string) $column : $label];
        }

        $chart = [
            'type' => $type,
            'title' => trim((string) ($controls['title'] ?? '')),
            'labelcolumn' => $labelcolumn,
            'labelformat' => $controls['labelformat'] ?? 'text',
            'series' => $series,
        ];
        foreach (['xlabel', 'ylabel'] as $key) {
            $value = trim((string) ($controls[$key] ?? ''));
            if ($value !== '') {
                $chart[$key] = $value;
            }
        }
        foreach (self::FLAGS as $flag) {
            if (!empty($controls[$flag])) {
                $chart[$flag] = true;
            }
        }
        return json_encode($chart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The controls of a chart JSON.
     *
     * @param string $chartjson Stored chart definition.
     * @return array As from_controls() expects.
     */
    public static function to_controls(string $chartjson): array {
        return self::controls(chart_spec::from_json($chartjson));
    }

    /**
     * The controls of a parsed chart.
     *
     * @param chart_spec $spec Parsed chart definition.
     * @return array As from_controls() expects.
     */
    public static function controls(chart_spec $spec): array {
        $series = [];
        foreach ($spec->series as $entry) {
            $series[$entry['column']] = $entry['label'];
        }
        return [
            'type' => $spec->type,
            'title' => $spec->title,
            'labelcolumn' => $spec->labelcolumn,
            'labelformat' => $spec->labelformat,
            'series' => $series,
            'xlabel' => $spec->xlabel,
            'ylabel' => $spec->ylabel,
            'horizontal' => $spec->horizontal,
            'stacked' => $spec->stacked,
            'doughnut' => $spec->doughnut,
            'smooth' => $spec->smooth,
        ];
    }

    /**
     * The controls a new chart starts with: a bar of every value column, or a line over the run time.
     *
     * @param string[] $columns The columns the Data step produced, the label column first.
     * @param string $kind oneshot or trend.
     * @param array $sample One result row; when given, only its numeric columns become series.
     * @return array As from_controls() expects.
     */
    public static function defaults(array $columns, string $kind, array $sample = []): array {
        $columns = array_values($columns);
        $series = [];
        foreach (array_slice($columns, 1) as $column) {
            if ($sample && array_key_exists($column, $sample) && !is_numeric($sample[$column]) && $sample[$column] !== null) {
                continue;
            }
            $series[$column] = $column;
        }
        $trend = $kind === 'trend';
        return [
            'type' => $trend ? 'line' : 'bar',
            'title' => '',
            'labelcolumn' => $trend ? point_store::TIME_COLUMN : (string) ($columns[0] ?? ''),
            'labelformat' => 'text',
            'series' => $series,
            'xlabel' => '',
            'ylabel' => '',
            'horizontal' => false,
            'stacked' => false,
            'doughnut' => false,
            'smooth' => false,
        ];
    }
}
