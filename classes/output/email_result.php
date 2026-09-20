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
use moodle_url;
use stdClass;

/**
 * The result of a scheduled run as the body of a message.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class email_result implements renderable, templatable {
    /** @var int How many rows the message carries. */
    public const INLINEROWS = 10;

    /**
     * Constructor.
     *
     * @param stdClass $chart Chart the run belongs to.
     * @param stdClass $result Result record.
     * @param array $rows Result rows, empty when the run failed.
     * @param string|null $imagedata PNG bytes of the chart, null for a table or a failed run.
     * @param bool $hasattachment Whether the CSV travels with the message.
     */
    public function __construct(
        /** @var stdClass Chart the run belongs to. */
        protected stdClass $chart,
        /** @var stdClass Result record. */
        protected stdClass $result,
        /** @var array Result rows. */
        protected array $rows = [],
        /** @var string|null PNG bytes of the chart. */
        protected ?string $imagedata = null,
        /** @var bool Whether the CSV travels with the message. */
        protected bool $hasattachment = false,
    ) {
    }

    /**
     * Export the message body for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        global $SITE;

        $failed = $this->failed();
        $table = (new result_table($this->rows, self::INLINEROWS))->export_for_template($output);
        $shown = count($table['inline']);

        return [
            'sitename' => format_string($SITE->fullname),
            'name' => format_string($this->chart->name),
            'runline' => $this->run_line(),
            'failed' => $failed,
            'errormessage' => (string) ($this->result->errormessage ?? ''),
            'hasimage' => !$failed && $this->imagedata !== null,
            'imagedata' => $this->imagedata === null ? '' : base64_encode($this->imagedata),
            'imagealt' => get_string('emailimagealt', 'local_aicharts', format_string($this->chart->name)),
            'hasrows' => !$failed && $table['hasrows'],
            'head' => $table['head'],
            'rows' => $table['inline'],
            'hasmore' => $table['total'] > $shown,
            'morerows' => $table['total'] - $shown,
            'historyurl' => $this->history_url()->out(false),
            'hasattachment' => $this->hasattachment,
            'preferencesurl' => (new moodle_url('/message/notificationpreferences.php'))->out(false),
        ];
    }

    /**
     * The same message body as plain text.
     *
     * @return string
     */
    public function plain_text(): string {
        $lines = [
            format_string($this->chart->name),
            $this->run_line(),
            '',
        ];

        if ($this->failed()) {
            $lines[] = (string) ($this->result->errormessage ?? '');
        } else {
            foreach (array_slice(array_values($this->rows), 0, self::INLINEROWS) as $row) {
                $cells = [];
                foreach ((array) $row as $column => $value) {
                    $cells[] = $column . ': ' . $value;
                }
                $lines[] = implode(' · ', $cells);
            }
            $more = count($this->rows) - self::INLINEROWS;
            if ($more > 0) {
                $lines[] = get_string('emailmorerows', 'local_aicharts', $more);
            }
        }

        $lines[] = '';
        $lines[] = get_string('emailopenhistory', 'local_aicharts') . ': ' . $this->history_url()->out(false);
        $lines[] = get_string($this->hasattachment ? 'emailattached' : 'emailnoattachment', 'local_aicharts');
        $lines[] = '';
        $lines[] = get_string('emailfooter', 'local_aicharts');

        return implode("\n", $lines);
    }

    /**
     * Whether the run this message reports failed.
     *
     * @return bool
     */
    protected function failed(): bool {
        return $this->result->status !== 'ok';
    }

    /**
     * The run date line, with the row count or the failure.
     *
     * @return string
     */
    protected function run_line(): string {
        $date = userdate($this->result->timecreated);
        if ($this->failed()) {
            return get_string('emailrunfailed', 'local_aicharts', $date);
        }

        return get_string(
            empty($this->result->truncated) ? 'emailrun' : 'emailruntruncated',
            'local_aicharts',
            ['date' => $date, 'rows' => (int) $this->result->numrows]
        );
    }

    /**
     * Link to the run this message reports.
     *
     * @return moodle_url
     */
    protected function history_url(): moodle_url {
        return new moodle_url('/local/aicharts/view.php', [
            'id' => $this->chart->id,
            'resultid' => $this->result->id,
        ]);
    }
}
