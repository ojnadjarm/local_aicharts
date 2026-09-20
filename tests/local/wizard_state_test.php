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

namespace local_aicharts\local;

/**
 * Tests for the wizard state kept in the session.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\local\wizard_state
 */
final class wizard_state_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A saved chart with two queries.
     *
     * @return \stdClass
     */
    protected function saved_chart(): \stdClass {
        $id = chart_repository::save((object) [
            'name' => 'Enrolments per course',
            'prompt' => 'enrolments per course',
            'kind' => 'trend',
            'queries' => [
                ['label' => 'Enrolments', 'sqltext' => 'SELECT c.fullname, COUNT(ue.id) FROM {course} c', 'params' => '{}'],
                ['label' => 'Courses', 'sqltext' => 'SELECT COUNT(*) FROM {course}', 'params' => '{"siteid": 1}'],
            ],
            'chartjson' => '{"type":"bar"}',
        ]);
        return chart_repository::get($id);
    }

    /**
     * Starting from a chart seeds the queries, none of them run, and the state stays small.
     */
    public function test_start_from_chart_seeds_queries(): void {
        $chart = $this->saved_chart();

        $token = wizard_state::start($chart);
        $state = wizard_state::open($token);

        $this->assertSame($token, $state->token);
        $this->assertEquals($chart->id, $state->data->id);
        $this->assertSame('trend', $state->data->kind);
        $this->assertSame('Enrolments per course', $state->data->name);
        $this->assertCount(2, $state->data->queries);
        $this->assertSame('Enrolments', $state->data->queries[0]->label);
        $this->assertSame('enrolments per course', $state->data->queries[0]->hint);
        $this->assertSame('', $state->data->queries[1]->hint);
        $this->assertSame('{"siteid": 1}', $state->data->queries[1]->params);
        $this->assertNull($state->data->queries[0]->run);
        $this->assertNull($state->data->queries[1]->run);
        $this->assertLessThan(8192, strlen(json_encode($state->data)));

        $copy = wizard_state::open(wizard_state::start($chart, true));
        $this->assertSame(0, $copy->data->id);
        $this->assertTrue($copy->data->duplicate);
        $this->assertSame(get_string('duplicatename', 'local_aicharts', $chart->name), $copy->data->name);

        $blank = wizard_state::open(wizard_state::start(null));
        $this->assertSame('', $blank->data->kind);
        $this->assertSame([], $blank->data->queries);
    }

    /**
     * Resetting the runs keeps the queries but forgets their results and the columns.
     */
    public function test_reset_runs_keeps_queries(): void {
        $state = wizard_state::open(wizard_state::start($this->saved_chart()));
        $run = (object) ['hash' => 'abc', 'columns' => ['fullname', 'count'], 'rowcount' => 3];
        $state->data->queries[0]->run = $run;
        $state->data->queries[1]->run = $run;
        $state->data->columns = ['fullname', 'Enrolments', 'Courses'];
        $state->data->lastrun = $run;

        $state->reset_runs();

        $this->assertCount(2, $state->data->queries);
        $this->assertSame('Enrolments', $state->data->queries[0]->label);
        $this->assertNull($state->data->queries[0]->run);
        $this->assertNull($state->data->queries[1]->run);
        $this->assertSame([], $state->data->columns);
        $this->assertNull($state->data->lastrun);
    }

    /**
     * An unknown or empty token opens nothing.
     */
    public function test_open_unknown_token_is_null(): void {
        wizard_state::start(null);

        $this->assertNull(wizard_state::open('nosuchtoken'));
        $this->assertNull(wizard_state::open(''));
    }

    /**
     * Discarding a wizard forgets its token; other wizards are untouched.
     */
    public function test_discard(): void {
        $first = wizard_state::start(null);
        $second = wizard_state::start(null);

        wizard_state::open($first)->discard();

        $this->assertNull(wizard_state::open($first));
        $this->assertNotNull(wizard_state::open($second));
    }

    /**
     * Series can be moved and removed; edges and unknown positions are ignored.
     */
    public function test_move_and_remove(): void {
        $state = wizard_state::open(wizard_state::start($this->saved_chart()));

        $state->move(1, -1);
        $this->assertSame(['Courses', 'Enrolments'], array_column($state->data->queries, 'label'));

        $state->move(0, -1);
        $state->move(1, 1);
        $state->move(5, -1);
        $this->assertSame(['Courses', 'Enrolments'], array_column($state->data->queries, 'label'));

        $state->remove(0);
        $state->remove(7);
        $this->assertSame(['Enrolments'], array_column($state->data->queries, 'label'));
        $this->assertArrayHasKey(0, $state->data->queries);
    }

    /**
     * The step and action URLs carry the token.
     */
    public function test_urls(): void {
        $state = wizard_state::open(wizard_state::start(null));

        $this->assertSame($state->token, $state->url(3)->get_param('w'));
        $this->assertSame('3', $state->url(3)->get_param('step'));

        $remove = $state->action_url('remove', 1);
        $this->assertSame('remove', $remove->get_param('action'));
        $this->assertSame('1', $remove->get_param('index'));
        $this->assertSame(sesskey(), $remove->get_param('sesskey'));
        $this->assertNull($state->action_url('discard')->get_param('index'));
    }
}
