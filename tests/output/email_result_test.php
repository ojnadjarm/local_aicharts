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
 * Tests for the message body of a scheduled result.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class email_result_test extends \advanced_testcase {
    /**
     * A chart record.
     *
     * @return \stdClass The chart.
     */
    private function make_chart(): \stdClass {
        return (object) ['id' => 7, 'name' => 'Users per role'];
    }

    /**
     * A result record.
     *
     * @param string $status ok or db_error.
     * @param int $numrows How many rows the run returned.
     * @return \stdClass The result.
     */
    private function make_result(string $status = 'ok', int $numrows = 12): \stdClass {
        return (object) [
            'id' => 3,
            'chartid' => 7,
            'status' => $status,
            'numrows' => $numrows,
            'truncated' => 0,
            'errormessage' => $status === 'ok' ? null : 'The query failed.',
            'timecreated' => 1757311200,
        ];
    }

    /**
     * Rows keyed by column name.
     *
     * @param int $count How many rows to build.
     * @return array The rows.
     */
    private function make_rows(int $count): array {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = ['role' => 'role' . $i, 'users' => (string) $i];
        }
        return $rows;
    }

    /**
     * A successful run exports the image, the first ten rows and the links.
     *
     * @covers \local_aicharts\output\email_result::export_for_template
     */
    public function test_export_with_image_and_table(): void {
        global $PAGE;
        $this->resetAfterTest();

        $body = new email_result($this->make_chart(), $this->make_result(), $this->make_rows(12), 'PNGBYTES', true);
        $context = $body->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($context['hasimage']);
        $this->assertSame(base64_encode('PNGBYTES'), $context['imagedata']);
        $this->assertTrue($context['hasrows']);
        $this->assertCount(email_result::INLINEROWS, $context['rows']);
        $this->assertTrue($context['hasmore']);
        $this->assertSame(2, $context['morerows']);
        $this->assertTrue($context['hasattachment']);
        $this->assertStringContainsString('id=7', $context['historyurl']);
        $this->assertStringContainsString('resultid=3', $context['historyurl']);
        $this->assertStringContainsString('12', $context['runline']);
    }

    /**
     * A failed run exports the error instead of the result.
     *
     * @covers \local_aicharts\output\email_result::export_for_template
     */
    public function test_export_of_a_failed_run(): void {
        global $PAGE;
        $this->resetAfterTest();

        $body = new email_result($this->make_chart(), $this->make_result('db_error', 0));
        $context = $body->export_for_template($PAGE->get_renderer('core'));

        $this->assertTrue($context['failed']);
        $this->assertFalse($context['hasimage']);
        $this->assertFalse($context['hasrows']);
        $this->assertSame('The query failed.', $context['errormessage']);
    }

    /**
     * The template renders the chart variant with the image and the table variant without it.
     *
     * @covers \local_aicharts\output\email_result::export_for_template
     */
    public function test_template_renders_both_variants(): void {
        global $PAGE;
        $this->resetAfterTest();

        $output = $PAGE->get_renderer('core');

        $withimage = new email_result($this->make_chart(), $this->make_result(), $this->make_rows(12), 'PNGBYTES', true);
        $html = $output->render_from_template('local_aicharts/email_result', $withimage->export_for_template($output));
        $this->assertStringContainsString('data:image/png;base64,' . base64_encode('PNGBYTES'), $html);
        $this->assertStringContainsString('Users per role', $html);
        $this->assertStringContainsString('role1', $html);

        $table = new email_result($this->make_chart(), $this->make_result(), $this->make_rows(3));
        $html = $output->render_from_template('local_aicharts/email_result', $table->export_for_template($output));
        $this->assertStringNotContainsString('data:image/png', $html);
        $this->assertStringContainsString('role3', $html);
    }

    /**
     * The plain text variant carries the rows, the link and the footer.
     *
     * @covers \local_aicharts\output\email_result::plain_text
     */
    public function test_plain_text_carries_rows_and_footer(): void {
        $this->resetAfterTest();

        $body = new email_result($this->make_chart(), $this->make_result(), $this->make_rows(12), null, true);
        $text = $body->plain_text();

        $this->assertStringContainsString('Users per role', $text);
        $this->assertStringContainsString('role: role1', $text);
        $this->assertStringNotContainsString('role: role11', $text);
        $this->assertStringContainsString(get_string('emailmorerows', 'local_aicharts', 2), $text);
        $this->assertStringContainsString('/local/aicharts/history.php', $text);
        $this->assertStringContainsString(get_string('emailattached', 'local_aicharts'), $text);
        $this->assertStringContainsString(get_string('emailfooter', 'local_aicharts'), $text);
    }
}
