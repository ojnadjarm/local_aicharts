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
use local_aicharts\local\wizard_state;

/**
 * One page of the chart wizard: heading, step strip, the step form and the button row.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_page implements renderable, templatable {
    /** @var string Id of the step form the Next button submits. */
    public const FORM_ID = 'local-aicharts-step';

    /**
     * Constructor.
     *
     * @param wizard_state $state The wizard.
     * @param int $step The step shown, 1 to 5.
     * @param string $formhtml The rendered step form, or a placeholder.
     * @param bool $hasnext Whether the step has a Next button.
     * @param string $asidehtml What is shown beside the form, empty for nothing.
     * @param bool $hassave Whether the step offers Save.
     */
    public function __construct(
        /** @var wizard_state The wizard. */
        protected wizard_state $state,
        /** @var int The step shown. */
        protected int $step,
        /** @var string The rendered step form. */
        protected string $formhtml,
        /** @var bool Whether the step has a Next button. */
        protected bool $hasnext = true,
        /** @var string What is shown beside the form. */
        protected string $asidehtml = '',
        /** @var bool Whether the step offers Save. */
        protected bool $hassave = false,
    ) {
    }

    /**
     * The heading of the wizard: add, edit or duplicate.
     *
     * @return string
     */
    public function title(): string {
        $data = $this->state->data;
        if ($data->duplicate) {
            return get_string('duplicatechart', 'local_aicharts');
        }
        return get_string($data->id ? 'editchart' : 'addchart', 'local_aicharts');
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        $steps = [];
        foreach (wizard_state::STEPS as $offset => $name) {
            $number = $offset + 1;
            $steps[] = [
                'number' => $number,
                'name' => get_string('step_' . $name, 'local_aicharts'),
                'done' => $number < $this->step,
                'current' => $number === $this->step,
                'url' => $number < $this->step ? $this->state->url($number)->out(false) : '',
            ];
        }
        $about = get_string('stepabout_' . wizard_state::STEPS[$this->step - 1], 'local_aicharts');
        $total = count(wizard_state::STEPS);

        return [
            'title' => $this->title(),
            'steps' => $steps,
            'stepof' => get_string('stepof', 'local_aicharts', ['n' => $this->step, 'total' => $total, 'about' => $about]),
            'formhtml' => $this->formhtml,
            'asidehtml' => $this->asidehtml,
            'formid' => self::FORM_ID,
            'hasnext' => $this->hasnext,
            'hassave' => $this->hassave,
            'backurl' => $this->step > 1 ? $this->state->url($this->step - 1)->out(false) : '',
            'cancelurl' => $this->state->action_url('discard')->out(false),
            'token' => $this->state->token,
            'url' => $this->state->url($this->step)->out(false),
            'timeout' => (int) get_config('local_aicharts', 'timeout'),
            'querytimeout' => (int) get_config('local_aicharts', 'querytimeout'),
        ];
    }
}
