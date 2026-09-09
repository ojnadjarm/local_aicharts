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
use core\notification;
use core_date;
use core_form\dynamic_form;
use core_user\fields;
use html_writer;
use local_aicharts\local\chart_factory;
use local_aicharts\local\chart_generator;
use local_aicharts\local\chart_repository;
use local_aicharts\local\chart_spec;
use local_aicharts\local\generation_result;
use local_aicharts\local\query_runner;
use local_aicharts\local\result_store;
use local_aicharts\local\schedule;
use local_aicharts\local\sql_validator;
use local_aicharts\output\result_table;
use local_aicharts\task\run_chart;
use moodle_exception;
use moodle_url;

/**
 * Add or edit a chart in a modal: Generate previews the answer, Save changes stores it.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_form extends dynamic_form {
    /** @var string[] Hint fields, keyed by name with their icon. */
    protected const HINTS = ['schemahint' => 'fa-database', 'charthint' => 'fa-chart-column', 'sqlhint' => 'fa-code'];

    /** @var string[] Run modes, live first. */
    protected const RUNMODES = ['live', 'daily', 'weekly', 'monthly'];

    /** @var generation_result|null Answer to preview, rendered once the page collects its JavaScript. */
    protected ?generation_result $result = null;

    #[\Override]
    protected function definition() {
        global $USER;

        $mform = $this->_form;

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $chart = $this->saved_chart();
        if ($chart && $this->optional_param('duplicate', 0, PARAM_BOOL)) {
            $mform->setConstants(['id' => 0]);
        }

        $mform->addElement('textarea', 'prompt', get_string('prompt', 'local_aicharts'), [
            'rows' => 3,
            'placeholder' => get_string('prompt_placeholder', 'local_aicharts'),
        ]);
        $mform->setType('prompt', PARAM_TEXT);
        $mform->addRule('prompt', null, 'required', null, 'client');
        $mform->addElement('static', 'promptscope', '', get_string('promptscope', 'local_aicharts'));

        $mform->addElement('text', 'maxrows', get_string('maxrows', 'local_aicharts'), ['size' => 6]);
        $mform->setType('maxrows', PARAM_INT);
        $mform->setDefault('maxrows', $this->default_maxrows());
        $mform->addElement('static', 'maxrowsinfo', '', get_string('maxrowssitemax', 'local_aicharts', $this->site_maxrows()));

        foreach (self::HINTS as $name => $icon) {
            $title = html_writer::tag('i', '', ['class' => 'fa ' . $icon . ' me-2', 'aria-hidden' => 'true'])
                . get_string($name, 'local_aicharts');
            $mform->addElement('header', $name . 'header', $title);
            $mform->addElement('textarea', $name, get_string($name, 'local_aicharts'), [
                'rows' => 3,
                'placeholder' => get_string($name . '_placeholder', 'local_aicharts'),
            ]);
            $mform->setType($name, PARAM_RAW);
            $mform->setExpanded($name . 'header', $this->optional_param($name, $chart->$name ?? '', PARAM_RAW) !== '');
        }

        $generate = $this->optional_param('generate', 0, PARAM_BOOL);
        $chartjson = $this->optional_param('chartjson', '', PARAM_RAW);
        $haspreview = $generate || $chartjson !== '' || $chart;

        $mform->registerNoSubmitButton('generate');
        $mform->addElement('submit', 'generate', get_string($haspreview ? 'regenerate' : 'generate', 'local_aicharts'));
        $mform->closeHeaderBefore('generate');
        $mform->addElement('static', 'provider', '', get_string('provider', 'local_aicharts', $this->provider_name()));

        if ($generate) {
            $request = $this->request_values();
            $result = chart_generator::generate(
                $request['prompt'],
                $request['schemahint'],
                $request['charthint'],
                $request['sqlhint'],
                $this->optional_param('maxrows', 0, PARAM_INT) ?: $this->default_maxrows(),
                (int) $USER->id,
            );
            $this->add_generated_preview($result, $request);
        } else if ($chartjson !== '') {
            $this->add_answer_elements(
                $this->optional_param('sqltext', '', PARAM_RAW),
                $this->optional_param('params', '', PARAM_RAW),
                get_string('generateagain', 'local_aicharts')
            );
        } else if ($chart) {
            $this->add_saved_preview($chart);
        }
    }

    /**
     * Append the preview of a saved chart, run from its stored query without asking the model.
     *
     * @param \stdClass $chart The chart being edited or duplicated.
     */
    protected function add_saved_preview(\stdClass $chart): void {
        $params = json_decode($chart->params, true);
        $params = is_array($params) ? $params : [];
        $query = query_runner::run($chart->sqltext, $params, (int) $chart->maxrows);

        $this->result = new generation_result(
            $query->has_error() ? $query->status : 'chart',
            $chart->name,
            $chart->sqltext,
            $params,
            $chart->chartjson,
            $query->rows,
            $query->rowcount,
            $query->truncated,
            $query->durationms,
            $query->errormessage,
        );
        $this->add_answer_elements($chart->sqltext, $chart->params, '');
        $this->_form->setConstants([
            'sqltext' => $chart->sqltext,
            'params' => $chart->params,
            'chartjson' => $chart->chartjson,
            'generatedfor' => $this->request_hash((array) $chart),
        ]);
    }

    /**
     * Append the preview of a fresh answer, or the refusal or error it produced.
     *
     * @param generation_result $result What the generator produced.
     * @param array $request Prompt and hints the answer was generated for.
     */
    protected function add_generated_preview(generation_result $result, array $request): void {
        $mform = $this->_form;

        if ($result->has_error()) {
            $mform->addElement('static', 'statebox', '', $this->statebox($result));
            return;
        }

        $this->result = $result;
        $mform->setDefault('name', $result->name);
        $params = json_encode($result->params ?: new \stdClass());
        $this->add_answer_elements($result->sql, $params, '');
        $mform->setConstants([
            'prompt' => $request['prompt'],
            'refine' => '',
            'sqltext' => $result->sql,
            'params' => $params,
            'chartjson' => $result->chartjson,
            'generatedfor' => $this->request_hash($request),
        ]);
    }

    /**
     * Render the chart here so that its script is collected with the form fragment.
     */
    #[\Override]
    public function definition_after_data() {
        global $PAGE;

        parent::definition_after_data();
        if ($this->result) {
            $html = $this->result->has_error() ? $this->statebox($this->result) : $this->preview_html($this->result);
            $this->_form->getElement('preview')->setText($html);
            $PAGE->requires->js_call_amd('local_aicharts/dashboard', 'focusPreview');
        }
    }

    /**
     * Append the name, preview area, SQL block, hidden answer fields and run mode.
     *
     * @param string $sql The generated query.
     * @param string $params JSON object of the query parameters.
     * @param string $previewhtml Chart, table or a line saying to generate again.
     */
    protected function add_answer_elements(string $sql, string $params, string $previewhtml): void {
        $mform = $this->_form;

        $heading = html_writer::tag('h5', get_string('preview', 'local_aicharts'), [
            'tabindex' => '-1',
            'data-region' => 'aic-preview',
            'class' => 'mt-3',
        ]);
        $mform->addElement('static', 'previewheading', '', $heading);

        $mform->addElement('text', 'name', get_string('chartname', 'local_aicharts'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('static', 'preview', '', $previewhtml);

        $mform->addElement('textarea', 'refine', get_string('refine', 'local_aicharts'), [
            'rows' => 2,
            'placeholder' => get_string('refine_placeholder', 'local_aicharts'),
        ]);
        $mform->setType('refine', PARAM_TEXT);
        $mform->addHelpButton('refine', 'refine', 'local_aicharts');

        $mform->addElement('static', 'sqlblock', '', $this->sql_html($sql, $params));

        foreach (['sqltext', 'params', 'chartjson', 'generatedfor'] as $name) {
            $mform->addElement('hidden', $name);
            $mform->setType($name, PARAM_RAW);
        }

        $modes = [];
        foreach (self::RUNMODES as $mode) {
            $modes[] = $mform->createElement('radio', 'runmode', '', get_string('runmode_' . $mode, 'local_aicharts'), $mode);
        }
        $mform->addGroup($modes, 'runmodegroup', get_string('runmode', 'local_aicharts'), ' ', false);
        $mform->setType('runmode', PARAM_ALPHA);
        $mform->setDefault('runmode', 'live');
        $mform->addHelpButton('runmodegroup', 'runmode', 'local_aicharts');
        $mform->addElement('static', 'runmodeinfo', '', $this->runmode_context_html());

        $hours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hours[$hour] = sprintf('%02d:00', $hour);
        }
        $mform->addElement('select', 'runhour', get_string('runtime', 'local_aicharts'), $hours);
        $mform->setType('runhour', PARAM_INT);
        $mform->setDefault('runhour', $this->default_runhour());
        $mform->addHelpButton('runhour', 'runtime', 'local_aicharts');
        $mform->hideIf('runhour', 'runmode', 'eq', 'live');

        $mform->addElement('static', 'runhourinfo', '', $this->schedule_context_html());
        $mform->hideIf('runhourinfo', 'runmode', 'eq', 'live');

        $this->add_email_elements();
    }

    /**
     * Append the recipient picker and the send-when radios, hidden while the item runs live.
     */
    protected function add_email_elements(): void {
        $mform = $this->_form;

        $title = html_writer::tag('i', '', ['class' => 'fa fa-envelope me-2', 'aria-hidden' => 'true'])
            . get_string('emailresult', 'local_aicharts');
        $mform->addElement('header', 'emailheader', $title);
        $mform->setExpanded('emailheader', true);

        $mform->addElement('autocomplete', 'emailto', get_string('emailto', 'local_aicharts'), $this->recipient_options(), [
            'multiple' => true,
            'noselectionstring' => get_string('emailnorecipients', 'local_aicharts'),
        ]);
        $mform->setType('emailto', PARAM_INT);
        $mform->addHelpButton('emailto', 'emailto', 'local_aicharts');
        $mform->hideIf('emailto', 'runmode', 'eq', 'live');

        $when = [
            $mform->createElement('radio', 'emailwhen', '', get_string('emailwhenalways', 'local_aicharts'), 'always'),
            $mform->createElement('radio', 'emailwhen', '', get_string('emailwhenrows', 'local_aicharts'), 'rows'),
        ];
        $mform->addGroup($when, 'emailwhengroup', get_string('emailwhen', 'local_aicharts'), ' ', false);
        $mform->setType('emailwhen', PARAM_ALPHA);
        $mform->setDefault('emailwhen', 'always');
        $mform->hideIf('emailwhengroup', 'runmode', 'eq', 'live');

        $mform->addElement('static', 'emailinfo', '', html_writer::div(
            get_string('emailrecipientshint', 'local_aicharts'),
            'text-muted small'
        ));
        $mform->hideIf('emailinfo', 'runmode', 'eq', 'live');
    }

    /**
     * The users that may be picked as recipients, newest name order, keyed by user id.
     *
     * @return string[]
     */
    protected function recipient_options(): array {
        $context = context_system::instance();
        $fields = 'u.id' . fields::for_name()->get_sql('u')->selects;
        $users = get_users_by_capability($context, 'local/aicharts:receiveresults', $fields, 'u.lastname, u.firstname', '', 200);

        $options = [];
        foreach ($users + get_admins() as $user) {
            $options[$user->id] = fullname($user);
        }
        return $options;
    }

    /**
     * The recipient ids as posted, before the select drops the ones it does not offer.
     *
     * @return int[]
     */
    protected function posted_recipients(): array {
        $raw = $this->_ajaxformdata['emailto'] ?? optional_param_array('emailto', [], PARAM_INT);
        return array_values(array_filter(array_map('intval', (array) $raw)));
    }

    /**
     * The posted recipient ids that still hold the capability to receive results.
     *
     * @return int[]
     */
    protected function allowed_recipients(): array {
        $context = context_system::instance();
        $allowed = [];
        foreach ($this->posted_recipients() as $userid) {
            if (has_capability('local/aicharts:receiveresults', $context, $userid)) {
                $allowed[] = $userid;
            }
        }
        return $allowed;
    }

    /**
     * The muted lines under the run-mode radios: what each mode does and how long the query took.
     *
     * @return string
     */
    protected function runmode_context_html(): string {
        $html = html_writer::div(get_string('runmodeinfo', 'local_aicharts'), 'text-muted small');
        if (!$this->result || $this->result->has_error()) {
            return $html;
        }

        $line = get_string('querytook', 'local_aicharts', $this->result->durationms);
        if ($this->result->durationms > 2000) {
            $line .= ' ' . get_string('considerscheduled', 'local_aicharts');
        }
        return $html . html_writer::div($line, 'text-muted small');
    }

    /**
     * The site timezone line and the next run of the posted or saved schedule.
     *
     * @return string
     */
    protected function schedule_context_html(): string {
        $timezone = core_date::get_server_timezone();
        $html = html_writer::div(get_string('runhourinfo', 'local_aicharts', $timezone), 'text-muted small');

        $chart = (object) ['runmode' => $this->current_runmode(), 'runhour' => $this->current_runhour()];
        $next = schedule::next_run($chart, time());
        if ($next) {
            $formatted = userdate($next, get_string('strftimedaydatetime', 'langconfig'), $timezone);
            $html .= html_writer::div(get_string('nextrun', 'local_aicharts', $formatted), 'text-muted small');
        }
        return $html;
    }

    /**
     * The run mode the form is showing: posted, saved, or live.
     *
     * @return string
     */
    protected function current_runmode(): string {
        $mode = $this->optional_param('runmode', '', PARAM_ALPHA);
        if (in_array($mode, self::RUNMODES, true)) {
            return $mode;
        }
        $chart = $this->saved_chart();
        return $chart && in_array($chart->runmode, self::RUNMODES, true) ? $chart->runmode : schedule::MODE_LIVE;
    }

    /**
     * The run hour the form is showing: posted, saved, or the site default.
     *
     * @return int
     */
    protected function current_runhour(): int {
        $hour = $this->optional_param('runhour', -1, PARAM_INT);
        if ($hour >= 0 && $hour <= 23) {
            return $hour;
        }
        $chart = $this->saved_chart();
        return $chart ? (int) $chart->runhour : $this->default_runhour();
    }

    /**
     * The run hour a new chart starts with.
     *
     * @return int
     */
    protected function default_runhour(): int {
        $hour = (int) get_config('local_aicharts', 'runhour');
        return $hour >= 0 && $hour <= 23 ? $hour : 0;
    }

    /**
     * The chart being edited, if any.
     *
     * @return \stdClass|null
     */
    protected function saved_chart(): ?\stdClass {
        $id = $this->optional_param('id', 0, PARAM_INT);
        return $id ? chart_repository::get($id) : null;
    }

    /**
     * The rendered chart or table with its row count, notes and live-data notice.
     *
     * @param generation_result $result A successful generation.
     * @return string
     */
    protected function preview_html(generation_result $result): string {
        global $OUTPUT;

        try {
            $spec = chart_spec::from_json($result->chartjson);
            if ($spec->is_table()) {
                $html = $OUTPUT->render(new result_table($result->rows));
            } else {
                $html = $OUTPUT->render(chart_factory::create($spec, $result->rows));
            }
        } catch (moodle_exception $e) {
            $icon = $e->errorcode === 'norows' ? 'fa-circle-info' : 'fa-triangle-exclamation';
            $html = $this->statebox_html($icon, $e->getMessage());
        }

        $count = get_string('previewrowcount', 'local_aicharts', ['rows' => $result->rowcount, 'ms' => $result->durationms]);
        if ($result->truncated) {
            $count .= ' ' . get_string('previewtruncated', 'local_aicharts', $result->rowcount);
        }
        $html .= html_writer::div($count, 'text-muted small mt-2');
        if ($result->notes !== '') {
            $notes = html_writer::tag('i', '', ['class' => 'fa fa-circle-info me-1', 'aria-hidden' => 'true'])
                . get_string('modelnotes', 'local_aicharts', s($result->notes));
            $html .= html_writer::div($notes, 'text-muted small');
        }
        $html .= html_writer::div(get_string('livenotice', 'local_aicharts'), 'text-muted small');
        return $html;
    }

    /**
     * The SQL block: an open details element with the query and its parameters.
     *
     * @param string $sql The generated query.
     * @param string $params JSON object of the query parameters.
     * @return string
     */
    protected function sql_html(string $sql, string $params): string {
        $live = $this->current_runmode() === schedule::MODE_LIVE;
        $summary = html_writer::tag('summary', get_string($live ? 'sqlrunsonload' : 'generatedsql', 'local_aicharts'));
        $pre = html_writer::tag('pre', s($sql), ['class' => 'mb-1', 'style' => 'white-space: pre-wrap;']);
        $html = $summary . $pre;

        $values = json_decode($params, true);
        if (is_array($values) && $values) {
            $list = [];
            foreach ($values as $name => $value) {
                $list[] = $name . '=' . $value;
            }
            $html .= html_writer::div(
                get_string('queryparams', 'local_aicharts', s(implode(', ', $list))),
                'text-muted small'
            );
        }
        return html_writer::tag('details', $html, ['open' => 'open', 'class' => 'mt-2']);
    }

    /**
     * The refusal or error box of a failed generation.
     *
     * @param generation_result $result A generation without rows.
     * @return string
     */
    protected function statebox(generation_result $result): string {
        if ($result->status === 'refused') {
            $body = get_string('refusal', 'local_aicharts')
                . html_writer::div(get_string('refusalexamples', 'local_aicharts'), 'mt-1')
                . html_writer::div(get_string('refusalnotrun', 'local_aicharts'), 'text-muted small mt-1');
            return $this->statebox_html('fa-ban', $body);
        }

        if ($result->status === 'validation_failed') {
            $message = $result->errormessage !== ''
                ? s($result->errormessage)
                : get_string('error_validation', 'local_aicharts');
            $body = $message . html_writer::div($this->settings_link(), 'mt-1');
            return $this->statebox_html('fa-triangle-exclamation', $body);
        }

        if ($result->status === 'llm_error' && $result->errorcode === 'nokey') {
            $body = get_string('error_llm_nokey', 'local_aicharts') . html_writer::div($this->settings_link(), 'mt-1');
            return $this->statebox_html('fa-triangle-exclamation', $body);
        }

        $strings = [
            'invalid_json' => 'error_invalidjson',
            'db_error' => 'error_db',
            'llm_error' => $result->errorcode === 'badanswer' ? 'error_llm_badanswer' : 'error_llm_noanswer',
        ];
        $body = get_string($strings[$result->status] ?? 'error_llm_noanswer', 'local_aicharts');
        if ($result->errormessage !== '') {
            $body .= html_writer::tag(
                'details',
                html_writer::tag('summary', get_string('errordetails', 'local_aicharts')) . s($result->errormessage),
                ['class' => 'small mt-1']
            );
        }
        return $this->statebox_html('fa-triangle-exclamation', $body);
    }

    /**
     * A neutral box with an icon and a message.
     *
     * @param string $icon Font Awesome class.
     * @param string $body Message HTML.
     * @return string
     */
    protected function statebox_html(string $icon, string $body): string {
        $icon = html_writer::tag('i', '', ['class' => 'fa ' . $icon . ' me-2', 'aria-hidden' => 'true']);
        return html_writer::div($icon . $body, 'border rounded p-3 mt-2', ['role' => 'status']);
    }

    /**
     * A link to the plugin settings page.
     *
     * @return string
     */
    protected function settings_link(): string {
        $url = new moodle_url('/admin/settings.php', ['section' => 'local_aicharts']);
        $icon = html_writer::tag('i', '', ['class' => 'fa fa-gear me-1', 'aria-hidden' => 'true']);
        return html_writer::link($url, $icon . get_string('opensettings', 'local_aicharts'));
    }

    /**
     * The row limit a new chart starts with.
     *
     * @return int
     */
    protected function default_maxrows(): int {
        return max(1, (int) get_config('local_aicharts', 'maxrowsdefault'));
    }

    /**
     * The highest row limit the site allows.
     *
     * @return int
     */
    protected function site_maxrows(): int {
        return max(1, (int) get_config('local_aicharts', 'maxrowsmax'));
    }

    /**
     * The name of the configured provider.
     *
     * @return string
     */
    protected function provider_name(): string {
        $type = get_config('local_aicharts', 'clienttype') === 'openai' ? 'openai' : 'stub';
        return get_string('clienttype' . $type, 'local_aicharts');
    }

    /**
     * The posted prompt and hints.
     *
     * @return string[]
     */
    protected function request_values(): array {
        $prompt = $this->optional_param('prompt', '', PARAM_TEXT);
        $refine = trim($this->optional_param('refine', '', PARAM_TEXT));
        if ($refine !== '') {
            $prompt .= "\n\n" . get_string('refinement', 'local_aicharts', $refine);
        }
        return [
            'prompt' => $prompt,
            'schemahint' => $this->optional_param('schemahint', '', PARAM_RAW),
            'charthint' => $this->optional_param('charthint', '', PARAM_RAW),
            'sqlhint' => $this->optional_param('sqlhint', '', PARAM_RAW),
        ];
    }

    /**
     * Hash of the request a preview was generated for.
     *
     * @param array $data Prompt and hints.
     * @return string
     */
    protected function request_hash(array $data): string {
        $parts = [];
        foreach (['prompt', 'schemahint', 'charthint', 'sqlhint'] as $name) {
            $parts[] = (string) ($data[$name] ?? '');
        }
        return sha1(implode('|', $parts));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $maxrows = (int) ($data['maxrows'] ?? 0);
        if ($maxrows < 1 || $maxrows > $this->site_maxrows()) {
            $errors['maxrows'] = get_string('maxrowsinvalid', 'local_aicharts', $this->site_maxrows());
        }

        $runmode = $data['runmode'] ?? schedule::MODE_LIVE;
        if ($runmode !== schedule::MODE_LIVE) {
            $runhour = (int) ($data['runhour'] ?? -1);
            if ($runhour < 0 || $runhour > 23) {
                $errors['runhour'] = get_string('runhourinvalid', 'local_aicharts');
            }
            if (count($this->posted_recipients()) !== count($this->allowed_recipients())) {
                $errors['emailto'] = get_string('emailtoinvalid', 'local_aicharts');
            }
        }

        if (empty($data['chartjson'])) {
            $errors['prompt'] = get_string('generatefirst', 'local_aicharts');
            return $errors;
        }
        if (($data['generatedfor'] ?? '') !== $this->request_hash($data)) {
            $errors['prompt'] = get_string('promptchanged', 'local_aicharts');
            return $errors;
        }

        try {
            $params = json_decode($data['params'] ?? '', true);
            sql_validator::validate((string) $data['sqltext'], is_array($params) ? $params : []);
            chart_spec::from_json($data['chartjson']);
        } catch (moodle_exception $e) {
            $errors['prompt'] = $e->getMessage();
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
        $id = $this->optional_param('id', 0, PARAM_INT);
        if ($id && ($chart = chart_repository::get($id))) {
            $chart->emailto = array_filter(array_map('intval', explode(',', (string) $chart->emailto)));
            if ($this->optional_param('duplicate', 0, PARAM_BOOL)) {
                $chart->id = 0;
                $chart->name = get_string('duplicatename', 'local_aicharts', $chart->name);
            }
            $this->set_data($chart);
        }
    }

    #[\Override]
    public function process_dynamic_submission() {
        $data = $this->get_data();
        $runmode = in_array($data->runmode, self::RUNMODES, true) ? $data->runmode : schedule::MODE_LIVE;
        $live = $runmode === schedule::MODE_LIVE;

        $id = chart_repository::save((object) [
            'id' => $data->id ?? 0,
            'name' => $data->name,
            'prompt' => $data->prompt,
            'schemahint' => $data->schemahint ?? '',
            'charthint' => $data->charthint ?? '',
            'sqlhint' => $data->sqlhint ?? '',
            'sqltext' => $data->sqltext,
            'params' => $data->params,
            'chartjson' => $data->chartjson,
            'maxrows' => (int) $data->maxrows,
            'runmode' => $runmode,
            'runhour' => (int) ($data->runhour ?? $this->default_runhour()),
            'emailto' => $live ? '' : implode(',', $this->allowed_recipients()),
            'emailwhen' => !$live && ($data->emailwhen ?? '') === 'rows' ? 'rows' : 'always',
        ]);
        $this->run_first_result($id);
        notification::success(get_string('chartsaved', 'local_aicharts'));
        return ['id' => $id];
    }

    /**
     * Gives a scheduled chart a result straight away, so its card is not empty until cron runs.
     *
     * @param int $id Chart id.
     */
    protected function run_first_result(int $id): void {
        global $USER;

        $chart = chart_repository::get($id);
        if (!$chart || $chart->runmode === schedule::MODE_LIVE || result_store::get_latest_ok($id)) {
            return;
        }

        run_chart::run($chart, 'save', (int) $USER->id);
    }

    #[\Override]
    protected function get_page_url_for_dynamic_submission(): moodle_url {
        return new moodle_url('/local/aicharts/index.php');
    }
}
