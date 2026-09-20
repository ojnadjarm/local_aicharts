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

use local_aicharts\local\chart_repository;
use local_aicharts\local\chart_saver;
use local_aicharts\local\point_store;
use local_aicharts\local\wizard_state;

/**
 * Tests for the Review step of the wizard.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\form\step_review
 */
final class step_review_test extends \advanced_testcase {
    /** @var string The SQL of the sample series. */
    protected const SQL = 'SELECT c.fullname AS coursename, c.id AS total FROM {course} c WHERE c.id > :siteid';

    /** @var string The chart definition of the sample series. */
    protected const CHARTJSON = '{"type":"bar","title":"Courses","labelcolumn":"coursename",'
        . '"series":[{"column":"total","label":"Total"}]}';

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('maxrowsdefault', 100, 'local_aicharts');
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
        set_config('pointsretention', 365, 'local_aicharts');
    }

    /**
     * A wizard past the Schedule step, with one run series over the sample query.
     *
     * @param string $kind Chart kind.
     * @param string $sql The query of the series.
     * @return wizard_state
     */
    protected function state(string $kind = 'oneshot', string $sql = self::SQL): wizard_state {
        $state = wizard_state::open(wizard_state::start(null));
        $state->data->kind = $kind;
        $state->data->chartjson = $kind === 'trend'
            ? '{"type":"line","labelcolumn":"runtime","labelformat":"date","series":[{"column":"Courses","label":"Courses"}]}'
            : self::CHARTJSON;
        $rows = [['coursename' => 'Course one', 'total' => 2]];
        $run = (object) ['hash' => sha1($sql), 'columns' => ['coursename', 'total'], 'rowcount' => 1,
            'truncated' => false, 'durationms' => 2, 'rows' => $rows];
        $state->data->queries = [wizard_state::query('Courses', 'the courses', $sql, '{"siteid": 1}', $run)];
        $state->data->columns = $kind === 'trend' ? ['runtime', 'Courses'] : ['coursename', 'total'];
        $state->data->runmode = 'daily';
        $state->data->runhour = 6;
        $state->save();
        return $state;
    }

    /**
     * The step form of a wizard.
     *
     * @param wizard_state $state The wizard.
     * @return step_review
     */
    protected function form(wizard_state $state): step_review {
        return new step_review($state->url(5)->out(false), ['state' => $state], 'post', '', ['id' => 'local-aicharts-step']);
    }

    /**
     * Save is offered only once the review run of the chart has succeeded.
     */
    public function test_save_hidden_until_review_run_ok(): void {
        $this->getDataGenerator()->create_course();
        $broken = 'SELECT nothing FROM {sessions}';
        $state = $this->state('oneshot', $broken);

        $form = $this->form($state);
        $form->prepare_review();
        $html = $form->render();
        $this->assertFalse($form->can_save());
        $this->assertFalse($state->data->review->ok);
        $this->assertStringContainsString(get_string('fixdata', 'local_aicharts'), $html);

        $state = $this->state();
        $form = $this->form($state);
        $form->prepare_review();
        $html = $form->render();
        $this->assertTrue($form->can_save());
        $this->assertTrue($state->data->review->ok);
        $this->assertStringContainsString(get_string('summary_kind', 'local_aicharts'), $html);
        $this->assertStringContainsString('1 rows returned', $html);
    }

    /**
     * Saving writes the chart with its series and the hint of each series.
     */
    public function test_save_creates_chart_with_queries_and_hint(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state();
        $this->form($state)->prepare_review();

        step_review::mock_submit(['name' => 'Courses per site', 'step' => 5]);
        $form = $this->form($state);
        $data = $form->get_data();
        $this->assertNotNull($data);

        $state->data->name = $data->name;
        $id = chart_saver::save($state);
        $chart = chart_repository::get($id);
        $this->assertSame('Courses per site', $chart->name);
        $this->assertSame('oneshot', $chart->kind);
        $this->assertSame('daily', $chart->runmode);
        $this->assertSame('the courses', $chart->prompt);
        $this->assertCount(1, $chart->queries);
        $this->assertSame('Courses', $chart->queries[0]->label);
        $this->assertSame('the courses', $chart->queries[0]->hint);
        $this->assertSame(self::SQL, $chart->queries[0]->sqltext);
        $this->assertObjectNotHasProperty('charthint', $chart);
    }

    /**
     * A submission refused for a missing name still shows the review card it was refused on.
     */
    public function test_refused_submit_keeps_the_review_card(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state();
        $this->form($state)->prepare_review();

        step_review::mock_submit(['name' => '', 'step' => 5]);
        $form = $this->form($state);
        $this->assertNull($form->get_data());
        $form->prepare_review();
        $html = $form->render();

        $this->assertTrue($form->can_save());
        $this->assertStringContainsString(get_string('summary_kind', 'local_aicharts'), $html);
        $this->assertStringContainsString('1 rows returned', $html);
    }

    /**
     * A query changed after the review run is refused until the step is opened again.
     */
    public function test_save_after_changed_query_is_refused(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state();
        $this->form($state)->prepare_review();
        $state->data->queries[0]->sqltext = 'SELECT c.fullname AS coursename, 1 AS total FROM {course} c';

        step_review::mock_submit(['name' => 'Courses', 'step' => 5]);
        $form = $this->form($state);
        $this->assertFalse($form->can_save());
        $this->assertNull($form->get_data());
        $errors = $form->validation(['name' => 'Courses'], []);
        $this->assertSame(get_string('runfirstreview', 'local_aicharts'), $errors['review']);
    }

    /**
     * Editing a chart saves over the same row.
     */
    public function test_edit_save_updates_same_row(): void {
        global $DB;

        $this->getDataGenerator()->create_course();
        $state = $this->state();
        $state->data->name = 'First name';
        $id = chart_saver::save($state);

        $state->data->id = $id;
        $this->form($state)->prepare_review();
        step_review::mock_submit(['name' => 'Second name', 'step' => 5]);
        $data = $this->form($state)->get_data();
        $state->data->name = $data->name;

        $this->assertSame($id, chart_saver::save($state));
        $this->assertSame('Second name', chart_repository::get($id)->name);
        $this->assertSame(0, $DB->count_records('local_aicharts_chart', ['name' => 'First name']));
    }

    /**
     * Duplicating a chart saves a second row and leaves the first alone.
     */
    public function test_duplicate_saves_new_row(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state();
        $state->data->name = 'Courses';
        $id = chart_saver::save($state);

        $copy = wizard_state::open(wizard_state::start(chart_repository::get($id), true));
        $copy->data->columns = $state->data->columns;
        $copy->data->queries[0]->run = $state->data->queries[0]->run;
        $copy->save();

        $newid = chart_saver::save($copy);
        $this->assertNotSame($id, $newid);
        $this->assertSame('Courses', chart_repository::get($id)->name);
        $this->assertSame(get_string('duplicatename', 'local_aicharts', 'Courses'), chart_repository::get($newid)->name);
    }

    /**
     * A trend chart draws its first point on the review page and stores none until it runs.
     */
    public function test_trend_review_draws_the_first_point(): void {
        $this->getDataGenerator()->create_course();
        $state = $this->state('trend', 'SELECT COUNT(c.id) AS total FROM {course} c WHERE c.id > :siteid');

        $form = $this->form($state);
        $form->prepare_review();
        $html = $form->render();
        $this->assertTrue($form->can_save());
        $this->assertStringContainsString(get_string('firstpointnote', 'local_aicharts'), $html);
        $this->assertStringContainsString(get_string('pointskept', 'local_aicharts', 365), $html);

        $state->data->name = 'Courses over time';
        $id = chart_saver::save($state);
        $this->assertSame('trend', chart_repository::get($id)->kind);
        $this->assertSame(0, point_store::timepoints($id));
    }
}
