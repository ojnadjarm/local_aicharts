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

use local_aicharts\local\chart_repository;
use local_aicharts\local\point_store;
use local_aicharts\local\result_store;
use local_aicharts\task\run_chart;
use stdClass;

/**
 * Tests for the chart page renderable.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class chart_page_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $DB->delete_records('local_aicharts_chart');
        $this->setAdminUser();
        set_config('maxrowsmax', 5000, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
        set_config('resultretention', 30, 'local_aicharts');
    }

    /**
     * Save a scheduled chart counting the users of the site.
     *
     * @param array $overrides Fields replacing the defaults.
     * @return stdClass The chart record.
     */
    private function create_chart(array $overrides = []): stdClass {
        $chart = (object) array_merge([
            'name' => 'Users',
            'prompt' => 'users by id',
            'queries' => [[
                'label' => 'Users',
                'sqltext' => 'SELECT id AS label, id AS total FROM {user} ORDER BY id',
                'params' => '{}',
            ]],
            'chartjson' => json_encode([
                'type' => 'bar',
                'title' => 'Users',
                'labelcolumn' => 'label',
                'series' => [['column' => 'total', 'label' => 'Total']],
            ]),
            'runmode' => 'weekly',
            'runhour' => 6,
            'maxrows' => 100,
            'enabled' => 1,
        ], $overrides);

        return chart_repository::get(chart_repository::save($chart));
    }

    /**
     * Export the page as view.php does.
     *
     * @param stdClass $chart The chart record.
     * @param stdClass|null $selected The run asked for.
     * @return array The template context.
     */
    private function export(stdClass $chart, ?stdClass $selected = null): array {
        global $PAGE;

        return (new chart_page($chart, $selected))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * A trend chart names its kind and says how many points it holds and keeps.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_trend_page_states_its_points(): void {
        global $OUTPUT;

        set_config('pointsretention', 365, 'local_aicharts');
        $chart = $this->create_chart(['kind' => 'trend', 'runmode' => 'daily']);
        point_store::append($chart, ['Users' => 4.0], time(), null);

        $context = $this->export($chart);
        $html = $OUTPUT->render_from_template('local_aicharts/chart_page', $context);

        $this->assertTrue($context['trend']);
        $this->assertStringContainsString('fa-arrow-trend-up', $html);
        $this->assertStringContainsString(get_string('trendmarker', 'local_aicharts'), $html);
        $this->assertStringContainsString(get_string('pointscount', 'local_aicharts', 1), $html);
        $this->assertStringContainsString(get_string('pointskept', 'local_aicharts', 365), $html);
    }

    /**
     * Every stored run is listed newest first with human trigger and status labels.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_lists_results_newest_first_with_labels(): void {
        global $DB, $USER;

        $chart = $this->create_chart();
        $first = run_chart::run($chart, 'save');
        $manual = run_chart::run($chart, 'manual', (int) $USER->id);
        $DB->set_field('local_aicharts_result', 'timecreated', $first->timecreated - 100, ['id' => $first->id]);

        $context = $this->export($chart);

        $this->assertTrue($context['hasresults']);
        $this->assertSame('Scheduled weekly on Monday at 06:00', $context['schedulelabel']);
        $this->assertStringContainsString('30', $context['runskept']);
        $this->assertCount(2, $context['rows']);
        $this->assertSame(
            [get_string('trigger_manual', 'local_aicharts', fullname($USER)), 'First run after save'],
            array_column($context['rows'], 'trigger')
        );
        $this->assertSame(['OK', 'OK'], array_column($context['rows'], 'status'));
        $this->assertTrue($context['rows'][0]['current']);
        $this->assertNotEmpty($context['rows'][1]['downloadurl']);
        $this->assertStringContainsString('resultid=' . $first->id, $context['rows'][1]['viewurl']);
        $this->assertSame((string) $manual->numrows, $context['rows'][0]['numrows']);
    }

    /**
     * Asking for an older run renders it and says the shown run is not the newest.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_selected_result_is_rendered(): void {
        global $DB;

        $chart = $this->create_chart();
        $older = run_chart::run($chart, 'scheduled');
        run_chart::run($chart, 'scheduled');
        $DB->set_field('local_aicharts_result', 'timecreated', $older->timecreated - 100, ['id' => $older->id]);

        $context = $this->export($chart, result_store::get((int) $older->id));

        $this->assertTrue($context['hasselected']);
        $this->assertTrue($context['hasresult']);
        $this->assertStringContainsString('chart-area', $context['body']);
        $this->assertStringContainsString('pluginfile.php', $context['csvurl']);
        $this->assertArrayHasKey('olderrun', $context);
        $this->assertStringContainsString('ms', $context['runmeta']);
        $this->assertTrue($context['rows'][1]['current']);
    }

    /**
     * A user who only receives the results can open the page.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_receiveresults_holder_can_open(): void {
        $chart = $this->create_chart();
        run_chart::run($chart, 'scheduled');

        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/aicharts:receiveresults', CAP_ALLOW, $role, \core\context\system::instance()->id);
        role_assign($role, $user->id, \core\context\system::instance()->id);
        $this->setUser($user);

        $context = $this->export($chart);

        $this->assertTrue($context['hasselected']);
        $this->assertCount(1, $context['rows']);
    }

    /**
     * A chart that never ran shows the empty state and no run.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_chart_without_results_shows_empty_state(): void {
        $context = $this->export($this->create_chart());

        $this->assertFalse($context['hasresults']);
        $this->assertArrayNotHasKey('hasselected', $context);
        $this->assertSame([], $context['rows']);
    }

    /**
     * A live chart is run when the page loads and its rows are rendered with a streaming CSV link.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     * @covers \local_aicharts\output\chart_page::stream_url
     */
    public function test_live_chart_renders_current_rows(): void {
        $context = $this->export($this->create_chart(['runmode' => 'live']));

        $this->assertTrue($context['live']);
        $this->assertSame('Live — runs when the dashboard or this page loads', $context['livesummary']);
        $this->assertTrue($context['hasselected']);
        $this->assertTrue($context['hasresult']);
        $this->assertStringContainsString('chart-area', $context['body']);
        $this->assertStringContainsString('ran just now', $context['runmeta']);
        $this->assertStringContainsString('download=csv', $context['csvurl']);
        $this->assertStringContainsString('sesskey=', $context['csvurl']);
        $this->assertFalse($context['hasresults']);
    }

    /**
     * The page keeps the data table toggle under the chart.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_page_keeps_chart_data_table(): void {
        $chart = $this->create_chart();
        run_chart::run($chart, 'scheduled');

        $context = $this->export($chart);

        $this->assertStringContainsString('chart-area', $context['body']);
        $this->assertStringContainsString(get_string('showchartdata'), $context['body']);
    }

    /**
     * A live chart keeps showing its current rows and lists its stored runs.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_live_chart_lists_manual_runs(): void {
        global $USER;

        $chart = $this->create_chart(['runmode' => 'live']);
        $manual = run_chart::run($chart, 'manual', (int) $USER->id);

        $context = $this->export($chart);

        $this->assertTrue($context['hasresults']);
        $this->assertStringContainsString('ran just now', $context['runmeta']);
        $this->assertCount(1, $context['rows']);
        $this->assertSame((string) $manual->numrows, $context['rows'][0]['numrows']);
        $this->assertNotEmpty($context['rows'][0]['downloadurl']);
    }

    /**
     * A paused live chart shows its paused state instead of running its query.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_paused_live_chart_is_not_run(): void {
        $context = $this->export($this->create_chart(['runmode' => 'live', 'enabled' => 0]));

        $this->assertTrue($context['paused']);
        $this->assertArrayNotHasKey('hasselected', $context);
        $this->assertArrayNotHasKey('body', $context);
    }

    /**
     * A user who only receives results sees the stored runs of a live chart, never a fresh query.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_live_chart_without_view_shows_no_rows(): void {
        $chart = $this->create_chart(['runmode' => 'live']);

        $user = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('local/aicharts:receiveresults', CAP_ALLOW, $role, \core\context\system::instance()->id);
        role_assign($role, $user->id, \core\context\system::instance()->id);
        $this->setUser($user);

        $context = $this->export($chart);

        $this->assertArrayNotHasKey('hasselected', $context);
        $this->assertArrayNotHasKey('csvurl', $context);
        $this->assertFalse($context['hasresults']);
    }

    /**
     * The CSV link streams for a live chart and points at the stored file for a stored run.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_download_url_is_stream_for_live_and_file_for_stored(): void {
        $live = $this->export($this->create_chart(['runmode' => 'live']));

        $chart = $this->create_chart(['name' => 'Stored']);
        run_chart::run($chart, 'scheduled');
        $stored = $this->export($chart);

        $this->assertStringContainsString('/local/aicharts/view.php', $live['csvurl']);
        $this->assertStringContainsString('download=csv', $live['csvurl']);
        $this->assertStringContainsString('/pluginfile.php/', $stored['csvurl']);
        $this->assertStringContainsString('/local_aicharts/result/', $stored['csvurl']);
    }

    /**
     * Streaming sends the header row and the current rows as CSV without storing a run.
     *
     * @covers \local_aicharts\output\chart_page::stream_csv
     */
    public function test_stream_csv_sends_current_rows(): void {
        $chart = $this->create_chart(['runmode' => 'live']);

        $this->expectOutputRegex('/label,total\n(\d+,\d+\n)+$/');
        chart_page::stream_csv($chart);

        $this->assertSame([], result_store::list_for_chart((int) $chart->id));
    }

    /**
     * A live render marks the newest stored run as the latest one, not as the run shown.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_live_render_marks_the_newest_stored_run_as_the_latest(): void {
        global $DB, $OUTPUT, $USER;

        $chart = $this->create_chart(['runmode' => 'live']);
        $older = run_chart::run($chart, 'manual', (int) $USER->id);
        run_chart::run($chart, 'manual', (int) $USER->id);
        $DB->set_field('local_aicharts_result', 'timecreated', $older->timecreated - 100, ['id' => $older->id]);

        $context = $this->export($chart);
        $html = $OUTPUT->render_from_template('local_aicharts/chart_page', $context);

        $this->assertSame([false, false], array_column($context['rows'], 'current'));
        $this->assertSame([true, false], array_column($context['rows'], 'latest'));
        $this->assertStringContainsString(get_string('latestrun', 'local_aicharts'), $html);
        $this->assertStringNotContainsString(get_string('thisrun', 'local_aicharts'), $html);
    }

    /**
     * A live chart can open a past run of its own history instead of the current one.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_live_chart_selects_an_older_run(): void {
        global $DB, $USER;

        $chart = $this->create_chart(['runmode' => 'live']);
        $older = run_chart::run($chart, 'manual', (int) $USER->id);
        run_chart::run($chart, 'manual', (int) $USER->id);
        $DB->set_field('local_aicharts_result', 'timecreated', $older->timecreated - 100, ['id' => $older->id]);

        $context = $this->export($chart, result_store::get((int) $older->id));

        $this->assertCount(2, $context['rows']);
        $this->assertTrue($context['hasselected']);
        $this->assertTrue($context['hasresult']);
        $this->assertArrayHasKey('olderrun', $context);
        $this->assertStringContainsString('pluginfile.php', $context['csvurl']);
        $this->assertTrue($context['rows'][1]['current']);
        $this->assertSame([false, false], array_column($context['rows'], 'latest'));
    }

    /**
     * The page offers Run now until a run is stored and Run again afterwards.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_page_run_button_is_named_for_what_the_chart_holds(): void {
        global $OUTPUT, $USER;

        $chart = $this->create_chart();

        $context = $this->export($chart);
        $this->assertTrue($context['runagain']);
        $this->assertSame(get_string('runnow', 'local_aicharts'), $context['runlabel']);
        $this->assertStringContainsString('data-action="runnow"', $OUTPUT->render_from_template(
            'local_aicharts/chart_page',
            $context
        ));

        run_chart::run($chart, 'manual', (int) $USER->id);

        $this->assertSame(get_string('runagain', 'local_aicharts'), $this->export($chart)['runlabel']);
    }

    /**
     * The run button stays on the page of a paused chart and while an older run is shown.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_page_run_button_survives_pausing_and_older_runs(): void {
        global $USER;

        $chart = $this->create_chart();
        $first = run_chart::run($chart, 'manual', (int) $USER->id);
        run_chart::run($chart, 'manual', (int) $USER->id);

        $older = $this->export($chart, result_store::get((int) $first->id));
        $this->assertSame(get_string('runagain', 'local_aicharts'), $older['runlabel']);

        $chart->enabled = 0;
        $this->assertTrue($this->export($chart)['runagain']);
    }

    /**
     * A user without the manage capability gets no run button.
     *
     * @covers \local_aicharts\output\chart_page::export_for_template
     */
    public function test_page_run_button_needs_the_manage_capability(): void {
        global $OUTPUT;

        $chart = $this->create_chart();
        $this->setUser($this->getDataGenerator()->create_user());

        $html = $OUTPUT->render_from_template('local_aicharts/chart_page', $this->export($chart));

        $this->assertStringNotContainsString('data-action="runnow"', $html);
    }
}
