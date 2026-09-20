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

use html_writer;
use local_aicharts\local\query_runner;
use local_aicharts\local\wizard_state;
use moodleform;

/**
 * Wizard step 2: the series of the chart, each edited in its own modal.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_queries extends moodleform {
    /** @var string[] Columns the series produce together, set by a successful validation. */
    protected array $columns = [];

    #[\Override]
    protected function definition() {
        global $OUTPUT;

        $mform = $this->_form;
        $state = $this->_customdata['state'];
        $kind = $state->data->kind;

        $mform->addElement('hidden', 'step', 2);
        $mform->setType('step', PARAM_INT);

        $kindline = html_writer::span(get_string('kind', 'local_aicharts') . ':', 'text-muted') . ' '
            . html_writer::tag('strong', get_string('kind' . $kind, 'local_aicharts')) . ' — '
            . get_string('kind' . $kind . '_desc', 'local_aicharts') . ' '
            . html_writer::link($state->url(1), get_string('changekind', 'local_aicharts'));
        $mform->addElement('static', 'kindline', '', html_writer::div($kindline, 'mb-3'));

        if ($state->data->kindchanged) {
            $icon = html_writer::tag('i', '', ['class' => 'icon fa fa-circle-info mt-1', 'aria-hidden' => 'true']);
            $box = html_writer::div(
                $icon . html_writer::div(get_string('kindchanged', 'local_aicharts')),
                'local-aicharts-statebox d-flex gap-2 mb-3',
                ['role' => 'status']
            );
            $mform->addElement('static', 'kindchanged', '', $box);
        }

        $mform->addElement('static', 'serieslist', '', $OUTPUT->render_from_template(
            'local_aicharts/series_list',
            $this->series_context($state)
        ));

        $rule = $kind === 'trend' ? 'trendseriesrule' : 'severalseriesrule';
        $limit = max(1, (int) get_config('local_aicharts', 'maxrowsdefault'));
        $notes = html_writer::tag('p', get_string($rule, 'local_aicharts'), ['class' => 'text-muted small mb-1'])
            . html_writer::tag('p', get_string('previewlimitnote', 'local_aicharts', $limit), ['class' => 'text-muted small']);
        $mform->addElement('static', 'seriesnotes', '', $notes);
    }

    /**
     * The template context of the series list.
     *
     * @param wizard_state $state The wizard.
     * @return array
     */
    protected function series_context(wizard_state $state): array {
        $rows = [];
        $last = count($state->data->queries) - 1;
        foreach ($state->data->queries as $index => $query) {
            $run = $query->run;
            $rows[] = [
                'index' => $index,
                'label' => $query->label,
                'sql' => trim(strtok($query->sqltext, "\n")),
                'status' => $run
                    ? get_string('previewrowcount', 'local_aicharts', ['rows' => $run->rowcount, 'ms' => $run->durationms])
                    : get_string('notrun', 'local_aicharts'),
                'upurl' => $state->action_url('up', $index)->out(false),
                'downurl' => $state->action_url('down', $index)->out(false),
                'removeurl' => $state->action_url('remove', $index)->out(false),
                'first' => $index === 0,
                'last' => $index === $last,
            ];
        }
        return ['series' => $rows];
    }

    /**
     * The columns the series produce together, known once validation passed.
     *
     * @return string[]
     */
    public function columns(): array {
        return $this->columns;
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $state = $this->_customdata['state'];
        $queries = $state->data->queries;
        $trend = $state->data->kind === 'trend';

        if (!$queries) {
            $errors['serieslist'] = get_string('seriesrequired', 'local_aicharts');
            return $errors;
        }
        $labels = [];
        foreach ($queries as $query) {
            if (in_array($query->label, $labels, true)) {
                $errors['serieslist'] = get_string('labelduplicate', 'local_aicharts');
                return $errors;
            }
            $labels[] = $query->label;
            if (!$query->run) {
                $errors['serieslist'] = get_string('seriesnotrun', 'local_aicharts', $query->label);
                return $errors;
            }
            if ($trend && $query->run->rowcount > 1) {
                $a = (object) ['label' => $query->label, 'rows' => $query->run->rowcount];
                $errors['serieslist'] = get_string('error_trendrows', 'local_aicharts', $a);
                return $errors;
            }
        }

        $maxrows = max(1, (int) get_config('local_aicharts', 'maxrowsdefault'));
        $result = $trend ? query_runner::run_points($queries, $maxrows) : query_runner::run_all($queries, $maxrows);
        if ($result->has_error()) {
            $errors['serieslist'] = $result->errormessage;
            return $errors;
        }

        if ($trend) {
            $this->columns = array_merge(['runtime'], $labels);
        } else if (count($queries) === 1) {
            $this->columns = $queries[0]->run->columns;
        } else {
            $this->columns = array_merge([$queries[0]->run->columns[0]], $labels);
        }
        return $errors;
    }
}
