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

use local_aicharts\local\schedule;
use local_aicharts\local\wizard_state;

/**
 * Tests for the Schedule step of the wizard.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\form\step_schedule
 */
final class step_schedule_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('maxrowsdefault', 100, 'local_aicharts');
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('runhour', 4, 'local_aicharts');
        set_config('pointsretention', 365, 'local_aicharts');
    }

    /**
     * A wizard of the given kind past the Chart step.
     *
     * @param string $kind Chart kind.
     * @return wizard_state
     */
    protected function state(string $kind = 'oneshot'): wizard_state {
        $state = wizard_state::open(wizard_state::start(null));
        $state->data->kind = $kind;
        $state->data->columns = ['label', 'total'];
        $state->data->chartjson = '{"type":"bar","labelcolumn":"label","series":[{"column":"total","label":"Total"}]}';
        return $state;
    }

    /**
     * The step form of a wizard.
     *
     * @param wizard_state $state The wizard.
     * @return step_schedule
     */
    protected function form(wizard_state $state): step_schedule {
        return new step_schedule($state->url(4)->out(false), ['state' => $state], 'post', '', ['id' => 'local-aicharts-step']);
    }

    /**
     * The values a valid daily schedule posts.
     *
     * @return array
     */
    protected function daily_values(): array {
        return [
            'maxrows' => 100,
            'runmode' => 'daily',
            'runhour' => 6,
            'runweekday' => 1,
            'runmonthday' => 1,
            'emailwhen' => 'always',
        ];
    }

    /**
     * A trend chart is not offered Live and refuses it when posted.
     */
    public function test_trend_refuses_live(): void {
        $form = $this->form($this->state('trend'));
        $html = $form->render();

        $this->assertDoesNotMatchRegularExpression('/name="runmode"[^>]*value="live"/', $html);
        $this->assertMatchesRegularExpression('/name="runmode"[^>]*value="daily"[^>]*checked/', $html);
        $this->assertStringContainsString(get_string('trendneedsschedule', 'local_aicharts'), $html);

        $errors = $form->validation(['runmode' => 'live'] + $this->daily_values(), []);
        $this->assertSame(get_string('trendneedsschedule', 'local_aicharts'), $errors['runmodegroup']);

        $html = $this->form($this->state())->render();
        $this->assertMatchesRegularExpression('/name="runmode"[^>]*value="live"[^>]*checked/', $html);
        $this->assertStringContainsString(get_string('runmodeinfo', 'local_aicharts'), $html);
    }

    /**
     * Next stores the weekday of a weekly chart and the page shows its next run.
     */
    public function test_weekly_stores_weekday(): void {
        $state = $this->state();
        step_schedule::mock_submit(['runmode' => 'weekly', 'runweekday' => 3, 'runhour' => 6] + $this->daily_values());
        $form = $this->form($state);

        $chart = (object) ['runmode' => 'weekly', 'runhour' => 6, 'runday' => 3];
        $timezone = \core_date::get_server_timezone();
        $next = userdate(schedule::next_run($chart, time()), get_string('strftimedaydatetime', 'langconfig'), $timezone);
        $this->assertStringContainsString(get_string('nextrun', 'local_aicharts', $next), $form->render());

        $data = $form->get_data();
        $this->assertNotNull($data);
        $form->apply($data, $state);
        $this->assertSame('weekly', $state->data->runmode);
        $this->assertSame(6, $state->data->runhour);
        $this->assertSame(3, $state->data->runday);
        $this->assertSame(100, $state->data->maxrows);
        $this->assertSame('', $state->data->emailto);

        $html = $this->form($state)->render();
        $this->assertMatchesRegularExpression('/name="runmode"[^>]*value="weekly"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="runweekday".*?<option[^>]*value="3"[^>]*selected/s', $html);
    }

    /**
     * Only users who may receive results are offered and kept as recipients.
     */
    public function test_recipients_filtered_by_capability(): void {
        $recipient = $this->getDataGenerator()->create_user(['lastname' => 'Recipientname']);
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/aicharts:receiveresults', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $recipient->id, \context_system::instance()->id);
        $other = $this->getDataGenerator()->create_user(['lastname' => 'Othername']);
        $state = $this->state();

        step_schedule::mock_submit(['emailto' => [$recipient->id, $other->id], 'emailwhen' => 'rows'] + $this->daily_values());
        $form = $this->form($state);
        $this->assertNull($form->get_data());
        $html = $form->render();
        $this->assertStringContainsString('Recipientname', $html);
        $this->assertStringNotContainsString('Othername', $html);
        $this->assertStringContainsString(get_string('emailtoinvalid', 'local_aicharts'), $html);

        step_schedule::mock_submit(['emailto' => [$recipient->id], 'emailwhen' => 'rows'] + $this->daily_values());
        $form = $this->form($state);
        $form->apply($form->get_data(), $state);
        $this->assertSame((string) $recipient->id, $state->data->emailto);
        $this->assertSame('rows', $state->data->emailwhen);

        step_schedule::mock_submit(['runmode' => 'live', 'emailto' => [$recipient->id]] + $this->daily_values());
        $form = $this->form($state);
        $form->apply($form->get_data(), $state);
        $this->assertSame('', $state->data->emailto);
        $this->assertSame('always', $state->data->emailwhen);
    }

    /**
     * A row limit above the site maximum is refused and the message names the maximum.
     */
    public function test_rejects_maxrows_above_maximum(): void {
        $state = $this->state();

        step_schedule::mock_submit(['maxrows' => 501] + $this->daily_values());
        $form = $this->form($state);
        $this->assertNull($form->get_data());
        $this->assertStringContainsString(get_string('maxrowsinvalid', 'local_aicharts', 500), $form->render());

        step_schedule::mock_submit(['maxrows' => 500] + $this->daily_values());
        $this->assertNotNull($this->form($state)->get_data());
    }

    /**
     * Points to keep is shown for a trend only, starts on the site setting and never goes below the floor.
     */
    public function test_points_to_keep_only_for_trend(): void {
        $this->assertStringNotContainsString('name="pointsretention"', $this->form($this->state())->render());

        $state = $this->state('trend');
        $html = $this->form($state)->render();
        $this->assertMatchesRegularExpression('/name="pointsretention"[^>]*value="365"/', $html);
        $this->assertStringContainsString(get_string('pointsretention_sitedefault', 'local_aicharts', 365), $html);

        step_schedule::mock_submit(['pointsretention' => 10] + $this->daily_values());
        $form = $this->form($state);
        $form->apply($form->get_data(), $state);
        $this->assertSame(30, $state->data->pointsretention);

        $state = $this->state();
        step_schedule::mock_submit(['pointsretention' => 10] + $this->daily_values());
        $form = $this->form($state);
        $form->apply($form->get_data(), $state);
        $this->assertSame(0, $state->data->pointsretention);
    }
}
