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

namespace local_aicharts\form;

use core_date;
use local_aicharts\local\chart_spec;
use local_aicharts\local\point_store;
use local_aicharts\local\query_runner;
use local_aicharts\local\schedule;
use local_aicharts\output\dashboard;
use moodleform;
use stdClass;

/**
 * Wizard step 5: the summary, a fresh run of the chart, its name and Save.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_review extends moodleform {
    /** @var array The review run as template keys: the drawn result, or why there is none. */
    protected array $run = [];

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $state = $this->_customdata['state'];

        $mform->addElement('hidden', 'step', 5);
        $mform->setType('step', PARAM_INT);

        $mform->addElement('text', 'name', get_string('chartname', 'local_aicharts'), ['size' => 40]);
        $mform->setType('name', PARAM_TEXT);
        $mform->setDefault('name', $state->data->name ?: ($state->data->proposal->name ?? ''));
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('static', 'review', '');
    }

    /**
     * Run the chart as it is now, remember whether it can be saved and render the summary.
     *
     * Called by the page after the submission was handled, so a Save runs no query.
     */
    public function prepare_review(): void {
        global $OUTPUT;

        $this->review();
        $this->_form->getElement('review')->setText($OUTPUT->render_from_template(
            'local_aicharts/wizard_review',
            $this->review_context()
        ));
    }

    /**
     * Run the chart as it is now and remember in the wizard whether it can be saved.
     */
    protected function review(): void {
        $state = $this->_customdata['state'];
        $data = $state->data;
        $trend = $data->kind === 'trend';
        $maxrows = max(1, (int) $data->maxrows);

        $result = $trend
            ? query_runner::run_points($data->queries, $maxrows)
            : query_runner::run_all($data->queries, $maxrows);

        $data->review = (object) ['hash' => self::hash($data), 'ok' => !$result->has_error()];
        $state->save();

        if ($result->has_error()) {
            $this->run = ['errormessage' => $result->errormessage, 'errordetail' => $result->errordetail];
            return;
        }

        $rows = $trend
            ? point_store::preview_rows((int) $data->id, (array) $result->rows[0], time())
            : $result->rows;
        $this->run = $this->body($rows) + [
            'count' => get_string('previewrowcount', 'local_aicharts', (object) [
                'rows' => count($rows),
                'ms' => $result->durationms,
            ]),
            'firstpoint' => $trend && count($rows) === 1,
        ];
    }

    /**
     * The chart or table of the review run.
     *
     * @param array $rows The rows the run returned.
     * @return array The result part of the review context.
     */
    protected function body(array $rows): array {
        global $OUTPUT;

        $data = $this->_customdata['state']->data;
        $chart = (object) [
            'id' => (int) $data->id,
            'name' => $data->name,
            'chartjson' => $data->chartjson,
            'maxrows' => (int) $data->maxrows,
        ];
        return ['icon' => dashboard::icon($chart), 'iconlabel' => dashboard::icon_label($chart)]
            + dashboard::render_rows($OUTPUT, $chart, $rows, count($rows), false, true);
    }

    /**
     * The template context of the summary and the review run.
     *
     * @return array
     */
    protected function review_context(): array {
        $state = $this->_customdata['state'];
        $data = $state->data;

        return $this->run + [
            'summary' => $this->summary(),
            'name' => $data->name ?: ($data->proposal->name ?? ''),
            'fixurl' => $state->url(2)->out(false),
        ];
    }

    /**
     * The summary of what the wizard holds, one term and description per line.
     *
     * @return array
     */
    protected function summary(): array {
        $data = $this->_customdata['state']->data;
        $series = [];
        foreach ($data->queries as $query) {
            $status = get_string('previewrowcount', 'local_aicharts', (object) [
                'rows' => $query->run->rowcount ?? 0,
                'ms' => $query->run->durationms ?? 0,
            ]);
            $series[] = $query->label . ' (' . $status . ')';
        }

        $lines = [
            'summary_kind' => get_string('kind' . $data->kind, 'local_aicharts') . ' — '
                . get_string('kind' . $data->kind . '_desc', 'local_aicharts'),
            'summary_series' => implode(', ', $series),
            'summary_chart' => $this->chart_line(),
            'summary_runs' => $this->runs_line(),
            'summary_maxrows' => (string) (int) $data->maxrows,
            'summary_emailed' => $this->emailed_line(),
        ];

        $summary = [];
        foreach ($lines as $key => $description) {
            if ($description !== '') {
                $summary[] = ['term' => get_string($key, 'local_aicharts'), 'description' => $description];
            }
        }
        return $summary;
    }

    /**
     * The chart type with the column it is drawn along.
     *
     * @return string
     */
    protected function chart_line(): string {
        try {
            $spec = chart_spec::from_json($this->_customdata['state']->data->chartjson);
        } catch (\moodle_exception $e) {
            return '';
        }
        if ($spec->is_table()) {
            return get_string('type_table', 'local_aicharts');
        }

        $label = $spec->labelcolumn === point_store::TIME_COLUMN
            ? get_string('runtimecolumn', 'local_aicharts')
            : $spec->labelcolumn;
        return get_string('summary_chartline', 'local_aicharts', (object) [
            'type' => get_string('type_' . $spec->type, 'local_aicharts'),
            'labelcolumn' => $label,
            'labelformat' => get_string('labelformat_' . ($spec->labelformat ?: 'text'), 'local_aicharts'),
        ]);
    }

    /**
     * When the chart runs, with the next run and the points a trend keeps.
     *
     * @return string
     */
    protected function runs_line(): string {
        $data = $this->_customdata['state']->data;
        if ($data->runmode === schedule::MODE_LIVE) {
            return get_string('livesummary', 'local_aicharts');
        }

        $chart = (object) [
            'runmode' => $data->runmode,
            'runhour' => (int) $data->runhour,
            'runday' => (int) $data->runday,
        ];
        $parts = [get_string('scheduledmodeat', 'local_aicharts', (object) [
            'schedule' => schedule::describe($chart),
            'hour' => sprintf('%02d:00', (int) $data->runhour),
        ])];
        $next = schedule::next_run($chart, time());
        if ($next) {
            $formatted = userdate($next, get_string('strftimedaydatetime', 'langconfig'), core_date::get_server_timezone());
            $parts[] = get_string('nextrun', 'local_aicharts', $formatted);
        }
        if ($data->kind === 'trend') {
            $parts[] = get_string('pointskept', 'local_aicharts', point_store::retention($data));
        }
        return implode(' · ', $parts);
    }

    /**
     * Who receives the result and when, empty when nobody does.
     *
     * @return string
     */
    protected function emailed_line(): string {
        global $DB;

        $data = $this->_customdata['state']->data;
        $ids = array_filter(array_map('intval', explode(',', (string) $data->emailto)));
        if (!$ids) {
            return '';
        }

        $names = [];
        foreach ($DB->get_records_list('user', 'id', $ids) as $user) {
            $names[] = fullname($user);
        }
        $when = get_string('emailwhen_' . ($data->emailwhen === 'rows' ? 'rows' : 'always'), 'local_aicharts');
        return implode(', ', $names) . ' · ' . $when;
    }

    /**
     * Whether the review run of the current chart succeeded, so Save may be offered.
     *
     * @return bool
     */
    public function can_save(): bool {
        $data = $this->_customdata['state']->data;
        $review = $data->review ?? null;
        return (bool) $review && $review->ok && $review->hash === self::hash($data);
    }

    /**
     * Hash of what the review run covered: the queries, the chart and the row limit.
     *
     * @param stdClass $data The wizard data.
     * @return string
     */
    public static function hash(stdClass $data): string {
        $queries = [];
        foreach ($data->queries as $query) {
            $queries[] = [$query->label, $query->sqltext, $query->params];
        }
        return sha1(json_encode([$data->kind, $queries, $data->chartjson, (int) $data->maxrows]));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }
        if (!$this->can_save()) {
            $errors['review'] = get_string('runfirstreview', 'local_aicharts');
        }
        return $errors;
    }
}
