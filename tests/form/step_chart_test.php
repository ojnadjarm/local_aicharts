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

use local_aicharts\local\chart_spec;
use local_aicharts\local\query_runner;
use local_aicharts\local\wizard_state;

/**
 * Tests for the Chart step of the wizard.
 *
 * The render-only test presses Ask the assistant and comes last: no_submit_button_pressed() keeps a
 * process-wide static, so the first form checked in the process decides the answer for every later one.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\form\step_chart
 */
final class step_chart_test extends \advanced_testcase {
    /** @var string The SQL of the sample series. */
    protected const SQL = 'SELECT c.fullname AS coursename, c.id AS total FROM {course} c WHERE c.id > :siteid';

    /** @var string The preview card of the last form rendered with the assistant asked. */
    protected string $preview = '';

    /** @var bool Whether the last Ask the assistant stored an answer. */
    protected bool $stored = false;

    /** @var string A saved chart definition over the sample series. */
    protected const CHARTJSON = '{"type":"line","title":"Enrolments","labelcolumn":"coursename","labelformat":"date",'
        . '"series":[{"column":"total","label":"Users"}],"xlabel":"Course","ylabel":"Users","smooth":true}';

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
     * A wizard past the Data step with one run series over the sample query.
     *
     * @param string $kind Chart kind.
     * @param string $chartjson Saved chart definition, empty for none.
     * @param string $sql The query of the series.
     * @return wizard_state
     */
    protected function state(string $kind = 'oneshot', string $chartjson = '', string $sql = self::SQL): wizard_state {
        $state = wizard_state::open(wizard_state::start(null));
        $state->data->kind = $kind;
        $state->data->chartjson = $chartjson;
        $rows = [['coursename' => 'Course one', 'total' => 2]];
        $run = (object) ['hash' => sha1($sql), 'columns' => ['coursename', 'total'], 'rowcount' => 1, 'durationms' => 2,
            'rows' => $rows];
        $state->data->queries = [wizard_state::query('Courses', '', $sql, '{"siteid": 1}', $run)];
        $state->data->columns = $kind === 'trend' ? ['runtime', 'Courses'] : ['coursename', 'total'];
        return $state;
    }

    /**
     * The step form of a wizard.
     *
     * @param wizard_state $state The wizard.
     * @return step_chart
     */
    protected function form(wizard_state $state): step_chart {
        return new step_chart($state->url(3)->out(false), ['state' => $state], 'post', '', ['id' => 'local-aicharts-step']);
    }

    /**
     * The values the controls of the saved chart definition post.
     *
     * @return array
     */
    protected function saved_values(): array {
        return [
            'type' => 'line',
            'title' => 'Enrolments',
            'labelcolumn' => 'coursename',
            'labelformat' => 'date',
            'seriescol' => [0 => 0, 1 => 1],
            'serieslabel' => [0 => '', 1 => 'Users'],
            'xlabel' => 'Course',
            'ylabel' => 'Users',
            'horizontal' => 0,
            'stacked' => 0,
            'doughnut' => 0,
            'smooth' => 1,
            'charthint' => '',
        ];
    }

