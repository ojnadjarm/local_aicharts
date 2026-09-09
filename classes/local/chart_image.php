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

use core\chart_line;
use core\chart_pie;
use GdImage;
use moodle_exception;

/**
 * Draws the result of a chart as a PNG image for the result email.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_image {
    /** @var int Image width. */
    public const WIDTH = 640;

    /** @var int Image height. */
    public const HEIGHT = 320;

    /** @var int Series drawn at most. */
    protected const MAXSERIES = 7;

    /** @var int Legend entries drawn at most. */
    protected const MAXLEGEND = 8;

    /** @var string[] The colours core assigns to chart series. */
    protected const COLOURS = [
        '#f3c300', '#875692', '#f38400', '#a1caf1', '#be0032', '#c2b280', '#7f180d', '#008856',
        '#e68fac', '#0067a5',
    ];

    /** @var string Text colour. */
    protected const TEXTCOLOUR = '#1d2125';

    /** @var string Muted text and grid colour. */
    protected const MUTEDCOLOUR = '#6a737b';

    /** @var string Grid line colour. */
    protected const GRIDCOLOUR = '#dee2e6';

    /**
     * Render the rows of a result as a PNG.
     *
     * @param chart_spec $spec Parsed chart definition.
     * @param array $rows Result rows.
     * @return string|null PNG bytes, null for a table, without GD or when the rows do not fit the spec.
     */
    public static function png(chart_spec $spec, array $rows): ?string {
        if ($spec->is_table() || !$rows || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        try {
            $chart = chart_factory::create($spec, $rows);
        } catch (moodle_exception $e) {
            return null;
        }

        $labels = array_values($chart->get_labels());
        $series = array_slice(array_values($chart->get_series()), 0, self::MAXSERIES);
        if (!$labels || !$series) {
            return null;
        }

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagefilledrectangle($image, 0, 0, self::WIDTH, self::HEIGHT, self::colour($image, '#ffffff'));

        $top = 18;
        if ($spec->title !== '') {
            self::text($image, self::clip($spec->title, 60), 16, $top + 8, 11, self::TEXTCOLOUR);
            $top = 46;
        }

        if ($chart instanceof chart_pie) {
            self::draw_pie($image, $labels, $series[0]->get_values(), $spec->doughnut, $top);
        } else {
            self::draw_cartesian($image, $labels, $series, $top, $chart instanceof chart_line, $spec);
        }

        imagetruecolortopalette($image, false, 255);
        ob_start();
        imagepng($image, null, 9);
        $png = ob_get_clean();

        return $png ?: null;
    }

    /**
     * Draw a bar or line chart with both axes.
     *
     * @param GdImage $image Image being drawn.
     * @param string[] $labels Category labels.
     * @param array $series Chart series.
     * @param int $top Top of the plotting area.
     * @param bool $line Draw lines instead of bars.
     * @param chart_spec $spec Parsed chart definition.
     * @return void
     */
    protected static function draw_cartesian(
        GdImage $image,
        array $labels,
        array $series,
        int $top,
        bool $line,
        chart_spec $spec
    ): void {
        $horizontal = !$line && $spec->horizontal;
        $stacked = !$line && !$horizontal && $spec->stacked;

        $legendwidth = count($series) > 1 ? 132 : 0;
        $left = $horizontal ? 96 : 60;
        $right = self::WIDTH - 20 - $legendwidth;
        $bottom = self::HEIGHT - 36;

        [$min, $max, $step] = self::range($series, $stacked);
        $valuesize = $horizontal ? $right - $left : $bottom - $top;
        $scale = fn(float $value): int => (int) round(($value - $min) / ($max - $min) * $valuesize);

        for ($value = $min; $value <= $max + $step / 2; $value += $step) {
            $offset = $scale($value);
            if ($horizontal) {
                imageline($image, $left + $offset, $top, $left + $offset, $bottom, self::colour($image, self::GRIDCOLOUR));
                self::text($image, self::format_value($value), $left + $offset, $bottom + 16, 8, self::MUTEDCOLOUR, 'centre');
            } else {
                imageline($image, $left, $bottom - $offset, $right, $bottom - $offset, self::colour($image, self::GRIDCOLOUR));
                self::text($image, self::format_value($value), $left - 6, $bottom - $offset + 4, 8, self::MUTEDCOLOUR, 'right');
            }
        }

        $slot = ($horizontal ? $bottom - $top : $right - $left) / count($labels);
        $barwidth = max(2, (int) round($slot * 0.7 / ($stacked ? 1 : count($series))));
        $every = (int) ceil(count($labels) / ($horizontal ? 12 : 10));

        foreach ($labels as $index => $label) {
            $centre = (int) round(($horizontal ? $top : $left) + $slot * ($index + 0.5));
            if ($index % $every === 0) {
                if ($horizontal) {
                    self::text($image, self::clip($label, 14), $left - 6, $centre + 4, 8, self::MUTEDCOLOUR, 'right');
                } else {
                    self::text($image, self::clip($label, 10), $centre, $bottom + 16, 8, self::MUTEDCOLOUR, 'centre');
                }
            }

            if ($line) {
                continue;
            }

            $stackbase = 0.0;
            foreach ($series as $position => $one) {
                $values = array_values($one->get_values());
                $value = (float) ($values[$index] ?? 0);
                $colour = self::colour($image, self::COLOURS[$position % count(self::COLOURS)]);
                $offset = $stacked ? $centre : (int) round($centre - $slot * 0.35 + $barwidth * ($position + 0.5));
                if ($horizontal) {
                    imagefilledrectangle(
                        $image,
                        $left + $scale(0.0),
                        (int) round($offset - $barwidth / 2),
                        $left + $scale($value),
                        (int) round($offset + $barwidth / 2),
                        $colour
                    );
                } else {
                    imagefilledrectangle(
                        $image,
                        (int) round($offset - $barwidth / 2),
                        $bottom - $scale($stacked ? $stackbase + $value : $value),
                        (int) round($offset + $barwidth / 2),
                        $bottom - $scale($stacked ? $stackbase : 0.0),
                        $colour
                    );
                }
                $stackbase += $value;
            }
        }

        if ($line) {
            imagesetthickness($image, 2);
            foreach ($series as $position => $one) {
                $values = array_values($one->get_values());
                $colour = self::colour($image, self::COLOURS[$position % count(self::COLOURS)]);
                $previous = null;
                foreach ($labels as $index => $unused) {
                    $x = (int) round($left + $slot * ($index + 0.5));
                    $y = $bottom - $scale((float) ($values[$index] ?? 0));
                    if ($previous !== null) {
                        imageline($image, $previous[0], $previous[1], $x, $y, $colour);
                    }
                    imagefilledrectangle($image, $x - 2, $y - 2, $x + 2, $y + 2, $colour);
                    $previous = [$x, $y];
                }
            }
            imagesetthickness($image, 1);
        }

        imageline($image, $left, $top, $left, $bottom, self::colour($image, self::MUTEDCOLOUR));
        imageline($image, $left, $bottom, $right, $bottom, self::colour($image, self::MUTEDCOLOUR));

        if ($legendwidth) {
            $entries = [];
            foreach ($series as $position => $one) {
                $entries[] = [self::clip($one->get_label(), 16), self::COLOURS[$position % count(self::COLOURS)]];
            }
            self::draw_legend($image, $entries, $right + 16, $top);
        }
    }

    /**
     * Draw a pie or doughnut chart with a legend.
     *
     * @param GdImage $image Image being drawn.
     * @param string[] $labels Slice labels.
     * @param array $values Slice values.
     * @param bool $doughnut Cut the centre out.
     * @param int $top Top of the plotting area.
     * @return void
     */
    protected static function draw_pie(GdImage $image, array $labels, array $values, bool $doughnut, int $top): void {
        $values = array_map(fn($value) => max(0.0, (float) $value), array_values($values));
        $total = array_sum($values);
        if ($total <= 0) {
            return;
        }

        $bottom = self::HEIGHT - 20;
        $right = self::WIDTH - 20 - 132;
        $size = min($right - 20, $bottom - $top) - 10;
        $centrex = (int) round((20 + $right) / 2);
        $centrey = (int) round(($top + $bottom) / 2);

        $angle = 0.0;
        $entries = [];
        foreach ($values as $index => $value) {
            $hex = self::COLOURS[$index % count(self::COLOURS)];
            $end = $angle + $value / $total * 360;
            imagefilledarc(
                $image,
                $centrex,
                $centrey,
                $size,
                $size,
                (int) round($angle),
                (int) round($end),
                self::colour($image, $hex),
                IMG_ARC_PIE
            );
            $angle = $end;
            $entries[] = [self::clip((string) ($labels[$index] ?? ''), 16), $hex];
        }

        if ($doughnut) {
            imagefilledarc(
                $image,
                $centrex,
                $centrey,
                (int) round($size / 2),
                (int) round($size / 2),
                0,
                360,
                self::colour($image, '#ffffff'),
                IMG_ARC_PIE
            );
        }

        self::draw_legend($image, $entries, $right + 16, $top);
    }

    /**
     * Draw a colour swatch and a label for each entry.
     *
     * @param GdImage $image Image being drawn.
     * @param array $entries List of [label, hex colour].
     * @param int $x Left of the legend.
     * @param int $top Top of the legend.
     * @return void
     */
    protected static function draw_legend(GdImage $image, array $entries, int $x, int $top): void {
        foreach (array_slice($entries, 0, self::MAXLEGEND) as $index => $entry) {
            $y = $top + $index * 20;
            imagefilledrectangle($image, $x, $y, $x + 10, $y + 10, self::colour($image, $entry[1]));
            self::text($image, $entry[0], $x + 16, $y + 9, 8, self::TEXTCOLOUR);
        }
    }

    /**
     * Work out the value axis bounds and the distance between grid lines.
     *
     * @param array $series Chart series.
     * @param bool $stacked Add the series values up.
     * @return array [minimum, maximum, step]
     */
    protected static function range(array $series, bool $stacked): array {
        $totals = [];
        foreach ($series as $one) {
            foreach (array_values($one->get_values()) as $index => $value) {
                if ($stacked) {
                    $totals[$index] = ($totals[$index] ?? 0) + (float) $value;
                } else {
                    $totals[] = (float) $value;
                }
            }
        }

        $max = $totals ? max($totals) : 0.0;
        $min = min(0.0, $totals ? min($totals) : 0.0);
        if ($max <= $min) {
            $max = $min + 1;
        }

        $step = self::nice_step(($max - $min) / 4);

        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    /**
     * Round a rough grid line distance up to a readable one.
     *
     * @param float $span Rough distance between two grid lines.
     * @return float
     */
    protected static function nice_step(float $span): float {
        $magnitude = pow(10, floor(log10(max($span, 1e-9))));
        foreach ([1, 2, 2.5, 5] as $multiple) {
            if ($span <= $magnitude * $multiple) {
                return $magnitude * $multiple;
            }
        }
        return $magnitude * 10;
    }

    /**
     * Format one axis value.
     *
     * @param float $value The value.
     * @return string
     */
    protected static function format_value(float $value): string {
        if (abs($value - round($value)) < 0.001) {
            return (string) (int) round($value);
        }
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /**
     * Shorten a label that does not fit.
     *
     * @param string $text The label.
     * @param int $length How many characters fit.
     * @return string
     */
    protected static function clip(string $text, int $length): string {
        return \core_text::strlen($text) > $length ? \core_text::substr($text, 0, $length - 1) . '…' : $text;
    }

    /**
     * Allocate a colour given as a hex string.
     *
     * @param GdImage $image Image being drawn.
     * @param string $hex Colour as #rrggbb.
     * @return int
     */
    protected static function colour(GdImage $image, string $hex): int {
        [$red, $green, $blue] = sscanf($hex, '#%02x%02x%02x');
        return imagecolorallocate($image, $red, $green, $blue);
    }

    /**
     * Write one line of text, the given point being its baseline.
     *
     * @param GdImage $image Image being drawn.
     * @param string $text The text.
     * @param int $x Horizontal position of the alignment point.
     * @param int $y Baseline.
     * @param int $size Font size.
     * @param string $colour Colour as #rrggbb.
     * @param string $align left, right or centre.
     * @return void
     */
    protected static function text(
        GdImage $image,
        string $text,
        int $x,
        int $y,
        int $size,
        string $colour,
        string $align = 'left'
    ): void {
        $font = self::font();
        if ($font) {
            $box = imagettfbbox($size, 0, $font, $text);
            $width = $box[2] - $box[0];
        } else {
            $width = \core_text::strlen($text) * imagefontwidth(2);
        }

        if ($align === 'right') {
            $x -= $width;
        } else if ($align === 'centre') {
            $x -= (int) round($width / 2);
        }

        if ($font) {
            imagettftext($image, $size, 0, $x, $y, self::colour($image, $colour), $font, $text);
        } else {
            imagestring($image, 2, $x, $y - imagefontheight(2), $text, self::colour($image, $colour));
        }
    }

    /**
     * Path of the font used for the labels.
     *
     * @return string|null Null when text has to be drawn with the built-in font.
     */
    protected static function font(): ?string {
        global $CFG;

        $path = $CFG->libdir . '/default.ttf';

        return function_exists('imagettftext') && is_readable($path) ? $path : null;
    }
}
