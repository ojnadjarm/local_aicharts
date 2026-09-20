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

use local_aicharts\local\wizard_state;

/**
 * Tests for the Kind step of the wizard.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\form\step_kind
 */
final class step_kind_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * The step form of a fresh wizard.
     *
     * @param string $kind Kind already chosen, empty for none.
     * @return step_kind
     */
    protected function form(string $kind = ''): step_kind {
        $state = wizard_state::open(wizard_state::start(null));
        $state->data->kind = $kind;
        return new step_kind($state->url(1)->out(false), ['state' => $state], 'post', '', ['id' => 'local-aicharts-step']);
    }

    /**
     * Next without a kind is refused; each kind is accepted.
     */
    public function test_next_requires_a_kind(): void {
        $form = $this->form();

        $errors = $form->validation([], []);
        $this->assertSame(get_string('kindrequired', 'local_aicharts'), $errors['kindgroup']);

        $errors = $form->validation(['kind' => 'pie'], []);
        $this->assertSame(get_string('kindrequired', 'local_aicharts'), $errors['kindgroup']);

        $this->assertSame([], $form->validation(['kind' => 'oneshot'], []));
        $this->assertSame([], $form->validation(['kind' => 'trend'], []));
    }

    /**
     * Editing a chart preselects its kind; a new wizard selects none.
     */
    public function test_edit_prefills_saved_kind(): void {
        $html = $this->form('trend')->render();
        $this->assertMatchesRegularExpression('/name="kind"[^>]*value="trend"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="kind"[^>]*value="oneshot"[^>]*checked/', $html);
        $this->assertStringContainsString(get_string('kindtrend_example', 'local_aicharts'), $html);
        $this->assertStringContainsString(get_string('kindoneshot_example', 'local_aicharts'), $html);

        $html = $this->form()->render();
        $this->assertStringNotContainsString('checked', $html);
    }
}