    /**
     * Editing a chart shows its definition as controls, and Next rebuilds the same definition.
     */
    public function test_controls_prefilled_from_saved_chartjson(): void {
        $this->getDataGenerator()->create_course();
        $form = $this->form($this->state('oneshot', self::CHARTJSON));

        $html = $form->render();
        $this->assertMatchesRegularExpression('/name="type"[^>]*value="line"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="type"[^>]*value="bar"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="title"[^>]*value="Enrolments"/', $html);
        $this->assertMatchesRegularExpression('/value="coursename"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/value="date"[^>]*selected/', $html);
        $this->assertMatchesRegularExpression('/name="seriescol\[1\]"[^>]*value="1"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="serieslabel\[1\]"[^>]*value="Users"/', $html);
        $this->assertStringNotContainsString('name="seriescol[0]"', $html);
        $this->assertMatchesRegularExpression('/name="smooth"[^>]*value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('<code>coursename</code>, <code>total</code>', $html);

        $this->assertSame([], $form->validation($this->saved_values(), []));
        $this->assertEquals(chart_spec::from_json(self::CHARTJSON), chart_spec::from_json($form->chartjson()));

        $preview = $form->preview_html();
        $this->assertStringContainsString('chart-area', $preview);
        $this->assertStringContainsString('1 rows returned', $preview);
        $this->assertStringContainsString('name="updatepreview"', $preview);
    }

    /**
     * A new chart starts from the defaults; a trend draws its first point over the run time.
     */
    public function test_defaults_and_trend_preview(): void {
        $this->getDataGenerator()->create_course();

        $html = $this->form($this->state())->render();
        $this->assertMatchesRegularExpression('/name="type"[^>]*value="bar"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="seriescol\[1\]"[^>]*value="1"[^>]*checked/', $html);

        $form = $this->form($this->state('trend', '', 'SELECT COUNT(*) AS total FROM {course} c WHERE c.id > :siteid'));
        $html = $form->render();
        $this->assertMatchesRegularExpression('/name="type"[^>]*value="line"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="labelcolumn"[^>]*disabled/', $html);
        $this->assertStringContainsString(get_string('runtimecolumn', 'local_aicharts'), $html);
        $this->assertStringNotContainsString('name="labelformat"', $html);
        $this->assertStringContainsString('<li>Courses</li>', $html);

        $this->assertSame([], $form->validation(['type' => 'line', 'title' => 'Courses'], []));
        $spec = chart_spec::from_json($form->chartjson());
        $this->assertSame('runtime', $spec->labelcolumn);
        $this->assertSame('text', $spec->labelformat);
        $this->assertSame([['column' => 'Courses', 'label' => 'Courses']], $spec->series);

        $preview = $form->preview_html();
        $this->assertStringContainsString('chart-area', $preview);
        $this->assertStringContainsString(get_string('firstpointnote', 'local_aicharts'), $preview);
    }

    /**
     * Next refuses a label column the result does not have, and a chart without a series.
     */
    public function test_next_rejects_series_column_not_in_result(): void {
        $form = $this->form($this->state());

        $values = $this->saved_values();
        $values['labelcolumn'] = 'nope';
        $errors = $form->validation($values, []);
        $this->assertStringContainsString('"nope"', $errors['typegroup']);
        $this->assertSame('', $form->chartjson());

        $values = $this->saved_values();
        $values['seriescol'] = [0 => 0, 1 => 0];
        $errors = $form->validation($values, []);
        $this->assertStringContainsString('no series', $errors['typegroup']);
    }

    /**
     * A table needs neither a label column nor a series.
     */
    public function test_table_type_needs_no_label_column(): void {
        $form = $this->form($this->state());

        $this->assertSame([], $form->validation(['type' => 'table', 'title' => 'Courses'], []));

        $spec = chart_spec::from_json($form->chartjson());
        $this->assertTrue($spec->is_table());
        $this->assertSame('', $spec->labelcolumn);
        $this->assertSame([], $spec->series);
        $this->assertSame('Courses', $spec->title);
    }

    /**
     * The step draws the rows the Data step kept and runs no query of its own.
     *
     * The series SQL names a table the validator refuses, so query_runner::run() can only fail: a preview
     * holding the stored rows, and none of the live course, proves no run happened on this render.
     */
    public function test_render_draws_stored_rows_and_runs_no_query(): void {
        $this->getDataGenerator()->create_course(['fullname' => 'Live course']);
        $sql = 'SELECT g.fullname AS coursename, g.id AS total FROM {gone} g WHERE g.id > :siteid';
        $state = $this->state('oneshot', '{"type":"table","title":"Courses"}', $sql);
        $state->data->queries[0]->run->rowcount = 120;
        $state->data->queries[0]->run->rows = [
            ['coursename' => 'Stored course', 'total' => 7],
            ['coursename' => 'Other stored', 'total' => 9],
        ];
        $this->assertTrue(query_runner::run($sql, ['siteid' => 1], 100)->has_error());

        $form = $this->form($state);
        $form->render();
        $preview = $form->preview_html();
        $this->assertStringContainsString('Stored course', $preview);
        $this->assertStringContainsString('Other stored', $preview);
        $this->assertStringNotContainsString('Live course', $preview);
        $this->assertStringContainsString('120 rows returned', $preview);
        $this->assertStringContainsString(get_string('previewsource', 'local_aicharts', 2), $preview);
    }

    /**
     * Changing the label column moves the series checkboxes with it.
     *
     * @covers \local_aicharts\form\step_chart::definition
     * @covers \local_aicharts\form\step_chart::controls
     */
    public function test_posted_label_column_rebuilds_the_series(): void {
        $state = $this->state('oneshot', self::CHARTJSON);
        $values = $this->saved_values();
        $values['labelcolumn'] = 'total';
        step_chart::mock_submit($values);

        $html = $this->form($state)->render();
        $this->assertMatchesRegularExpression('/value="total"[^>]*selected/', $html);
        $this->assertStringNotContainsString('name="seriescol[1]"', $html);
        $this->assertStringContainsString('name="seriescol[0]"', $html);
    }

    /**
     * The column drawn along cannot also be one of the series.
     *
     * @covers \local_aicharts\form\step_chart::validation
     * @covers \local_aicharts\form\step_chart::chartjson
     */
    public function test_label_column_is_never_a_series(): void {
        $form = $this->form($this->state());

        $values = $this->saved_values();
        $values['labelcolumn'] = 'total';
        $values['seriescol'] = [0 => 1, 1 => 1];
        $this->assertSame([], $form->validation($values, []));

        $spec = chart_spec::from_json($form->chartjson());
        $this->assertSame('total', $spec->labelcolumn);
        $this->assertSame([['column' => 'coursename', 'label' => 'coursename']], $spec->series);
    }

    /**
     * Making the ticked series the label column ticks the next column instead of none.
     *
     * @covers \local_aicharts\form\step_chart::controls
     * @covers \local_aicharts\form\step_chart::fallback_series
     */
    public function test_label_column_of_the_only_series_ticks_another(): void {
        $state = $this->state();
        $state->data->columns = ['coursename', 'total', 'active'];
        $state->data->queries[0]->run->columns = $state->data->columns;
        $state->data->queries[0]->run->rows = [['coursename' => 'Course one', 'total' => 2, 'active' => 1]];

        $values = $this->saved_values();
        $values['labelcolumn'] = 'total';
        $values['type'] = 'bar';
        $values['seriescol'] = [0 => 0, 1 => 1, 2 => 0];
        step_chart::mock_submit($values);
        $form = $this->form($state);

        $html = $form->render();
        $this->assertStringNotContainsString('name="seriescol[1]"', $html);
        $this->assertMatchesRegularExpression('/name="seriescol\[2\]"[^>]*value="1"[^>]*checked/', $html);

        $this->assertSame([], $form->validation($values, []));
        $spec = chart_spec::from_json($form->chartjson());
        $this->assertSame('total', $spec->labelcolumn);
        $this->assertSame([['column' => 'active', 'label' => 'active']], $spec->series);
    }

    /**
     * A pie of several series is refused, so a blank preview cannot reach the next step.
     */
    public function test_pie_refuses_several_series(): void {
        $state = $this->state();
        $state->data->columns = ['coursename', 'total', 'active'];
        $state->data->queries[0]->run->columns = $state->data->columns;
        $state->data->queries[0]->run->rows = [['coursename' => 'Course one', 'total' => 2, 'active' => 1]];
        $form = $this->form($state);

        $values = [
            'type' => 'pie',
            'title' => 'Mine',
            'labelcolumn' => 'coursename',
            'seriescol' => [1 => 1, 2 => 1],
            'serieslabel' => [1 => 'Total', 2 => 'Active'],
        ];
        $errors = $form->validation($values, []);
        $this->assertStringContainsString(get_string('pieoneseries', 'local_aicharts'), $errors['typegroup']);
        $this->assertSame('', $form->chartjson());

        $values['seriescol'] = [1 => 1, 2 => 0];
        $this->assertSame([], $form->validation($values, []));
        $this->assertSame('pie', chart_spec::from_json($form->chartjson())->type);
    }

    /**
     * Pressing Ask the assistant, as the page does.
     *
     * @param wizard_state $state The wizard.
     * @param string $hint How the chart should look.
     * @param string $type The chart type posted along.
     * @return step_chart The form, after the answer was handled.
     */
    protected function generate(wizard_state $state, string $hint, string $type = 'pie'): step_chart {
        $values = ['type' => $type, 'title' => 'Mine', 'labelcolumn' => 'coursename', 'charthint' => $hint, 'generate' => 1];
        step_chart::mock_submit($values);
        $form = $this->form($state);
        $this->stored = $form->prepare_chart();
        return $form;
    }

    /**
     * Rendering with the assistant asked for a hint.
     *
     * @param wizard_state $state The wizard.
     * @param string $hint How the chart should look.
     * @param string $type The chart type posted along.
     * @return string The form HTML.
     */
    protected function render_generate(wizard_state $state, string $hint, string $type = 'pie'): string {
        $form = $this->generate($state, $hint, $type);
        $this->preview = $form->preview_html();
        return $form->render();
    }

    /**
     * The assistant's answer is kept in the wizard, so the step it reloads shows exactly those controls.
     *
     * @covers \local_aicharts\form\step_chart::prepare_chart
     * @covers \local_aicharts\form\step_chart::ask_assistant
     * @covers \local_aicharts\form\step_chart::definition
     */
    public function test_assistant_answer_is_kept_for_the_reloaded_step(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state('oneshot', self::CHARTJSON);

        $this->generate($state, 'one bar per course');
        $this->assertTrue($this->stored);
        $spec = chart_spec::from_json($state->data->chartjson);
        $this->assertSame('bar', $spec->type);
        $this->assertSame('coursename', $spec->labelcolumn);
        $this->assertSame([['column' => 'total', 'label' => 'Users']], $spec->series);
        $this->assertSame('one bar per course', $state->data->charthint);

        $_POST = [];
        $html = $this->form($state)->render();
        $this->assertMatchesRegularExpression('/name="type"[^>]*value="bar"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="title"[^>]*value="Users per course"/', $html);
        $this->assertMatchesRegularExpression('/name="seriescol\[1\]"[^>]*value="1"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="serieslabel\[1\]"[^>]*value="Users"/', $html);
        $this->assertMatchesRegularExpression('/name="xlabel"[^>]*value="Course"/', $html);
        $this->assertStringContainsString('one bar per course</textarea>', $html);
    }

    /**
     * A refusal or an unknown column says so and leaves the controls as posted, with nothing stored.
     *
     * @covers \local_aicharts\form\step_chart::ask_assistant
     * @covers \local_aicharts\form\step_chart::preview_html
     */
    public function test_assistant_refusal_leaves_the_controls(): void {
        $this->getDataGenerator()->create_course();

        $html = $this->render_generate($this->state(), 'tell me a joke', 'table');
        $this->assertFalse($this->stored);
        $this->assertStringContainsString(get_string('refusal', 'local_aicharts'), $html);
        $this->assertMatchesRegularExpression('/name="type"[^>]*value="table"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/name="title"[^>]*value="Mine"/', $html);
        $this->assertStringContainsString('<table', $this->preview);
        $this->assertStringNotContainsString('chart-area', $this->preview);

        $html = $this->render_generate($this->state(), '');
        $this->assertFalse($this->stored);
        $this->assertStringContainsString(get_string('charthintrequired', 'local_aicharts'), $html);

        $state = $this->state('oneshot', '', 'SELECT c.shortname AS label, c.id AS total FROM {course} c WHERE c.id > :siteid');
        $state->data->columns = ['label', 'total'];
        $html = $this->render_generate($state, 'one bar per course');
        $this->assertFalse($this->stored);
        $this->assertStringContainsString(get_string('chartcolumnmissing', 'local_aicharts'), $html);
        $this->assertMatchesRegularExpression('/name="title"[^>]*value="Mine"/', $html);
    }
}
