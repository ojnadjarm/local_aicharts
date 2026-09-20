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
use local_aicharts\local\wizard_state;
use moodleform;

/**
 * Wizard step 1: the kind of chart, one-shot or trend.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_kind extends moodleform {
    /** @var string[] Icon of each kind. */
    protected const ICONS = ['oneshot' => 'fa-chart-column', 'trend' => 'fa-arrow-trend-up'];

    #[\Override]
    protected function definition() {
        global $OUTPUT;

        $mform = $this->_form;
        $state = $this->_customdata['state'];

        $cards = [];
        foreach (wizard_state::KINDS as $kind) {
            $label = $OUTPUT->render_from_template('local_aicharts/kind_choice', [
                'icon' => self::ICONS[$kind],
                'title' => get_string('kind' . $kind, 'local_aicharts'),
                'description' => get_string('kind' . $kind . '_desc', 'local_aicharts'),
                'example' => get_string('kind' . $kind . '_example', 'local_aicharts'),
            ]);
            $cards[] = $mform->createElement('radio', 'kind', '', $label, $kind);
        }
        $mform->addGroup($cards, 'kindgroup', get_string('kindquestion', 'local_aicharts'), '', false);
        $mform->setType('kind', PARAM_ALPHA);
        if ($state->data->kind !== '') {
            $mform->setDefault('kind', $state->data->kind);
        }
        $mform->addElement('static', 'kindnote', '', html_writer::div(get_string('kindnote', 'local_aicharts'), 'form-text'));
    }

    #[\Override]
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!in_array($data['kind'] ?? '', wizard_state::KINDS, true)) {
            $errors['kindgroup'] = get_string('kindrequired', 'local_aicharts');
        }
        return $errors;
    }
}
