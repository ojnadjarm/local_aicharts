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

use core_form\external\dynamic_form;
use local_aicharts\llm\stub_client;
use local_aicharts\local\chart_generator;
use local_aicharts\local\chart_repository;
use local_aicharts\local\generation_result;
use local_aicharts\local\result_store;
use local_aicharts\local\schedule;

/**
 * Tests for the chart form.
 *
 * The transport tests never press Generate: no_submit_button_pressed() keeps a process-wide static
 * (lib/formslib.php:516), so the first form checked in the process decides the answer for every later one.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chart_form_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('clienttype', 'stub', 'local_aicharts');
        set_config('allowedtables', "user\ncourse\nenrol\nuser_enrolments\n", 'local_aicharts');
        set_config('maxrowsdefault', 100, 'local_aicharts');
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
        set_config('runhour', 4, 'local_aicharts');
        stub_client::reset_call_count();
        chart_generator::reset_caches();
    }

    /**
     * Generate the sample bar chart the way the form does.
     *
     * @return generation_result
     */
    protected function generate(): generation_result {
        global $USER;

        return chart_generator::generate('users per course', '', '', '', 100, (int) $USER->id);
    }

    /**
     * Send form data through the form web service.
     *
     * @param array $data Field values.
     * @return array The service response.
     */
    protected function submit(array $data): array {
        return dynamic_form::execute(chart_form::class, http_build_query(chart_form::mock_ajax_submit($data)));
    }

    /**
     * Form data of a Save that matches a generated answer.
     *
     * @param generation_result $result The answer to save.
     * @return array
     */
    protected function save_data(generation_result $result): array {
        return [
            'prompt' => 'users per course',
            'name' => 'Enrolled users',
            'sqltext' => $result->sql,
            'params' => json_encode($result->params),
            'chartjson' => $result->chartjson,
            'maxrows' => 50,
            'generatedfor' => sha1('users per course|||'),
            'runmode' => 'daily',
            'runhour' => 6,
        ];
    }

    /**
     * Store a chart built from a generated answer, as an earlier save would have.
     *
     * @param generation_result $result The answer to store.
     * @param array $overrides Column values to change.
     * @return int The chart id.
     */
    protected function create_chart(generation_result $result, array $overrides = []): int {
        $data = $this->save_data($result);
        $data['params'] = json_encode($result->params ?: new \stdClass());
        $data['runmode'] = 'live';
        unset($data['generatedfor']);
        return chart_repository::save((object) array_merge($data, $overrides));
    }

    /**
     * Opening a saved chart prefills the fields and renders it from the stored query without calling the model.
     *
     * @covers \local_aicharts\form\chart_form::add_saved_preview
     * @covers \local_aicharts\form\chart_form::set_data_for_dynamic_submission
     */
    public function test_edit_prefills_and_prerenders(): void {
        global $OUTPUT, $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $course->id);
        $result = $this->generate();
        $id = $this->create_chart($result, ['schemahint' => 'course has fullname']);

        $form = new chart_form(null, null, 'post', '', [], true, ['id' => $id], true);
        $form->set_data_for_dynamic_submission();
        $OUTPUT->header();
        $PAGE->start_collecting_javascript_requirements();
        $html = $form->render();
        $js = $PAGE->requires->get_end_code();

        $this->assertSame(1, stub_client::get_call_count());
        $this->assertStringContainsString('core/chart_builder', $js);
        $this->assertStringContainsString('chart-area', $html);
        $this->assertMatchesRegularExpression('/name="id"[^>]*value="' . $id . '"/', $html);
        $this->assertStringContainsString('value="Enrolled users"', $html);
        $this->assertStringContainsString('users per course', $html);
        $this->assertStringContainsString('course has fullname', $html);
        $this->assertMatchesRegularExpression('/name="maxrows"[^>]*value="50"/', $html);
        $this->assertStringContainsString(s($result->sql), $html);
        $took = str_replace('0', '\d+', get_string('querytook', 'local_aicharts', 0));
        $this->assertMatchesRegularExpression('/' . $took . '/', $html);
        $this->assertStringContainsString(get_string('regenerate', 'local_aicharts'), $html);
    }

    /**
     * A failed save of an edited chart keeps the answer but does not run the query or the model again.
     *
     * @covers \local_aicharts\form\chart_form::definition
     */
    public function test_save_submit_does_not_run_query(): void {
        $result = $this->generate();
        $data = $this->save_data($result);
        $data['id'] = $this->create_chart($result);
        $data['maxrows'] = 9999;

        $response = $this->submit($data);

        $this->assertFalse($response['submitted']);
        $this->assertSame(1, stub_client::get_call_count());
        $this->assertStringContainsString(get_string('generateagain', 'local_aicharts'), $response['html']);
        $this->assertStringNotContainsString(get_string('querytook', 'local_aicharts', 0), $response['html']);
        $this->assertStringNotContainsString('chart-area', $response['html']);
    }

    /**
     * Duplicating opens the saved chart without its id and with a copy name, and saving it inserts a new row.
     *
     * @covers \local_aicharts\form\chart_form::set_data_for_dynamic_submission
     * @covers \local_aicharts\form\chart_form::process_dynamic_submission
     */
    public function test_duplicate_has_no_id_and_copy_name(): void {
        $result = $this->generate();
        $id = $this->create_chart($result);

        $form = new chart_form(null, null, 'post', '', [], true, ['id' => $id, 'duplicate' => 1], true);
        $form->set_data_for_dynamic_submission();
        $html = $form->render();

        $this->assertMatchesRegularExpression('/name="id"[^>]*value="0"/', $html);
        $this->assertStringContainsString('value="Enrolled users (copy)"', $html);
        $this->assertStringContainsString(s($result->sql), $html);

        $data = $this->save_data($result);
        $data['id'] = 0;
        $data['name'] = 'Enrolled users (copy)';
        $response = $this->submit($data);

        $this->assertTrue($response['submitted']);
        $newid = json_decode($response['data'])->id;
        $this->assertNotEquals($id, $newid);
        $this->assertSame('Enrolled users (copy)', chart_repository::get($newid)->name);
        $this->assertSame('Enrolled users', chart_repository::get($id)->name);
    }

    /**
     * Saving with an id updates that row and keeps who created it and when.
     *
     * @covers \local_aicharts\form\chart_form::process_dynamic_submission
     */
    public function test_updates_existing_chart(): void {
        global $DB;

        $result = $this->generate();
        $creator = $this->getDataGenerator()->create_user();
        $id = $this->create_chart($result, ['isdefault' => 1, 'userid' => $creator->id]);
        $DB->set_field('local_aicharts_chart', 'timecreated', 1000, ['id' => $id]);
        $data = $this->save_data($result);
        $data['id'] = $id;
        $data['name'] = 'Renamed';
        $count = count(chart_repository::list_all());

        $response = $this->submit($data);

        $this->assertTrue($response['submitted']);
        $this->assertSame($id, json_decode($response['data'])->id);
        $this->assertCount($count, chart_repository::list_all());
        $chart = chart_repository::get($id);
        $this->assertSame('Renamed', $chart->name);
        $this->assertSame('daily', $chart->runmode);
        $this->assertSame('1', $chart->isdefault);
        $this->assertSame('1000', $chart->timecreated);
        $this->assertSame((string) $creator->id, $chart->userid);
    }

    /**
     * Rendering with Generate pressed shows the chart, the hidden answer and the notice, and its script is collected.
     *
     * @covers \local_aicharts\form\chart_form::definition
     * @covers \local_aicharts\form\chart_form::definition_after_data
     */
    public function test_generate_renders_preview(): void {
        global $OUTPUT, $PAGE;

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->getDataGenerator()->create_user()->id, $course->id);
        $this->generate();

        $form = new chart_form(null, null, 'post', '', [], true, ['prompt' => 'users per course', 'generate' => 1], true);
        $OUTPUT->header();
        $PAGE->start_collecting_javascript_requirements();
        $html = $form->render();
        $js = $PAGE->requires->get_end_code();

        $this->assertSame(1, stub_client::get_call_count());
        $this->assertStringContainsString('core/chart_builder', $js);
        $this->assertStringContainsString('focusPreview', $js);
        $this->assertStringContainsString('data-region="aic-preview"', $html);
        $this->assertStringContainsString('chart-area', $html);
        $this->assertStringContainsString('name="sqltext"', $html);
        $this->assertStringContainsString('{course}', $html);
        $this->assertStringContainsString('value="Users per course"', $html);
        $this->assertStringContainsString(get_string('livenotice', 'local_aicharts'), $html);
        $this->assertStringContainsString(get_string('regenerate', 'local_aicharts'), $html);
    }

    /**
     * The refinement is appended to the prompt and its field is cleared.
     *
     * @covers \local_aicharts\form\chart_form::request_values
     */
    public function test_refine_is_appended_to_prompt(): void {
        $data = ['prompt' => 'users per course', 'refine' => 'only the top 3 courses', 'generate' => 1];

        $form = new chart_form(null, null, 'post', '', [], true, $data, true);
        $html = $form->render();

        $merged = get_string('refinement', 'local_aicharts', 'only the top 3 courses');
        $this->assertStringContainsString($merged, $html);
        $this->assertSame(1, substr_count($html, 'only the top 3 courses'));
    }

    /**
     * A provider without an API key shows the missing-key box with a link to the settings.
     *
     * @covers \local_aicharts\form\chart_form::statebox
     */
    public function test_missing_api_key_renders_settings_link(): void {
        set_config('clienttype', 'openai', 'local_aicharts');
        set_config('apikey', '', 'local_aicharts');

        $form = new chart_form(null, null, 'post', '', [], true, ['prompt' => 'users per course', 'generate' => 1], true);
        $html = $form->render();

        $this->assertStringContainsString(get_string('error_llm_nokey', 'local_aicharts'), $html);
        $this->assertStringContainsString(get_string('opensettings', 'local_aicharts'), $html);
        $this->assertStringNotContainsString('name="sqltext"', $html);
    }

    /**
     * A refused request renders the refusal box and none of the answer fields.
     *
     * @covers \local_aicharts\form\chart_form::statebox
     */
    public function test_refusal_renders_statebox(): void {
        $form = new chart_form(null, null, 'post', '', [], true, ['prompt' => 'tell me a joke', 'generate' => 1], true);
        $html = $form->render();

        $this->assertStringContainsString(get_string('refusal', 'local_aicharts'), $html);
        $this->assertStringNotContainsString('name="sqltext"', $html);
        $this->assertStringNotContainsString('name="name"', $html);
    }

    /**
     * Saving before any preview is rejected.
     *
     * @covers \local_aicharts\form\chart_form::validation
     */
    public function test_save_without_preview_fails_validation(): void {
        $response = $this->submit(['prompt' => 'users per course']);

        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('generatefirst', 'local_aicharts'), $response['html']);
    }

    /**
     * Saving a preview generated for another prompt is rejected.
     *
     * @covers \local_aicharts\form\chart_form::validation
     */
    public function test_stale_preview_fails_validation(): void {
        $data = $this->save_data($this->generate());
        $data['prompt'] = 'users per course this year';

        $response = $this->submit($data);

        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('promptchanged', 'local_aicharts'), $response['html']);
        $this->assertStringContainsString(get_string('generateagain', 'local_aicharts'), $response['html']);
    }

    /**
     * A valid save stores the chart and returns its id.
     *
     * @covers \local_aicharts\form\chart_form::process_dynamic_submission
     */
    public function test_process_saves_chart(): void {
        $result = $this->generate();

        $response = $this->submit($this->save_data($result));

        $this->assertTrue($response['submitted']);
        $id = json_decode($response['data'])->id;
        $chart = chart_repository::get($id);
        $this->assertSame('Enrolled users', $chart->name);
        $this->assertSame('users per course', $chart->prompt);
        $this->assertSame($result->sql, $chart->sqltext);
        $this->assertSame(json_encode($result->params), $chart->params);
        $this->assertSame($result->chartjson, $chart->chartjson);
        $this->assertSame('50', $chart->maxrows);
        $this->assertSame('daily', $chart->runmode);
        $this->assertSame('6', $chart->runhour);
    }

    /**
     * Saving a scheduled chart gives it a first result straight away.
     *
     * @covers \local_aicharts\form\chart_form::process_dynamic_submission
     */
    public function test_scheduled_save_stores_first_result(): void {
        global $USER;

        $response = $this->submit($this->save_data($this->generate()));

        $id = json_decode($response['data'])->id;
        $results = result_store::list_for_chart($id);
        $this->assertCount(1, $results);
        $result = reset($results);
        $this->assertSame('ok', $result->status);
        $this->assertSame('save', $result->runtrigger);
        $this->assertSame((string) $USER->id, $result->userid);
        $this->assertNotNull(result_store::get_file($result));
    }

    /**
     * A live chart is not run on save.
     *
     * @covers \local_aicharts\form\chart_form::process_dynamic_submission
     */
    public function test_live_save_stores_no_result(): void {
        $data = $this->save_data($this->generate());
        $data['runmode'] = 'live';

        $response = $this->submit($data);

        $this->assertSame([], result_store::list_for_chart(json_decode($response['data'])->id));
    }

    /**
     * A row limit above the site maximum is rejected.
     *
     * @covers \local_aicharts\form\chart_form::validation
     */
    public function test_rejects_maxrows_above_maximum(): void {
        $data = $this->save_data($this->generate());
        $data['maxrows'] = 9999;

        $response = $this->submit($data);

        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('maxrowsinvalid', 'local_aicharts', 500), $response['html']);
    }

    /**
     * The run hour select starts on the site setting and the live mode owns the SQL summary.
     *
     * @covers \local_aicharts\form\chart_form::default_runhour
     * @covers \local_aicharts\form\chart_form::sql_html
     */
    public function test_runhour_defaults_to_site_setting(): void {
        $form = new chart_form(null, null, 'post', '', [], true, ['prompt' => 'users per course', 'generate' => 1], true);

        $html = $form->render();

        $this->assertMatchesRegularExpression('/<option[^>]*value="4"[^>]*selected/', $html);
        $this->assertStringContainsString(get_string('runmodeinfo', 'local_aicharts'), $html);
        $this->assertStringContainsString(get_string('sqlrunsonload', 'local_aicharts'), $html);
        $this->assertStringNotContainsString(get_string('generatedsql', 'local_aicharts'), $html);
    }

    /**
     * A scheduled mode shows the site timezone and the next run computed for the posted hour.
     *
     * @covers \local_aicharts\form\chart_form::schedule_context_html
     */
    public function test_next_run_is_rendered(): void {
        $data = ['prompt' => 'users per course', 'generate' => 1, 'runmode' => 'weekly', 'runhour' => 6];

        $form = new chart_form(null, null, 'post', '', [], true, $data, true);
        $html = $form->render();

        $timezone = \core_date::get_server_timezone();
        $next = schedule::next_run((object) ['runmode' => 'weekly', 'runhour' => 6], time());
        $expected = userdate($next, get_string('strftimedaydatetime', 'langconfig'), $timezone);
        $this->assertStringContainsString(get_string('runhourinfo', 'local_aicharts', $timezone), $html);
        $this->assertStringContainsString(get_string('nextrun', 'local_aicharts', $expected), $html);
        $this->assertStringContainsString(get_string('generatedsql', 'local_aicharts'), $html);
    }

    /**
     * A run hour outside the day is rejected for a scheduled chart only.
     *
     * @covers \local_aicharts\form\chart_form::validation
     */
    public function test_rejects_runhour_out_of_range_when_scheduled(): void {
        $data = $this->save_data($this->generate());
        $data['runhour'] = 42;

        $response = $this->submit($data);

        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('runhourinvalid', 'local_aicharts'), $response['html']);

        $data['runmode'] = 'live';
        $this->assertTrue($this->submit($data)['submitted']);
    }

    /**
     * A user who may receive results is stored in the comma separated recipient list.
     *
     * @covers \local_aicharts\form\chart_form::process_dynamic_submission
     * @covers \local_aicharts\form\chart_form::allowed_recipients
     */
    public function test_process_saves_recipients(): void {
        $recipient = $this->make_recipient();
        $data = $this->save_data($this->generate());
        $data['emailto'] = [$recipient->id];
        $data['emailwhen'] = 'rows';

        $response = $this->submit($data);

        $this->assertTrue($response['submitted']);
        $chart = chart_repository::get(json_decode($response['data'])->id);
        $this->assertSame((string) $recipient->id, $chart->emailto);
        $this->assertSame('rows', $chart->emailwhen);
    }

    /**
     * A user without the capability to receive results cannot be a recipient.
     *
     * @covers \local_aicharts\form\chart_form::validation
     */
    public function test_rejects_recipient_without_capability(): void {
        $data = $this->save_data($this->generate());
        $data['emailto'] = [$this->getDataGenerator()->create_user()->id];

        $response = $this->submit($data);

        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('emailtoinvalid', 'local_aicharts'), $response['html']);
    }

    /**
     * A live chart keeps no recipients even when the hidden fields are posted.
     *
     * @covers \local_aicharts\form\chart_form::process_dynamic_submission
     */
    public function test_live_save_clears_email_fields(): void {
        $data = $this->save_data($this->generate());
        $data['runmode'] = 'live';
        $data['emailto'] = [$this->make_recipient()->id];
        $data['emailwhen'] = 'rows';

        $response = $this->submit($data);

        $this->assertTrue($response['submitted']);
        $chart = chart_repository::get(json_decode($response['data'])->id);
        $this->assertSame('', $chart->emailto);
        $this->assertSame('always', $chart->emailwhen);
    }

    /**
     * A user holding the capability to receive results.
     *
     * @return \stdClass The user record.
     */
    protected function make_recipient(): \stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/aicharts:receiveresults', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $user->id, \context_system::instance()->id);
        return $user;
    }

    /**
     * Only users who may manage charts reach the form.
     *
     * @covers \local_aicharts\form\chart_form::check_access_for_dynamic_submission
     */
    public function test_requires_manage(): void {
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        $this->submit(['prompt' => 'users per course']);
    }
}
