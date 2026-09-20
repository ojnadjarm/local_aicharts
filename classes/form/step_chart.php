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
use local_aicharts\local\chart_builder;
use local_aicharts\local\chart_factory;
use local_aicharts\local\chart_generator;
use local_aicharts\local\chart_spec;
use local_aicharts\local\point_store;
use local_aicharts\local\query_runner;
use local_aicharts\local\wizard_state;
use local_aicharts\output\assistant_state;
use local_aicharts\output\result_table;
use local_aicharts\output\wizard_page;
use moodle_exception;
use moodleform;

/**
 * Wizard step 3: how the result is drawn, as plain controls over the columns the Data step produced.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_chart extends moodleform {
    /** @var int Rows sent to the assistant as a sample. */
    protected const SAMPLE_ROWS = 5;

    /** @var string[] Icon of each chart type. */
    protected const ICONS = ['bar' => 'fa-chart-column', 'line' => 'fa-chart-line', 'pie' => 'fa-chart-pie', 'table' => 'fa-table'];

    /** @var string[] The columns the Data step produced. */
    protected array $columns;

    /** @var bool Whether the chart is a trend. */
    protected bool $trend;

    /** @var bool Whether the series are picked from the columns of a single query. */
    protected bool $onequery;

    /** @var array The controls the preview is drawn from. */
    protected array $controls;

    /** @var string The chart JSON of the controls, set by a successful validation. */
    protected string $chartjson = '';

    /** @var bool Whether the rows of the Data step runs were read. */
    protected bool $loaded = false;

    /** @var array The rows the preview is drawn from. */
    protected array $rows = [];

    /** @var int Rows the Data step runs returned. */
    protected int $rowcount = 0;

    /** @var int Time the Data step runs took. */
    protected int $durationms = 0;

    /** @var bool Whether a Data step run returned more rows than were kept. */
    protected bool $truncated = false;

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $state = $this->_customdata['state'];
        $this->columns = array_values($state->data->columns);
        $this->trend = $state->data->kind === 'trend';
        $this->onequery = !$this->trend && count($state->data->queries) === 1;
        $this->controls = $this->submitted()
            ? $this->controls($this->posted_values())
            : $this->initial_controls($state);

        $codes = implode(', ', array_map(fn($column) => html_writer::tag('code', s($column)), $this->columns));
        $mform->addElement('static', 'columnsline', '', html_writer::div(
            get_string('columnsfromdata', 'local_aicharts', $codes),
            'text-muted small mb-3'
        ));

        $radios = [];
        foreach (chart_spec::TYPES as $type) {
            $icon = html_writer::tag('i', '', ['class' => 'icon fa ' . self::ICONS[$type], 'aria-hidden' => 'true']);
            $radios[] = $mform->createElement('radio', 'type', '', $icon . get_string('type_' . $type, 'local_aicharts'), $type);
        }
        $mform->addGroup($radios, 'typegroup', get_string('charttype', 'local_aicharts'), ' ', false);
        $mform->setType('type', PARAM_ALPHA);

        $mform->addElement('text', 'title', get_string('charttitle', 'local_aicharts'), ['size' => 40]);
        $mform->setType('title', PARAM_TEXT);

        if ($this->trend) {
            $options = [point_store::TIME_COLUMN => get_string('runtimecolumn', 'local_aicharts')];
            $attributes = ['disabled' => 'disabled'];
            $mform->addElement('select', 'labelcolumn', get_string('labelcolumn', 'local_aicharts'), $options, $attributes);
        } else {
            $options = array_combine($this->columns, $this->columns);
            $mform->addElement('select', 'labelcolumn', get_string('labelcolumn', 'local_aicharts'), $options);
            $formats = [];
            foreach (chart_spec::LABEL_FORMATS as $format) {
                $formats[$format] = get_string('labelformat_' . $format, 'local_aicharts');
            }
            $mform->addElement('select', 'labelformat', get_string('labelformat', 'local_aicharts'), $formats);
            $mform->hideIf('labelformat', 'type', 'eq', 'table');
        }
        $mform->addHelpButton('labelcolumn', 'labelcolumn', 'local_aicharts');
        $mform->hideIf('labelcolumn', 'type', 'eq', 'table');

        $this->add_series_elements();

        $mform->addElement('text', 'xlabel', get_string('xlabel', 'local_aicharts'), ['size' => 40]);
        $mform->setType('xlabel', PARAM_TEXT);
        $mform->addElement('text', 'ylabel', get_string('ylabel', 'local_aicharts'), ['size' => 40]);
        $mform->setType('ylabel', PARAM_TEXT);
        foreach (['xlabel', 'ylabel'] as $name) {
            $mform->hideIf($name, 'type', 'eq', 'table');
            $mform->hideIf($name, 'type', 'eq', 'pie');
        }

        $groups = ['bar' => ['horizontal', 'stacked'], 'line' => ['smooth'], 'pie' => ['doughnut']];
        foreach ($groups as $type => $flags) {
            $boxes = [];
            foreach ($flags as $flag) {
                $boxes[] = $mform->createElement('advcheckbox', $flag, get_string($flag, 'local_aicharts'), '', null, [0, 1]);
            }
            if ($type === 'bar') {
                $note = html_writer::div(get_string('typeoptionsnote', 'local_aicharts'), 'form-text w-100');
                $boxes[] = $mform->createElement('static', 'typeoptionsnote', '', $note);
            }
            $mform->addGroup($boxes, $type . 'options', get_string($type . 'options', 'local_aicharts'), ' ', false);
            $mform->hideIf($type . 'options', 'type', 'neq', $type);
        }

        $mform->addElement('textarea', 'charthint', get_string('charthint', 'local_aicharts'), [
            'rows' => 2,
            'placeholder' => get_string('charthint_placeholder', 'local_aicharts'),
        ]);
        $mform->setType('charthint', PARAM_TEXT);
        $mform->setDefault('charthint', $state->data->charthint);

        $mform->registerNoSubmitButton('generate');
        $mform->registerNoSubmitButton('updatepreview');
        $wand = html_writer::tag('i', '', ['class' => 'icon fa fa-wand-magic-sparkles', 'aria-hidden' => 'true']);
        $note = html_writer::span(get_string('askassistantinfo', 'local_aicharts'), 'text-muted small');
        $mform->addGroup([
            $mform->createElement('static', 'wand', '', $wand),
            $mform->createElement('submit', 'generate', get_string('askassistant', 'local_aicharts'), null, false),
            $mform->createElement('static', 'assistantnote', '', $note),
        ], 'assistant', '', '', false);
        $values = $this->values($this->controls);
        $mform->setDefaults($values);
        if ($this->submitted()) {
            // A submitted checkbox keeps the posted value, so the ticks the step dropped or added are forced.
            $mform->setConstants(['seriescol' => $values['seriescol']]);
        }
    }

    /**
     * Ask the assistant when it was asked for and keep the answer in the wizard.
     *
     * Called by the page after the submission was handled, so no answer is fetched on a Next.
     *
     * @return bool Whether an answer was stored, so the page reloads the step from it.
     */
    public function prepare_chart(): bool {
        if (!$this->optional_param('generate', 0, PARAM_BOOL) || !$this->ask_assistant()) {
            return false;
        }
        $state = $this->_customdata['state'];
        $state->data->chartjson = chart_builder::from_controls($this->controls);
        $state->data->charthint = trim($this->optional_param('charthint', '', PARAM_TEXT));
        $state->save();
        return true;
    }

    /**
     * Whether this step was submitted, answered before any element is added.
     *
     * The form's own flag is not set yet: _process_submission() runs after definition(). data_submitted()
     * only says that some POST arrived and misses the data of a modal form, so the marker is read instead.
     *
     * @return bool
     */
    protected function submitted(): bool {
        return (bool) $this->optional_param('_qf__' . $this->_formname, 0, PARAM_BOOL);
    }

    /**
     * The series controls: a checkbox per column of a single query, or the list of the query labels.
     */
    protected function add_series_elements(): void {
        $mform = $this->_form;
        if (!$this->onequery) {
            $items = array_map(fn($column) => html_writer::tag('li', s($column)), array_slice($this->columns, 1));
            $list = html_writer::tag('p', get_string('chartseries_desc', 'local_aicharts'), ['class' => 'form-text mt-0 mb-1'])
                . html_writer::tag('ul', implode('', $items), ['class' => 'mb-0 ps-4']);
            $mform->addElement('static', 'chartseries', get_string('chartseries', 'local_aicharts'), $list);
            return;
        }

        $elements = [];
        foreach ($this->columns as $index => $column) {
            if ($column === $this->controls['labelcolumn']) {
                continue;
            }
            if ($elements) {
                $elements[] = $mform->createElement('static', "seriesbreak$index", '', html_writer::div('', 'w-100'));
            }
            $elements[] = $mform->createElement('advcheckbox', "seriescol[$index]", s($column), '', null, [0, 1]);
            $elements[] = $mform->createElement('text', "serieslabel[$index]", '', [
                'size' => 20,
                'placeholder' => get_string('serieslabel', 'local_aicharts'),
                'aria-label' => get_string('serieslabel', 'local_aicharts'),
            ]);
            $mform->setType("serieslabel[$index]", PARAM_TEXT);
        }
        $mform->addGroup($elements, 'series', get_string('chartseries', 'local_aicharts'), ' ', false);
        $mform->hideIf('series', 'type', 'eq', 'table');
    }

    /**
     * Ask the assistant how to draw the columns and set the controls from its answer, or say why it gave none.
     *
     * @return bool Whether an answer was taken.
     */
    protected function ask_assistant(): bool {
        global $OUTPUT, $USER;

        $mform = $this->_form;
        $hint = trim($this->optional_param('charthint', '', PARAM_TEXT));
        if ($hint === '') {
            $box = new assistant_state('fa-circle-info', get_string('charthintrequired', 'local_aicharts'));
            $mform->addElement('static', 'assistantstate', '', $OUTPUT->render($box));
            return false;
        }

        $this->load_rows();
        $sample = array_slice($this->rows, 0, self::SAMPLE_ROWS);
        $kind = $this->trend ? 'trend' : 'oneshot';
        $result = chart_generator::generate_chart($this->columns, $sample, $hint, $kind, (int) $USER->id);
        if ($result->has_error()) {
            $mform->addElement('static', 'assistantstate', '', $OUTPUT->render(assistant_state::from_result($result)));
            return false;
        }

        try {
            $spec = chart_spec::from_json($result->chartjson);
            if (!$spec->is_table()) {
                chart_factory::require_columns($spec, $this->columns);
            }
        } catch (moodle_exception $e) {
            $box = new assistant_state('fa-triangle-exclamation', get_string('chartcolumnmissing', 'local_aicharts'));
            $mform->addElement('static', 'assistantstate', '', $OUTPUT->render($box));
            return false;
        }
        $this->controls = $this->controls($this->values(chart_builder::controls($spec)));
        return true;
    }

    /**
     * The controls the step opens with: the saved chart, else the assistant's proposal, else the defaults.
     *
     * @param wizard_state $state The wizard.
     * @return array
     */
    protected function initial_controls(wizard_state $state): array {
        foreach ([$state->data->chartjson, $state->data->proposal->chartjson ?? ''] as $chartjson) {
            if ($chartjson === '') {
                continue;
            }
            try {
                $spec = chart_spec::from_json($chartjson);
                if (!$spec->is_table()) {
                    chart_factory::require_columns($spec, $this->columns);
                }
                return $this->controls($this->values(chart_builder::controls($spec)));
            } catch (moodle_exception $e) {
                continue;
            }
        }
        $sample = $this->onequery ? (array) ($state->data->queries[0]->run->rows[0] ?? []) : [];
        return chart_builder::defaults($this->columns, $state->data->kind, $sample);
    }

    /**
     * The form values of a set of controls.
     *
     * @param array $controls As chart_builder::from_controls() expects.
     * @return array Keyed by element name.
     */
    protected function values(array $controls): array {
        $values = [
            'type' => $controls['type'],
            'title' => $controls['title'],
            'labelcolumn' => $controls['labelcolumn'],
            'labelformat' => $controls['labelformat'],
            'xlabel' => $controls['xlabel'],
            'ylabel' => $controls['ylabel'],
        ];
        foreach (chart_builder::FLAGS as $flag) {
            $values[$flag] = (int) $controls[$flag];
        }
        foreach ($this->columns as $index => $column) {
            $values['seriescol'][$index] = (int) isset($controls['series'][$column]);
            $values['serieslabel'][$index] = $controls['series'][$column] ?? '';
        }
        return $values;
    }

    /**
     * The controls of a set of form values.
     *
     * @param array $values Keyed by element name, as posted or as validated.
     * @return array As chart_builder::from_controls() expects.
     */
    protected function controls(array $values): array {
        $labelcolumn = $this->trend ? point_store::TIME_COLUMN : (string) ($values['labelcolumn'] ?? '');
        $series = [];
        if ($this->onequery) {
            $labelindex = array_search($labelcolumn, $this->columns, true);
            foreach ($this->columns as $index => $column) {
                if (!empty($values['seriescol'][$index]) && $column !== $labelcolumn) {
                    $series[$column] = trim((string) ($values['serieslabel'][$index] ?? ''));
                }
            }
            if (!$series && $labelindex !== false && !empty($values['seriescol'][$labelindex])) {
                $series = $this->fallback_series($labelcolumn);
            }
        } else {
            foreach (array_slice($this->columns, 1) as $column) {
                $series[$column] = $column;
            }
        }

        $controls = [
            'type' => $values['type'] ?? '',
            'title' => trim((string) ($values['title'] ?? '')),
            'labelcolumn' => $labelcolumn,
            'labelformat' => $this->trend ? 'text' : (($values['labelformat'] ?? '') ?: 'text'),
            'series' => $series,
            'xlabel' => trim((string) ($values['xlabel'] ?? '')),
            'ylabel' => trim((string) ($values['ylabel'] ?? '')),
        ];
        foreach (chart_builder::FLAGS as $flag) {
            $controls[$flag] = !empty($values[$flag]);
        }
        return $controls;
    }

    /**
     * The series to tick when the column that held the only tick has just become the label column.
     *
     * @param string $labelcolumn The column drawn along.
     * @return array Column => label, the first remaining column the defaults would take.
     */
    protected function fallback_series(string $labelcolumn): array {
        $state = $this->_customdata['state'];
        $columns = array_values(array_diff($this->columns, [$labelcolumn]));
        array_unshift($columns, $labelcolumn);
        $sample = (array) ($state->data->queries[0]->run->rows[0] ?? []);
        $series = chart_builder::defaults($columns, $state->data->kind, $sample)['series'];
        return $series ? [(string) array_key_first($series) => ''] : [];
    }

    /**
     * The control values as posted.
     *
     * @return array Keyed by element name.
     */
    protected function posted_values(): array {
        $values = [];
        $types = [
            'type' => PARAM_ALPHA,
            'title' => PARAM_TEXT,
            'labelcolumn' => PARAM_RAW,
            'labelformat' => PARAM_ALPHA,
            'xlabel' => PARAM_TEXT,
            'ylabel' => PARAM_TEXT,
        ];
        foreach ($types as $name => $type) {
            $values[$name] = $this->optional_param($name, '', $type);
        }
        foreach (chart_builder::FLAGS as $flag) {
            $values[$flag] = $this->optional_param($flag, 0, PARAM_BOOL);
        }
        $values['seriescol'] = optional_param_array('seriescol', [], PARAM_BOOL);
        $values['serieslabel'] = optional_param_array('serieslabel', [], PARAM_TEXT);
        return $values;
    }

    /**
     * Read the rows the Data step runs kept; the Chart step never runs a query.
     */
    protected function load_rows(): void {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $queries = array_values($this->_customdata['state']->data->queries);
        $rowsets = [];
        foreach ($queries as $query) {
            $run = $query->run;
            $rowsets[$query->label] = array_map(fn($row) => (array) $row, (array) ($run->rows ?? []));
            $this->rowcount += (int) ($run->rowcount ?? 0);
            $this->durationms += (int) ($run->durationms ?? 0);
            $this->truncated = $this->truncated || count($rowsets[$query->label]) < (int) ($run->rowcount ?? 0);
        }

        if ($this->trend) {
            $values = [];
            foreach ($rowsets as $label => $rows) {
                $value = $rows ? end($rows[0]) : null;
                $values[$label] = $value === null ? null : (float) $value;
            }
            $this->rows = point_store::preview_rows((int) $this->_customdata['state']->data->id, $values, time());
        } else if (count($rowsets) === 1) {
            $this->rows = reset($rowsets);
        } else {
            $this->rows = array_map(fn($row) => (array) $row, query_runner::merge($rowsets));
            $this->rowcount = count($this->rows);
        }
    }

    /**
     * The preview card: the chart or table of the current controls, drawn from the rows the Data step kept.
     *
     * @return string
     */
    public function preview_html(): string {
        global $OUTPUT;

        $this->load_rows();
        $count = '';
        $limit = 0;
        if (!$this->rows) {
            $body = $OUTPUT->render(new assistant_state('fa-circle-info', get_string('norows', 'local_aicharts')));
        } else {
            $count = get_string('previewrowcount', 'local_aicharts', ['rows' => $this->rowcount, 'ms' => $this->durationms]);
            $limit = $this->truncated ? count($this->rows) : 0;
            try {
                $spec = chart_spec::from_json(chart_builder::from_controls($this->controls));
                self::require_drawable($spec);
                $body = $spec->is_table()
                    ? $OUTPUT->render(new result_table($this->rows))
                    : $OUTPUT->render_chart(chart_factory::create($spec, $this->rows), false);
            } catch (moodle_exception $e) {
                $body = $OUTPUT->render(new assistant_state('fa-triangle-exclamation', $e->getMessage()));
            }
        }

        return $OUTPUT->render_from_template('local_aicharts/wizard_chart_preview', [
            'body' => $body,
            'count' => $count,
            'formid' => wizard_page::FORM_ID,
            'limit' => $limit,
            'firstpoint' => $this->trend && count($this->rows) === 1,
        ]);
    }

    /**
     * The chart JSON of the validated controls.
     *
     * @return string
     */
    public function chartjson(): string {
        return $this->chartjson;
    }

    /**
     * Refuse what the chart library cannot draw: a pie of several series.
     *
     * @param chart_spec $spec Parsed chart definition.
     * @throws moodle_exception
     */
    protected static function require_drawable(chart_spec $spec): void {
        if ($spec->type === 'pie' && count($spec->series) > 1) {
            throw new moodle_exception('invalidchartspec', 'local_aicharts', '', get_string('pieoneseries', 'local_aicharts'));
        }
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        try {
            $chartjson = chart_builder::from_controls($this->controls($data));
            $spec = chart_spec::from_json($chartjson);
            if (!$spec->is_table()) {
                chart_factory::require_columns($spec, $this->columns);
            }
            self::require_drawable($spec);
            $this->chartjson = $chartjson;
        } catch (moodle_exception $e) {
            $errors['typegroup'] = $e->getMessage();
        }
        return $errors;
    }
}
