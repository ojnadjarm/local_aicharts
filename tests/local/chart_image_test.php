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
 * Tests for the GD chart image.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chart_image_test extends \advanced_testcase {
    /** @var string The first bytes of every PNG file. */
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    /**
     * Rows of two series over four labels.
     *
     * @return array The rows.
     */
    private function make_rows(): array {
        return [
            ['role' => 'Student', 'users' => '1240', 'active' => '900'],
            ['role' => 'Teacher', 'users' => '86', 'active' => '80'],
            ['role' => 'Manager', 'users' => '12', 'active' => '4'],
            ['role' => 'Guest', 'users' => '3', 'active' => '0'],
        ];
    }

    /**
     * A bar chart is drawn as a PNG of the fixed size.
     *
     * @covers \local_aicharts\local\chart_image::png
     */
    public function test_bar_png_has_expected_size(): void {
        $this->resetAfterTest();

        $spec = chart_spec::from_array([
            'type' => 'bar',
            'title' => 'Users per role',
            'labelcolumn' => 'role',
            'series' => [['column' => 'users', 'label' => 'Users']],
        ]);

        $png = chart_image::png($spec, $this->make_rows());

        $this->assertStringStartsWith(self::SIGNATURE, $png);
        $size = getimagesizefromstring($png);
        $this->assertSame(chart_image::WIDTH, $size[0]);
        $this->assertSame(chart_image::HEIGHT, $size[1]);
        $this->assertLessThan(40000, strlen($png));
    }

    /**
     * Every chart variant produces a PNG.
     *
     * @covers \local_aicharts\local\chart_image::png
     */
    public function test_every_chart_variant_is_drawn(): void {
        $this->resetAfterTest();

        $variants = [
            ['type' => 'bar', 'horizontal' => true],
            ['type' => 'bar', 'stacked' => true],
            ['type' => 'line', 'smooth' => true],
            ['type' => 'pie'],
            ['type' => 'pie', 'doughnut' => true],
        ];

        foreach ($variants as $variant) {
            $spec = chart_spec::from_array($variant + [
                'title' => 'Users per role',
                'labelcolumn' => 'role',
                'series' => [['column' => 'users', 'label' => 'Users'], ['column' => 'active', 'label' => 'Active']],
            ]);

            $this->assertStringStartsWith(self::SIGNATURE, chart_image::png($spec, $this->make_rows()));
        }
    }

    /**
     * A table has no image.
     *
     * @covers \local_aicharts\local\chart_image::png
     */
    public function test_table_returns_null(): void {
        $this->resetAfterTest();

        $spec = chart_spec::from_array(['type' => 'table', 'title' => 'Users']);

        $this->assertNull(chart_image::png($spec, $this->make_rows()));
    }

    /**
     * Rows that do not carry the columns of the spec have no image.
     *
     * @covers \local_aicharts\local\chart_image::png
     */
    public function test_rows_without_the_columns_return_null(): void {
        $this->resetAfterTest();

        $spec = chart_spec::from_array([
            'type' => 'bar',
            'labelcolumn' => 'role',
            'series' => [['column' => 'missing', 'label' => 'Missing']],
        ]);

        $this->assertNull(chart_image::png($spec, $this->make_rows()));
        $this->assertNull(chart_image::png($spec, []));
    }
}
