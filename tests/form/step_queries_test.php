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
 * Tests for the Data step of the wizard.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\form\step_queries
 */
final class step_queries_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('maxrowsdefault', 100, 'local_aicharts');
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
    }

    /**
     * A wizard of the given kind holding the given series.
     *
     * @param string $kind Chart kind.
     * @param \stdClass[] $queries Series records.
     * @return wizard_state
     */
    protected function state(string $kind, array $queries): wizard_state {
        $state = wizard_state::open(wizard_state::start(null));
        $state->data->kind = $kind;
        $state->data->queries = $queries;
        return $state;
    }

    /**
     * A series that has been run, with the result of a query returning one row of label and value.
     *
     * @param string $label Series name.
     * @param string $sql The query.
     * @param int $rowcount Rows the run returned.
     * @return \stdClass
     */
    protected function run_series(string $label, string $sql, int $rowcount = 1): \stdClass {
        $run = (object) ['hash' => sha1($sql), 'columns' => ['label', 'total'], 'rowcount' => $rowcount, 'durationms' => 2];
        return wizard_state::query($label, '', $sql, '{}', $run);
    }

    /**
     * The step form of a wizard.
     *
     * @param wizard_state $state The wizard.
     * @return step_queries
     */
    protected function form(wizard_state $state): step_queries {
        return new step_queries($state->url(2)->out(false), ['state' => $state], 'post', '', ['id' => 'local-aicharts-step']);
    }

    /**
     * Next needs at least one series and every series run.
     */
    public function test_next_requires_a_run_series(): void {
        $form = $this->form($this->state('oneshot', []));
        $errors = $form->validation(['step' => 2], []);
        $this->assertSame(get_string('seriesrequired', 'local_aicharts'), $errors['serieslist']);

        $notrun = wizard_state::query('Users', '', 'SELECT COUNT(*) FROM {user}', '{}');
        $form = $this->form($this->state('oneshot', [$notrun]));
        $errors = $form->validation(['step' => 2], []);
        $this->assertSame(get_string('seriesnotrun', 'local_aicharts', 'Users'), $errors['serieslist']);
        $this->assertStringContainsString(get_string('notrun', 'local_aicharts'), $form->render());
    }

    /**
     * The kind line names the kind and links back to step 1; a changed kind is announced.
     */
    public function test_kind_line_links_to_step_1(): void {
        $state = $this->state('trend', []);
        $html = $this->form($state)->render();

        $this->assertStringContainsString(get_string('kindtrend', 'local_aicharts'), $html);
        $this->assertStringContainsString(get_string('kindtrend_desc', 'local_aicharts'), $html);
        $this->assertStringContainsString('href="' . s($state->url(1)->out(false)) . '"', $html);
        $this->assertStringContainsString(get_string('trendseriesrule', 'local_aicharts'), $html);
        $this->assertStringNotContainsString(get_string('kindchanged', 'local_aicharts'), $html);

        $state->data->kindchanged = true;
        $this->assertStringContainsString(get_string('kindchanged', 'local_aicharts'), $this->form($state)->render());
    }

    /**
     * Next runs every series together and records the columns of the merged result.
     */
    public function test_next_runs_all_and_stores_columns(): void {
        $this->getDataGenerator()->create_course();
        $courses = $this->run_series('Courses', "SELECT 'courses' AS label, COUNT(*) AS total FROM {course}");
        $users = $this->run_series('Users', "SELECT 'users' AS label, COUNT(*) AS total FROM {user}");

        $form = $this->form($this->state('oneshot', [$courses]));
        $this->assertSame([], $form->validation(['step' => 2], []));
        $this->assertSame(['label', 'total'], $form->columns());

        $form = $this->form($this->state('oneshot', [$courses, $users]));
        $this->assertSame([], $form->validation(['step' => 2], []));
        $this->assertSame(['label', 'Courses', 'Users'], $form->columns());

        $form = $this->form($this->state('trend', [$courses, $users]));
        $this->assertSame([], $form->validation(['step' => 2], []));
        $this->assertSame(['runtime', 'Courses', 'Users'], $form->columns());

        $broken = $this->run_series('Broken', 'SELECT COUNT(*) AS total FROM {user}');
        $form = $this->form($this->state('oneshot', [$courses, $broken]));
        $errors = $form->validation(['step' => 2], []);
        $this->assertNotEmpty($errors['serieslist']);
        $this->assertSame([], $form->columns());
    }

    /**
     * Two series with the same label are refused.
     */
    public function test_next_rejects_duplicate_labels(): void {
        $first = $this->run_series('Users', "SELECT 'a' AS label, COUNT(*) AS total FROM {user}");
        $second = $this->run_series('Users', "SELECT 'b' AS label, COUNT(*) AS total FROM {user}");

        $errors = $this->form($this->state('oneshot', [$first, $second]))->validation(['step' => 2], []);

        $this->assertSame(get_string('labelduplicate', 'local_aicharts'), $errors['serieslist']);
    }

    /**
     * A trend series that returned several rows is refused before anything runs.
     */
    public function test_trend_next_rejects_multi_row_series(): void {
        $series = $this->run_series('Users', 'SELECT username AS label, id AS total FROM {user}', 3);

        $errors = $this->form($this->state('trend', [$series]))->validation(['step' => 2], []);

        $a = (object) ['label' => 'Users', 'rows' => 3];
        $this->assertSame(get_string('error_trendrows', 'local_aicharts', $a), $errors['serieslist']);
    }
}
