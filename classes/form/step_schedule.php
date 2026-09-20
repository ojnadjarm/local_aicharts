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

use context_system;
use core_calendar\type_factory;
use core_date;
use core_user\fields;
use html_writer;
use local_aicharts\local\point_store;
use local_aicharts\local\schedule;
use local_aicharts\local\wizard_state;
use moodleform;
use stdClass;

/**
 * Wizard step 4: the row limit, when the chart runs, how many points a trend keeps and who receives the result.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_schedule extends moodleform {
    /** @var string[] Run modes a trend chart may use. */
    protected const SCHEDULED = ['daily', 'weekly', 'monthly'];

    #[\Override]
    protected function definition() {
        $mform = $this->_form;
        $state = $this->_customdata['state'];
        $trend = $state->data->kind === 'trend';

        $mform->addElement('hidden', 'step', 4);
        $mform->setType('step', PARAM_INT);

        $mform->addElement('text', 'maxrows', get_string('maxrows', 'local_aicharts'), ['size' => 6]);
        $mform->setType('maxrows', PARAM_INT);
        $mform->setDefault('maxrows', $state->data->maxrows);
        $maxrowsinfo = get_string('maxrowsinfo', 'local_aicharts', $this->site_maxrows());
        $mform->addElement('static', 'maxrowsinfo', '', html_writer::div($maxrowsinfo, 'text-muted small'));

        $modeinfo = get_string($trend ? 'trendneedsschedule' : 'runmodeinfo', 'local_aicharts');
        $modeinfo = html_writer::div($modeinfo, 'text-muted small');
        $this->add_schedule_elements($trend ? self::SCHEDULED : schedule::MODES, $modeinfo);

        if ($trend) {
            $sitedefault = point_store::retention((object) ['pointsretention' => 0]);
            $mform->addElement('text', 'pointsretention', get_string('pointsretention_chart', 'local_aicharts'), ['size' => 6]);
            $mform->setType('pointsretention', PARAM_INT);
            $mform->setDefault('pointsretention', point_store::retention($state->data));
            $mform->addHelpButton('pointsretention', 'pointsretention_chart', 'local_aicharts');
            $mform->addElement('static', 'pointsretentioninfo', '', html_writer::div(
                get_string('pointsretention_sitedefault', 'local_aicharts', $sitedefault),
                'text-muted small'
            ));
        }

        $recipients = array_filter(array_map('intval', explode(',', (string) $state->data->emailto)));
        $this->add_email_elements(array_values($recipients), $state->data->emailwhen);
    }

    /**
     * The schedule the form falls back to when nothing is posted: runmode, runhour and runday.
     *
     * @return stdClass
     */
    protected function schedule_defaults(): stdClass {
        $data = $this->_customdata['state']->data;
        $runmode = $data->runmode;
        if ($data->kind === 'trend' && !in_array($runmode, self::SCHEDULED, true)) {
            $runmode = 'daily';
        }
        return (object) ['runmode' => $runmode, 'runhour' => (int) $data->runhour, 'runday' => (int) $data->runday];
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
     * Writes the validated schedule into the wizard.
     *
     * @param stdClass $data The submitted data.
     * @param wizard_state $state The wizard.
     */
    public function apply(stdClass $data, wizard_state $state): void {
        $live = $data->runmode === schedule::MODE_LIVE;
        $state->data->maxrows = (int) $data->maxrows;
        $state->data->runmode = $data->runmode;
        $state->data->runhour = (int) $data->runhour;
        $state->data->runday = $this->posted_runday($data->runmode, $data);
        if ($state->data->kind === 'trend') {
            $state->data->pointsretention = max(point_store::RETENTION_FLOOR, (int) $data->pointsretention);
        }
        $state->data->emailto = $live ? '' : implode(',', $this->allowed_recipients());
        $state->data->emailwhen = !$live && ($data->emailwhen ?? '') === 'rows' ? 'rows' : 'always';
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $state = $this->_customdata['state'];

        $maxrows = (int) ($data['maxrows'] ?? 0);
        if ($maxrows < 1 || $maxrows > $this->site_maxrows()) {
            $errors['maxrows'] = get_string('maxrowsinvalid', 'local_aicharts', $this->site_maxrows());
        }
        $runmode = $data['runmode'] ?? '';
        $modes = $state->data->kind === 'trend' ? self::SCHEDULED : schedule::MODES;
        if (!in_array($runmode, $modes, true)) {
            $errors['runmodegroup'] = $state->data->kind === 'trend'
                ? get_string('trendneedsschedule', 'local_aicharts')
                : get_string('runmodeinvalid', 'local_aicharts');
            return $errors;
        }
        return $errors + $this->schedule_errors($data);
    }

    /**
     * Append the run mode radios, the hour and day selectors and the next run lines.
     *
     * @param string[] $modes The run modes offered.
     * @param string $modeinfo The muted lines under the radios.
     */
    protected function add_schedule_elements(array $modes, string $modeinfo): void {
        $mform = $this->_form;
        $defaults = $this->schedule_defaults();

        $radios = [];
        foreach ($modes as $mode) {
            $radios[] = $mform->createElement('radio', 'runmode', '', get_string('runmode_' . $mode, 'local_aicharts'), $mode);
        }
        $mform->addGroup($radios, 'runmodegroup', get_string('runmode', 'local_aicharts'), ' ', false);
        $mform->setType('runmode', PARAM_ALPHA);
        $mform->setDefault('runmode', $defaults->runmode);
        $mform->addHelpButton('runmodegroup', 'runmode', 'local_aicharts');
        $mform->addElement('static', 'runmodeinfo', '', $modeinfo);

        $hours = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $hours[$hour] = sprintf('%02d:00', $hour);
        }
        $mform->addElement('select', 'runhour', get_string('runtime', 'local_aicharts'), $hours);
        $mform->setType('runhour', PARAM_INT);
        $mform->setDefault('runhour', $defaults->runhour);
        $mform->addHelpButton('runhour', 'runtime', 'local_aicharts');
        $mform->hideIf('runhour', 'runmode', 'eq', schedule::MODE_LIVE);

        $weekdays = [];
        $start = $this->default_weekday();
        for ($offset = 0; $offset < 7; $offset++) {
            $weekday = ($start + $offset) % 7;
            $weekdays[$weekday] = schedule::weekday_name($weekday);
        }
        $mform->addElement('select', 'runweekday', get_string('runweekday', 'local_aicharts'), $weekdays);
        $mform->setType('runweekday', PARAM_INT);
        $mform->setDefault('runweekday', $defaults->runmode === 'weekly' ? $defaults->runday : $start);
        $mform->addHelpButton('runweekday', 'runday', 'local_aicharts');
        $mform->hideIf('runweekday', 'runmode', 'neq', 'weekly');

        $monthdays = array_combine(range(1, 28), range(1, 28));
        $monthdays[schedule::LAST_DAY] = get_string('lastdayofmonth', 'local_aicharts');
        $mform->addElement('select', 'runmonthday', get_string('runmonthday', 'local_aicharts'), $monthdays);
        $mform->setType('runmonthday', PARAM_INT);
        $mform->setDefault('runmonthday', $defaults->runmode === 'monthly' ? $defaults->runday : 1);
        $mform->addHelpButton('runmonthday', 'runday', 'local_aicharts');
        $mform->hideIf('runmonthday', 'runmode', 'neq', 'monthly');

        $timezone = get_string('runhourinfo', 'local_aicharts', core_date::get_server_timezone());
        $mform->addElement('static', 'runhourinfo', '', html_writer::div($timezone, 'text-muted small'));
        $mform->hideIf('runhourinfo', 'runmode', 'eq', schedule::MODE_LIVE);
        foreach (['daily', 'weekly', 'monthly'] as $mode) {
            $mform->addElement('static', 'nextrun_' . $mode, '', $this->schedule_context_html($mode));
            $mform->hideIf('nextrun_' . $mode, 'runmode', 'neq', $mode);
        }
    }

    /**
     * Append the recipient picker and the send-when radios, hidden while the item runs live.
     *
     * @param int[] $recipients The recipients preselected.
     * @param string $emailwhen The send-when option preselected.
     */
    protected function add_email_elements(array $recipients = [], string $emailwhen = 'always'): void {
        $mform = $this->_form;

        $title = html_writer::tag('i', '', ['class' => 'fa fa-envelope me-2', 'aria-hidden' => 'true'])
            . get_string('emailresult', 'local_aicharts');
        $mform->addElement('header', 'emailheader', $title);
        $mform->setExpanded('emailheader', true);
        $mform->hideIf('emailheader', 'runmode', 'eq', schedule::MODE_LIVE);

        $mform->addElement('autocomplete', 'emailto', get_string('emailto', 'local_aicharts'), $this->recipient_options(), [
            'multiple' => true,
            'noselectionstring' => get_string('emailnorecipients', 'local_aicharts'),
        ]);
        $mform->setType('emailto', PARAM_INT);
        $mform->setDefault('emailto', $recipients);
        $mform->addHelpButton('emailto', 'emailto', 'local_aicharts');

        $when = [
            $mform->createElement('radio', 'emailwhen', '', get_string('emailwhenalways', 'local_aicharts'), 'always'),
            $mform->createElement('radio', 'emailwhen', '', get_string('emailwhenrows', 'local_aicharts'), 'rows'),
        ];
        $mform->addGroup($when, 'emailwhengroup', get_string('emailwhen', 'local_aicharts'), ' ', false);
        $mform->setType('emailwhen', PARAM_ALPHA);
        $mform->setDefault('emailwhen', $emailwhen);

        $mform->addElement('static', 'emailinfo', '', html_writer::div(
            get_string('emailrecipientshint', 'local_aicharts'),
            'text-muted small'
        ));
    }

    /**
     * The errors of the posted schedule: hour, day and recipients of a scheduled mode.
     *
     * @param array $data The submitted data.
     * @return string[] Keyed by element name.
     */
    protected function schedule_errors(array $data): array {
        $errors = [];
        $runmode = $data['runmode'] ?? schedule::MODE_LIVE;
        if ($runmode === schedule::MODE_LIVE) {
            return $errors;
        }
        $runhour = (int) ($data['runhour'] ?? -1);
        if ($runhour < 0 || $runhour > 23) {
            $errors['runhour'] = get_string('runhourinvalid', 'local_aicharts');
        }
        $field = $runmode === 'weekly' ? 'runweekday' : 'runmonthday';
        if (!$this->valid_runday($runmode, (int) ($data[$field] ?? -1))) {
            $errors[$field] = get_string('rundayinvalid', 'local_aicharts');
        }
        if (count($this->posted_recipients()) !== count($this->allowed_recipients())) {
            $errors['emailto'] = get_string('emailtoinvalid', 'local_aicharts');
        }
        return $errors;
    }

    /**
     * The run day to store for a mode: the weekday or month day of its select, 1 otherwise.
     *
     * @param string $runmode One of schedule::MODES.
     * @param stdClass $data The submitted data.
     * @return int
     */
    protected function posted_runday(string $runmode, stdClass $data): int {
        if ($runmode === 'weekly') {
            return (int) $data->runweekday;
        }
        return $runmode === 'monthly' ? (int) $data->runmonthday : 1;
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
     * The autocomplete posts an empty string rather than an array when nothing is selected, and
     * optional_param_array() raises a debugging message on that, so the type is checked first.
     *
     * @return int[]
     */
    protected function posted_recipients(): array {
        $raw = $this->_ajaxformdata['emailto'] ?? null;
        if ($raw === null) {
            $posted = data_submitted();
            $raw = is_array($posted->emailto ?? null) ? optional_param_array('emailto', [], PARAM_INT) : [];
        }
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
     * The next run line of a scheduled mode, for the hour and day the form is showing.
     *
     * @param string $runmode daily, weekly or monthly.
     * @return string
     */
    protected function schedule_context_html(string $runmode): string {
        $chart = (object) [
            'runmode' => $runmode,
            'runhour' => $this->current_runhour(),
            'runday' => $this->current_runday($runmode),
        ];
        $next = schedule::next_run($chart, time());
        if (!$next) {
            return '';
        }
        $formatted = userdate($next, get_string('strftimedaydatetime', 'langconfig'), core_date::get_server_timezone());
        return html_writer::div(get_string('nextrun', 'local_aicharts', $formatted), 'text-muted small');
    }

    /**
     * The run hour the form is showing: posted, or the default.
     *
     * @return int
     */
    protected function current_runhour(): int {
        $hour = $this->optional_param('runhour', -1, PARAM_INT);
        if ($hour >= 0 && $hour <= 23) {
            return $hour;
        }
        return (int) $this->schedule_defaults()->runhour;
    }

    /**
     * The run day the form is showing for a mode: posted, or the default.
     *
     * @param string $runmode daily, weekly or monthly.
     * @return int Weekday 0 to 6 for weekly, day of the month for monthly, 1 otherwise.
     */
    protected function current_runday(string $runmode): int {
        if ($runmode !== 'weekly' && $runmode !== 'monthly') {
            return 1;
        }
        $field = $runmode === 'weekly' ? 'runweekday' : 'runmonthday';
        $day = $this->optional_param($field, -1, PARAM_INT);
        if ($this->valid_runday($runmode, $day)) {
            return $day;
        }
        $defaults = $this->schedule_defaults();
        if ($defaults->runmode === $runmode) {
            return (int) $defaults->runday;
        }
        return $runmode === 'weekly' ? $this->default_weekday() : 1;
    }

    /**
     * Whether a day is one the select of the mode offers.
     *
     * @param string $runmode daily, weekly or monthly.
     * @param int $day The day to check.
     * @return bool
     */
    protected function valid_runday(string $runmode, int $day): bool {
        if ($runmode === 'weekly') {
            return $day >= 0 && $day <= 6;
        }
        if ($runmode === 'monthly') {
            return ($day >= 1 && $day <= 28) || $day === schedule::LAST_DAY;
        }
        return true;
    }

    /**
     * The weekday a new weekly chart starts with: the first day of the site week.
     *
     * @return int
     */
    protected function default_weekday(): int {
        return (int) type_factory::get_calendar_instance()->get_starting_weekday();
    }
}
