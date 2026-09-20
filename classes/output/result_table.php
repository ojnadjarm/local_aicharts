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

namespace local_aicharts\output;

use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;

/**
 * Renders query result rows as a table with the first rows inline.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_table implements renderable, templatable {
    /** @var int Columns from this position on are hidden on small screens. */
    private const CAPPEDCOLUMNS = 3;

    /** @var array The result rows, each an object or array keyed by column name. */
    protected array $rows;

    /** @var int How many rows are shown outside the details element. */
    protected int $inlinerows;

    /** @var string Page the preview links to; empty renders the full table with a details element. */
    protected string $viewurl;

    /**
     * Constructor.
     *
     * @param array $rows The result rows.
     * @param int $inlinerows How many rows are shown outside the details element.
     * @param string $viewurl Page a fixed-height preview links to instead of showing every row.
     */
    public function __construct(array $rows, int $inlinerows = 5, string $viewurl = '') {
        $this->rows = $rows;
        $this->inlinerows = $inlinerows;
        $this->viewurl = $viewurl;
    }

    /**
     * Export the rows for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        $rows = array_values($this->rows);
        $preview = $this->viewurl !== '';

        $head = [];
        if ($rows) {
            foreach (array_keys((array) reset($rows)) as $index => $name) {
                $head[] = [
                    'name' => (string) $name,
                    'colcap' => !$preview && $index >= self::CAPPEDCOLUMNS,
                ];
            }
        }

        $body = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach (array_values((array) $row) as $index => $value) {
                $cells[] = [
                    'value' => (string) $value,
                    'colcap' => !$preview && $index >= self::CAPPEDCOLUMNS,
                ];
            }
            $body[] = ['cells' => $cells];
        }

        $rest = $preview ? [] : array_slice($body, $this->inlinerows);

        return [
            'head' => $head,
            'inline' => array_slice($body, 0, $this->inlinerows),
            'rest' => $rest,
            'total' => count($rows),
            'hasrows' => (bool) $rows,
            'hasrest' => (bool) $rest,
            'preview' => $preview,
            'viewurl' => $this->viewurl,
        ];
    }
}
