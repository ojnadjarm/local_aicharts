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

/**
 * Tests for the result table renderable.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_table_test extends \advanced_testcase {
    /**
     * Build result rows with the given number of rows and columns.
     *
     * @param int $rowcount How many rows to build.
     * @param int $columncount How many columns each row has.
     * @return array The rows.
     */
    private function make_rows(int $rowcount, int $columncount = 2): array {
        $rows = [];
        for ($i = 1; $i <= $rowcount; $i++) {
            $row = new \stdClass();
            for ($c = 1; $c <= $columncount; $c++) {
                $row->{'col' . $c} = 'r' . $i . 'c' . $c;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * The first rows are exported inline and the remainder separately.
     *
     * @covers \local_aicharts\output\result_table::export_for_template
     */
    public function test_exports_first_rows_and_rest(): void {
        global $PAGE;
        $this->resetAfterTest();

        $table = new result_table($this->make_rows(7));
        $context = $table->export_for_template($PAGE->get_renderer('core'));

        $this->assertSame(['col1', 'col2'], array_column($context['head'], 'name'));
        $this->assertCount(5, $context['inline']);
        $this->assertCount(2, $context['rest']);
        $this->assertSame(7, $context['total']);
        $this->assertTrue($context['hasrest']);
        $this->assertSame('r1c1', $context['inline'][0]['cells'][0]['value']);
        $this->assertSame('r6c1', $context['rest'][0]['cells'][0]['value']);
    }

    /**
     * A result within the inline limit renders no details element.
     *
     * @covers \local_aicharts\output\result_table::export_for_template
     */
    public function test_small_result_has_no_details(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render(new result_table($this->make_rows(3)));

        $this->assertStringNotContainsString('<details>', $html);
        $this->assertStringContainsString('r3c1', $html);
    }

    /**
     * Columns beyond the third are flagged so the template hides them on small screens.
     *
     * @covers \local_aicharts\output\result_table::export_for_template
     */
    public function test_columns_beyond_third_are_capped(): void {
        global $PAGE;
        $this->resetAfterTest();

        $table = new result_table($this->make_rows(1, 5));
        $context = $table->export_for_template($PAGE->get_renderer('core'));

        $this->assertSame([false, false, false, true, true], array_column($context['head'], 'colcap'));
        $this->assertSame([false, false, false, true, true], array_column($context['inline'][0]['cells'], 'colcap'));
    }
}
