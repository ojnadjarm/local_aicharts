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

use context;
use context_system;
use core_form\dynamic_form;
use html_writer;
use local_aicharts\local\chart_generator;
use local_aicharts\local\query_result;
use local_aicharts\local\query_runner;
use local_aicharts\local\schema_catalogue;
use local_aicharts\local\wizard_state;
use local_aicharts\output\assistant_state;
use local_aicharts\output\result_table;
use moodle_exception;
use moodle_url;

/**
 * One series of the wizard, edited in a modal: name, hint, SQL, parameters and a Run preview.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class series_form extends dynamic_form {
    /** @var int Rows of the preview table. */
    protected const SAMPLE_ROWS = 5;

    /** @var query_result|null Outcome of the Run press, rendered once the page collects its JavaScript. */
    protected ?query_result $result = null;

    #[\Override]
    protected function definition() {
        global $OUTPUT;

        $mform = $this->_form;
        $mono = 'font-family: var(--bs-font-monospace); resize: vertical;';

        $mform->addElement('hidden', 'w');
        $mform->setType('w', PARAM_ALPHANUM);
        $mform->addElement('hidden', 'index');
        $mform->setType('index', PARAM_INT);

        $mform->addElement('text', 'label', get_string('seriesname', 'local_aicharts'), ['size' => 40]);
        $mform->setType('label', PARAM_TEXT);
        $mform->addRule('label', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('textarea', 'hint', get_string('serieshint', 'local_aicharts'), [
            'rows' => 2,
            'placeholder' => get_string('serieshint_placeholder', 'local_aicharts'),
        ]);
        $mform->setType('hint', PARAM_TEXT);
        $mform->addElement('static', 'hintinfo', '', html_writer::div(get_string('serieshintinfo', 'local_aicharts'), 'form-text'));

        $hints = [];
        foreach (schema_catalogue::hints() as $table => $hint) {
            $hints[] = ['placeholder' => '{' . $table . '}', 'hint' => $hint];
        }
        $mform->addElement('static', 'catalogue', '', $OUTPUT->render_from_template('local_aicharts/schema_catalogue', [
            'tables' => $hints,
            'count' => count($hints),
        ]));

        $mform->registerNoSubmitButton('generate');
        $wand = html_writer::tag('i', '', ['class' => 'icon fa fa-wand-magic-sparkles', 'aria-hidden' => 'true']);
        $provider = get_string('provider', 'local_aicharts', assistant_state::provider_name());
        $mform->addGroup([
            $mform->createElement('static', 'wand', '', $wand),
            $mform->createElement('submit', 'generate', get_string('askassistant', 'local_aicharts'), null, false),
            $mform->createElement('static', 'provider', '', html_writer::span($provider, 'text-muted small ms-auto')),
        ], 'assistant', '', '', false);
        if ($this->optional_param('generate', 0, PARAM_BOOL)) {
            $this->ask_assistant();
        }

        $icon = html_writer::tag('i', '', ['class' => 'icon fa fa-triangle-exclamation mt-1', 'aria-hidden' => 'true']);
        $mform->addElement('static', 'disclaimer', '', html_writer::div(
            $icon . html_writer::div(get_string('querydisclaimer', 'local_aicharts')),
            'local-aicharts-statebox d-flex gap-2 mb-3'
        ));

        $mform->addElement('textarea', 'sqltext', get_string('sqltext', 'local_aicharts'), [
            'rows' => 8,
            'style' => $mono,
            'placeholder' => get_string('sqltext_placeholder', 'local_aicharts'),
        ]);
        $mform->setType('sqltext', PARAM_RAW);
        $mform->addElement('static', 'sqlinfo', '', html_writer::div(get_string('sqltextinfo', 'local_aicharts'), 'form-text'));

        $mform->addElement('textarea', 'params', get_string('params', 'local_aicharts'), [
            'rows' => 2,
            'style' => $mono,
            'placeholder' => get_string('params_placeholder', 'local_aicharts'),
        ]);
        $mform->setType('params', PARAM_RAW);

        $mform->registerNoSubmitButton('runquery');
        $mform->addElement('submit', 'runquery', get_string('runquery', 'local_aicharts'));
        $runinfo = html_writer::div(get_string('runseriesinfo', 'local_aicharts'), 'text-muted small');
        $mform->addElement('static', 'runinfo', '', $runinfo);

        $mform->addElement('hidden', 'previewedfor');
        $mform->setType('previewedfor', PARAM_ALPHANUM);

        if ($this->optional_param('runquery', 0, PARAM_BOOL)) {
            $this->run_preview();
        }
    }

    /**
     * Ask the assistant for the posted hint and fill the editors with its answer, or say why it gave none.
     */
    protected function ask_assistant(): void {
        global $OUTPUT, $USER;

        $mform = $this->_form;
        $query = $this->posted_query();
        if ($query->hint === '') {
            $box = new assistant_state('fa-circle-info', get_string('hintrequired', 'local_aicharts'));
            $mform->addElement('static', 'assistantstate', '', $OUTPUT->render($box));
            return;
        }

        $maxrows = max(1, (int) get_config('local_aicharts', 'maxrowsdefault'));
        $result = chart_generator::generate($query->hint, $query->sqltext, $maxrows, (int) $USER->id);
        if ($result->has_error()) {
            $mform->addElement('static', 'assistantstate', '', $OUTPUT->render(assistant_state::from_result($result)));
            return;
        }

        $constants = [
            'sqltext' => $result->sql,
            'params' => json_encode($result->params ?: new \stdClass()),
            'previewedfor' => '',
        ];
        if ($query->label === '') {
            $constants['label'] = $result->name;
        }
        $mform->setConstants($constants);

        $state = $this->state();
        if (!$state->data->proposal) {
            $state->data->proposal = (object) [
                'name' => $result->name,
                'chartjson' => $result->chartjson,
                'notes' => $result->notes,
            ];
            $state->save();
        }
        if ($result->notes !== '') {
            $icon = html_writer::tag('i', '', ['class' => 'icon fa fa-circle-info', 'aria-hidden' => 'true']);
            $notes = get_string('assistantnotes', 'local_aicharts', s($result->notes));
            $notes = html_writer::div($icon . $notes, 'text-muted small');
            $mform->addElement('static', 'assistantnotes', '', $notes);
        }
    }

    /**
     * Run the posted query, keep the outcome as the preview and remember it in the wizard.
     */
    protected function run_preview(): void {
        $query = $this->posted_query();
        $state = $this->state();
        try {
            $params = $this->decode_params($query->params);
            $maxrows = max(1, (int) get_config('local_aicharts', 'maxrowsdefault'));
            $this->result = query_runner::run($query->sqltext, $params, $maxrows);
            if (!$this->result->has_error() && !$this->result->rows) {
                $this->result = query_result::error('validation_failed', get_string('norows', 'local_aicharts'));
            }
        } catch (moodle_exception $e) {
            $this->result = query_result::error('validation_failed', $e->getMessage());
        }

        $hash = '';
        $state->data->lastrun = null;
        if (!$this->result->has_error()) {
            $hash = $this->preview_hash($query);
            $rows = array_slice($this->result->rows, 0, self::SAMPLE_ROWS);
            $state->data->lastrun = (object) [
                'hash' => $hash,
                'columns' => array_keys((array) reset($rows)),
                'rowcount' => $this->result->rowcount,
                'truncated' => $this->result->truncated,
                'durationms' => $this->result->durationms,
                'rows' => array_map(fn($row) => (array) $row, array_slice($this->result->rows, 0, wizard_state::PREVIEW_ROWS)),
            ];
        }
        $state->save();
        $this->_form->setConstants(['previewedfor' => $hash]);

        $heading = html_writer::tag('h5', get_string('preview', 'local_aicharts'), [
            'tabindex' => '-1',
            'data-region' => 'aic-preview',
            'class' => 'mt-3',
        ]);
        $this->_form->addElement('static', 'previewheading', '', $heading);
        $this->_form->addElement('static', 'preview', '', '');
    }

    /**
     * Render the preview here so that its output is collected with the form fragment.
     */
    #[\Override]
    public function definition_after_data() {
        parent::definition_after_data();
        if ($this->result) {
            $this->_form->getElement('preview')->setText($this->preview_html($this->result));
        }
    }

    /**
     * The sample rows with their count, or the reason there are none.
     *
     * @param query_result $result Outcome of the run.
     * @return string
     */
    protected function preview_html(query_result $result): string {
        global $OUTPUT;

        if ($result->has_error()) {
            return $OUTPUT->render(new assistant_state('fa-triangle-exclamation', s($result->errormessage)));
        }

        $html = $OUTPUT->render(new result_table(array_slice($result->rows, 0, self::SAMPLE_ROWS)));
        $count = get_string('previewrowcount', 'local_aicharts', ['rows' => $result->rowcount, 'ms' => $result->durationms]);
        if ($result->rowcount > self::SAMPLE_ROWS) {
            $count .= ' · ' . get_string('previewsample', 'local_aicharts', self::SAMPLE_ROWS);
        }
        $html .= html_writer::div($count, 'text-muted small mt-2');
        $html .= html_writer::div(get_string('livenotice', 'local_aicharts'), 'text-muted small');
        return $html;
    }

    /**
     * The wizard named by the posted token.
     *
     * @return wizard_state
     */
    protected function state(): wizard_state {
        $state = wizard_state::open($this->optional_param('w', '', PARAM_ALPHANUM));
        if (!$state) {
            throw new moodle_exception('wizardexpired', 'local_aicharts');
        }
        return $state;
    }

    /**
     * The series as posted, line endings normalised.
     *
     * @return \stdClass Record with label, hint, sqltext and params.
     */
    protected function posted_query(): \stdClass {
        return wizard_state::query(
            trim($this->optional_param('label', '', PARAM_TEXT)),
            trim($this->optional_param('hint', '', PARAM_TEXT)),
            $this->normalise_text($this->optional_param('sqltext', '', PARAM_RAW)),
            $this->normalise_text($this->optional_param('params', '', PARAM_RAW)),
        );
    }

    /**
     * Text with Unix line endings and no surrounding blank space.
     *
     * @param string $text Text as posted by a textarea.
     * @return string
     */
    protected function normalise_text(string $text): string {
        return trim(str_replace("\r\n", "\n", $text));
    }

    /**
     * The parameters of a query.
     *
     * @param string $params JSON object.
     * @return array
     * @throws moodle_exception When the text is not a JSON object of numbers or text.
     */
    protected function decode_params(string $params): array {
        $decoded = json_decode($params, true);
        $list = is_array($decoded) && $decoded && array_keys($decoded) === range(0, count($decoded) - 1);
        if (!is_array($decoded) || $list || array_filter($decoded, fn($value) => !is_scalar($value))) {
            throw new moodle_exception('paramsinvalid', 'local_aicharts');
        }
        return $decoded;
    }

    /**
     * Hash of the query a preview was built for.
     *
     * @param \stdClass $query Record with label, sqltext and params.
     * @return string
     */
    protected function preview_hash(\stdClass $query): string {
        return sha1(json_encode([$query->label, $query->sqltext, $query->params]));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $query = $this->posted_query();

        if ($query->label === '') {
            $errors['label'] = get_string('labelrequired', 'local_aicharts');
        }
        if ($query->sqltext === '') {
            $errors['sqltext'] = get_string('queryrequired', 'local_aicharts');
        }
        try {
            $this->decode_params($query->params);
        } catch (moodle_exception $e) {
            $errors['params'] = $e->getMessage();
        }
        if ($errors) {
            return $errors;
        }

        $lastrun = $this->state()->data->lastrun;
        $hash = $this->preview_hash($query);
        if (($data['previewedfor'] ?? '') !== $hash || !$lastrun || $lastrun->hash !== $hash) {
            $errors['sqltext'] = get_string('runfirst', 'local_aicharts');
        }
        return $errors;
    }

    #[\Override]
    protected function get_context_for_dynamic_submission(): context {
        return context_system::instance();
    }

    #[\Override]
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/aicharts:manage', context_system::instance());
    }

    #[\Override]
    public function set_data_for_dynamic_submission(): void {
        $index = $this->optional_param('index', -1, PARAM_INT);
        $query = $this->state()->data->queries[$index] ?? null;
        $data = ['w' => $this->optional_param('w', '', PARAM_ALPHANUM), 'index' => $index];
        if ($query) {
            $data += ['label' => $query->label, 'hint' => $query->hint, 'sqltext' => $query->sqltext, 'params' => $query->params];
        }
        $this->set_data($data);
    }

    #[\Override]
    public function process_dynamic_submission() {
        $data = $this->get_data();
        $state = $this->state();
        $query = $this->posted_query();
        $query->run = $state->data->lastrun;
        $state->data->lastrun = null;

        $index = (int) $data->index;
        if (!isset($state->data->queries[$index])) {
            $state->data->queries[] = $query;
            $index = count($state->data->queries) - 1;
        } else {
            $state->data->queries[$index] = $query;
        }
        $state->data->columns = [];
        $state->save();
        return ['index' => $index];
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return $this->state()->url(2);
    }
}
