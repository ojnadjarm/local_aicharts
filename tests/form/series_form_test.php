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
use local_aicharts\local\wizard_state;

/**
 * Tests for the series modal of the wizard.
 *
 * The render-only tests press Run or Ask the assistant and come last: no_submit_button_pressed() keeps a
 * process-wide static (lib/formslib.php:516), so the first form checked in the process decides the answer for
 * every later one.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\form\series_form
 */
final class series_form_test extends \advanced_testcase {
    /** @var string The SQL of the sample series. */
    protected const SQL = "SELECT 'courses' AS label, COUNT(*) AS total FROM {course} c WHERE c.id <> :siteid";

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('maxrowsdefault', 100, 'local_aicharts');
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
        set_config('clienttype', 'stub', 'local_aicharts');
    }

    /**
     * A one-shot wizard with no series.
     *
     * @return wizard_state
     */
    protected function state(): wizard_state {
        $state = wizard_state::open(wizard_state::start(null));
        $state->data->kind = 'oneshot';
        return $state;
    }

    /**
     * Form data of the sample series.
     *
     * @param wizard_state $state The wizard.
     * @param int $index Position of the series, -1 for a new one.
     * @return array
     */
    protected function data(wizard_state $state, int $index = -1): array {
        return [
            'w' => $state->token,
            'index' => $index,
            'label' => 'Courses',
            'hint' => 'number of courses',
            'sqltext' => self::SQL,
            'params' => '{"siteid": 1}',
        ];
    }

    /**
     * The hash the form records once it previewed the given series.
     *
     * @param array $data Form data with label, sqltext and params.
     * @return string
     */
    protected function preview_hash(array $data): string {
        return sha1(json_encode([$data['label'], $data['sqltext'], $data['params']]));
    }

    /**
     * The run metadata the modal keeps after a successful Run.
     *
     * @param array $data Form data with label, sqltext and params.
     * @return \stdClass
     */
    protected function lastrun(array $data): \stdClass {
        return (object) [
            'hash' => $this->preview_hash($data),
            'columns' => ['label', 'total'],
            'rowcount' => 1,
            'truncated' => false,
            'durationms' => 3,
            'rows' => [['label' => 'courses', 'total' => 1]],
        ];
    }

    /**
     * Send form data through the form web service.
     *
     * @param array $data Field values.
     * @return array The service response.
     */
    protected function submit(array $data): array {
        return dynamic_form::execute(series_form::class, http_build_query(series_form::mock_ajax_submit($data)));
    }

    /**
     * Saving a series that was not run, or was changed since, fails with runfirst.
     */
    public function test_save_without_run_fails_with_runfirst(): void {
        $state = $this->state();
        $data = $this->data($state);

        $response = $this->submit($data);
        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('runfirst', 'local_aicharts'), $response['html']);
        $this->assertSame([], $state->data->queries);

        $state->data->lastrun = $this->lastrun($data);
        $data['previewedfor'] = $this->preview_hash($data);
        $data['sqltext'] .= ' AND c.id > 0';
        $response = $this->submit($data);
        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('runfirst', 'local_aicharts'), $response['html']);

        $response = $this->submit(['w' => $state->token, 'index' => -1, 'label' => '', 'sqltext' => '', 'params' => '[1]']);
        $this->assertFalse($response['submitted']);
        $this->assertStringContainsString(get_string('labelrequired', 'local_aicharts'), $response['html']);
        $this->assertStringContainsString(get_string('queryrequired', 'local_aicharts'), $response['html']);
        $this->assertStringContainsString(get_string('paramsinvalid', 'local_aicharts'), $response['html']);
    }

    /**
     * Saving a previewed series writes it into the wizard with its run, appending or replacing.
     */
    public function test_save_writes_series_into_state(): void {
        $state = $this->state();
        $data = $this->data($state);
        $state->data->lastrun = $this->lastrun($data);
        $data['previewedfor'] = $this->preview_hash($data);
        $state->data->columns = ['label', 'total'];

        $response = $this->submit($data);

        $this->assertTrue($response['submitted']);
        $this->assertSame(0, json_decode($response['data'])->index);
        $this->assertCount(1, $state->data->queries);
        $query = $state->data->queries[0];
        $this->assertSame('Courses', $query->label);
        $this->assertSame('number of courses', $query->hint);
        $this->assertSame(self::SQL, $query->sqltext);
        $this->assertSame('{"siteid": 1}', $query->params);
        $this->assertSame(1, $query->run->rowcount);
        $this->assertSame(['label', 'total'], $query->run->columns);
        $this->assertNull($state->data->lastrun);
        $this->assertSame([], $state->data->columns);

        $data = $this->data($state, 0);
        $data['label'] = 'Courses again';
        $state->data->lastrun = $this->lastrun($data);
        $data['previewedfor'] = $this->preview_hash($data);
        $response = $this->submit($data);
        $this->assertTrue($response['submitted']);
        $this->assertCount(1, $state->data->queries);
        $this->assertSame('Courses again', $state->data->queries[0]->label);

        $this->expectException(\moodle_exception::class);
        $this->submit(['w' => 'gone'] + $this->data($state));
    }

    /**
     * Opening an existing series fills the fields from the wizard; a new one is blank.
     */
    public function test_edit_prefills_from_state(): void {
        $state = $this->state();
        $state->data->queries[] = wizard_state::query('Courses', 'number of courses', self::SQL, '{"siteid": 1}');

        $form = new series_form(null, null, 'post', '', [], true, ['w' => $state->token, 'index' => 0], true);
        $form->set_data_for_dynamic_submission();
        $html = $form->render();
        $this->assertMatchesRegularExpression('/name="label"[^>]*value="Courses"/', $html);
        $this->assertStringContainsString('number of courses', $html);
        $this->assertStringContainsString(s(self::SQL), $html);
        $this->assertStringContainsString('{&quot;siteid&quot;: 1}', $html);
        $this->assertStringContainsString(get_string('catalogue', 'local_aicharts'), $html);
        $this->assertStringContainsString('{course}', $html);
        $this->assertStringContainsString(get_string('querydisclaimer', 'local_aicharts'), $html);

        $form = new series_form(null, null, 'post', '', [], true, ['w' => $state->token, 'index' => -1], true);
        $form->set_data_for_dynamic_submission();
        $html = $form->render();
        $this->assertMatchesRegularExpression('/name="label"[^>]*value=""/', $html);
    }

    /**
     * A user without the capability cannot use the modal.
     */
    public function test_requires_manage(): void {
        $this->setUser($this->getDataGenerator()->create_user());
        $state = $this->state();

        $this->expectException(\required_capability_exception::class);
        $this->submit($this->data($state));
    }

    /**
     * Rendering with the assistant asked for a hint.
     *
     * @param wizard_state $state The wizard.
     * @param array $overrides Field values replacing the sample series.
     * @return string The form HTML.
     */
    protected function render_generate(wizard_state $state, array $overrides = []): string {
        global $OUTPUT, $PAGE;

        $data = array_merge($this->data($state), ['label' => '', 'sqltext' => '', 'params' => '', 'generate' => 1], $overrides);
        $form = new series_form(null, null, 'post', '', [], true, series_form::mock_ajax_submit($data), true);
        $OUTPUT->header();
        $PAGE->start_collecting_javascript_requirements();
        return $form->render();
    }

    /**
     * Asking the assistant fills the SQL, the parameters and a blank name with its answer, and shows its notes.
     */
    public function test_generate_fills_sql_and_label(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state();

        $html = $this->render_generate($state, ['hint' => 'users per course']);

        $this->assertMatchesRegularExpression('/name="label"[^>]*value="Users per course"/', $html);
        $this->assertStringContainsString('COUNT(DISTINCT ue.userid) AS total', $html);
        $this->assertStringContainsString('{&quot;siteid&quot;:1}', $html);
        $notes = get_string('assistantnotes', 'local_aicharts', 'Counts enrolled users, whatever the enrolment status.');
        $this->assertStringContainsString($notes, $html);
        $provider = get_string('provider', 'local_aicharts', get_string('clienttypestub', 'local_aicharts'));
        $this->assertStringContainsString($provider, $html);
        $this->assertMatchesRegularExpression('/name="previewedfor"[^>]*value=""/', $html);
        $this->assertNull($state->data->lastrun);
        $this->assertStringNotContainsString('data-region="aic-preview"', $html);
        $this->assertMatchesRegularExpression('/class="btn\s+btn-secondary[^>]*name="generate"/s', $html);
        $this->assertStringContainsString('fa-wand-magic-sparkles', $html);
    }

    /**
     * A name typed before asking is kept.
     */
    public function test_generate_keeps_typed_label(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state();

        $html = $this->render_generate($state, ['hint' => 'users per course', 'label' => 'My series']);

        $this->assertMatchesRegularExpression('/name="label"[^>]*value="My series"/', $html);
        $this->assertStringContainsString('COUNT(DISTINCT ue.userid) AS total', $html);
    }

    /**
     * A refusal or a missing hint renders the state box and leaves the editors as typed.
     */
    public function test_refusal_renders_statebox_and_keeps_fields(): void {
        $state = $this->state();

        $html = $this->render_generate($state, ['hint' => 'tell me a joke', 'sqltext' => 'SELECT 1 AS one']);
        $this->assertStringContainsString(get_string('refusal', 'local_aicharts'), $html);
        $this->assertStringContainsString('SELECT 1 AS one', $html);
        $this->assertMatchesRegularExpression('/name="label"[^>]*value=""/', $html);
        $this->assertNull($state->data->proposal);

        $html = $this->render_generate($state, ['hint' => '']);
        $this->assertStringContainsString(get_string('hintrequired', 'local_aicharts'), $html);
        $this->assertNull($state->data->proposal);
    }

    /**
     * The first answer becomes the proposal for the later steps; a second one does not replace it.
     */
    public function test_generate_stores_name_proposal(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state();

        $this->render_generate($state, ['hint' => 'users per course']);

        $this->assertSame('Users per course', $state->data->proposal->name);
        $this->assertSame('Counts enrolled users, whatever the enrolment status.', $state->data->proposal->notes);
        $this->assertSame('bar', json_decode($state->data->proposal->chartjson)->type);

        $this->render_generate($state, ['hint' => 'users who never logged in, as a list']);
        $this->assertSame('Users per course', $state->data->proposal->name);
    }

    /**
     * Rendering with Run pressed previews the query, records the run in the wizard and the hash in the form.
     */
    public function test_run_renders_preview_and_hash(): void {
        global $OUTPUT, $PAGE;

        $this->getDataGenerator()->create_course();
        $state = $this->state();
        $data = $this->data($state);
        $data['runquery'] = 1;

        $form = new series_form(null, null, 'post', '', [], true, series_form::mock_ajax_submit($data), true);
        $OUTPUT->header();
        $PAGE->start_collecting_javascript_requirements();
        $html = $form->render();

        $hash = $this->preview_hash($data);
        $this->assertStringContainsString('aic-preview', $html);
        $this->assertStringContainsString(get_string('livenotice', 'local_aicharts'), $html);
        $this->assertMatchesRegularExpression('/1 rows returned/', $html);
        $this->assertMatchesRegularExpression('/name="previewedfor"[^>]*value="' . $hash . '"/', $html);
        $this->assertSame($hash, $state->data->lastrun->hash);
        $this->assertSame(1, $state->data->lastrun->rowcount);
        $this->assertSame(['label', 'total'], $state->data->lastrun->columns);
        $this->assertSame([], $state->data->queries);

        $data['sqltext'] = 'SELECT c.shortname AS label, c.id AS total FROM {course} c WHERE c.id > :siteid AND c.id < 0';
        $html = (new series_form(null, null, 'post', '', [], true, series_form::mock_ajax_submit($data), true))->render();
        $this->assertStringContainsString(get_string('norows', 'local_aicharts'), $html);
        $this->assertMatchesRegularExpression('/name="previewedfor"[^>]*value=""/', $html);
        $this->assertNull($state->data->lastrun);

        $data['sqltext'] = 'DELETE FROM {course}';
        $html = (new series_form(null, null, 'post', '', [], true, series_form::mock_ajax_submit($data), true))->render();
        $this->assertStringContainsString(get_string('sqlnotselect', 'local_aicharts'), $html);
        $this->assertMatchesRegularExpression('/name="previewedfor"[^>]*value=""/', $html);
        $this->assertNull($state->data->lastrun);

        $data['sqltext'] = self::SQL;
        $data['params'] = '[1, 2]';
        $html = (new series_form(null, null, 'post', '', [], true, series_form::mock_ajax_submit($data), true))->render();
        $this->assertStringContainsString(s(get_string('paramsinvalid', 'local_aicharts')), $html);
        $this->assertNull($state->data->lastrun);
    }
}
