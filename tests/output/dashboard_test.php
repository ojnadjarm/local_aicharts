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

/**
 * Tests for the dashboard renderable.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class dashboard_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $DB->delete_records('local_aicharts_chart');
        $this->setAdminUser();
        set_config('maxrowsmax', 5000, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
    }

    /**
     * Save a chart counting the users of the site.
     *
     * @param array $overrides Fields replacing the defaults.
     * @return int The chart id.
     */
    private function create_chart(array $overrides = []): int {
        $chart = (object) array_merge([
            'name' => 'Users',
            'prompt' => 'users by id',
            'queries' => [[
                'label' => 'Total',
                'sqltext' => 'SELECT id AS label, id AS total FROM {user} ORDER BY id',
                'params' => '{}',
            ]],
            'chartjson' => json_encode([
                'type' => 'bar',
                'title' => 'Users',
                'labelcolumn' => 'label',
                'series' => [['column' => 'total', 'label' => 'Total']],
            ]),
            'runmode' => 'live',
            'maxrows' => 100,
            'enabled' => 1,
        ], $overrides);

        return chart_repository::save($chart);
    }

    /**
     * Export the dashboard as the page does.
     *
     * @param string|null $view grid or list; null takes the user preference.
     * @param bool $skiplive Whether every live chart is deferred.
     * @return array The template context.
     */
    private function export(?string $view = null, bool $skiplive = false, int $saved = 0): array {
        global $PAGE;

        return (new dashboard($view, $skiplive, $saved))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Scheduled charts come first, then charts by name.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_exports_cards_in_order(): void {
        $this->create_chart(['name' => 'Alpha']);
        $this->create_chart(['name' => 'Zulu', 'runmode' => 'daily']);

        $context = $this->export('grid');

        $this->assertTrue($context['hascharts']);
        $this->assertSame(['Zulu', 'Alpha'], array_column($context['cards'], 'name'));
    }

    /**
     * The chart that was just saved is the first card, wherever its name sorts.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_just_saved_chart_comes_first(): void {
        $this->create_chart(['name' => 'Alpha', 'runmode' => 'daily']);
        $saved = $this->create_chart(['name' => 'Zulu', 'runmode' => 'daily']);

        $this->assertSame(['Alpha', 'Zulu'], array_column($this->export('grid')['cards'], 'name'));
        $this->assertSame(['Zulu', 'Alpha'], array_column($this->export('grid', false, $saved)['cards'], 'name'));
        $this->assertSame(['Zulu', 'Alpha'], array_column($this->export('list', false, $saved)['rows'], 'name'));
    }

    /**
     * A live chart runs on load and its result is rendered.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_live_card_renders_chart(): void {
        $this->getDataGenerator()->create_user();
        $this->create_chart();

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['hasresult']);
        $this->assertStringContainsString('chart-area', $card['body']);
        $this->assertStringContainsString('limit 100', $card['rowsline']);
        $this->assertSame('fa-chart-column', $card['icon']);
    }

    /**
     * A live chart whose query returns nothing shows the empty state, not a failure.
     *
     * @covers \local_aicharts\output\dashboard::render_rows
     */
    public function test_live_card_without_rows_is_empty_state(): void {
        $this->create_chart(['queries' => [[
            'label' => 'Total',
            'sqltext' => 'SELECT id AS label, id AS total FROM {user} WHERE id < 0',
            'params' => '{}',
        ]]]);

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['hasresult']);
        $this->assertTrue($card['norows']);
        $this->assertArrayNotHasKey('failed', $card);
        $this->assertArrayNotHasKey('body', $card);
        $this->assertStringContainsString('0 rows', $card['rowsline']);
    }

    /**
     * A chart card carries the chart alone, without the data table toggle.
     *
     * @covers \local_aicharts\output\dashboard::render_rows
     */
    public function test_chart_card_has_no_data_table(): void {
        $this->getDataGenerator()->create_user();
        $this->create_chart();

        $card = $this->export('grid')['cards'][0];

        $this->assertStringContainsString('chart-area', $card['body']);
        $this->assertStringNotContainsString(get_string('showchartdata'), $card['body']);
    }

    /**
     * A query-only card shows a fixed-height preview that links to the chart page.
     *
     * @covers \local_aicharts\output\dashboard::render_rows
     */
    public function test_query_card_links_to_page(): void {
        for ($i = 0; $i < 6; $i++) {
            $this->getDataGenerator()->create_user();
        }
        $id = $this->create_chart(['chartjson' => json_encode(['type' => 'table'])]);

        $card = $this->export('grid')['cards'][0];

        $this->assertStringContainsString('local-aicharts-preview', $card['body']);
        $this->assertStringContainsString('view.php?id=' . $id, $card['body']);
        $this->assertStringContainsString('Open — ', $card['body']);
        $this->assertStringNotContainsString('<details>', $card['body']);
    }

    /**
     * A deferred live card that never stored a run offers Run now and nothing else to press.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     * @covers \local_aicharts\output\dashboard::stored
     */
    public function test_deferred_card_never_run_offers_run_now_only(): void {
        global $OUTPUT;

        $id = $this->create_chart();

        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $this->export('grid', true)['cards'][0]);

        $this->assertMatchesRegularExpression('/<button[^>]*data-action="runnow"[^>]*data-id="' . $id . '"[^>]*>/', $html);
        $this->assertSame(1, substr_count($html, 'data-action="runnow"'));
        $this->assertStringNotContainsString('data-action="runcard"', $html);
        $this->assertStringContainsString(get_string('runnow', 'local_aicharts'), $html);
        $this->assertStringContainsString('limit 100 · not run', $html);
    }

    /**
     * A deferred live card that has a stored run draws it and offers Run again.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     * @covers \local_aicharts\output\dashboard::stored
     */
    public function test_deferred_card_with_a_stored_run_draws_it(): void {
        global $OUTPUT;

        $this->getDataGenerator()->create_user();
        $chart = chart_repository::get($this->create_chart());
        run_chart::run($chart, 'manual');

        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $this->export('grid', true)['cards'][0]);

        $this->assertStringContainsString('chart-area', $html);
        $this->assertStringContainsString(get_string('runagain', 'local_aicharts'), $html);
        $this->assertStringNotContainsString(get_string('notloaded', 'local_aicharts'), $html);
        $this->assertStringNotContainsString(get_string('ranjustnow', 'local_aicharts'), $html);
    }

    /**
     * A live card that has never stored a run labels its run button Run now, not Run again.
     *
     * @covers \local_aicharts\output\dashboard::run
     * @covers \local_aicharts\output\dashboard::run_again
     */
    public function test_live_card_never_run_says_run_now(): void {
        global $DB, $OUTPUT;

        $id = $this->create_chart();

        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $this->export('grid')['cards'][0]);
        $this->assertStringContainsString(get_string('runnow', 'local_aicharts'), $html);
        $this->assertStringNotContainsString(get_string('runagain', 'local_aicharts'), $html);

        $DB->set_field('local_aicharts_chart', 'lastrun', time(), ['id' => $id]);
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $this->export('grid')['cards'][0]);
        $this->assertStringContainsString(get_string('runagain', 'local_aicharts'), $html);
    }

    /**
     * Live charts beyond the first six are left to be loaded on request.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_live_cards_beyond_six_are_deferred(): void {
        for ($i = 1; $i <= 8; $i++) {
            $this->create_chart(['name' => 'Chart ' . $i]);
        }

        $deferred = array_column($this->export('grid')['cards'], 'deferred');

        $this->assertSame(array_fill(0, dashboard::LIVE_ON_LOAD, false), array_slice($deferred, 0, 6));
        $this->assertSame([true, true], array_slice($deferred, 6));
    }

    /**
     * The site setting decides how many live charts run on load, 0 deferring them all.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     * @covers \local_aicharts\output\dashboard::live_on_load
     */
    public function test_liveonload_setting_bounds_the_cards_run(): void {
        for ($i = 1; $i <= 3; $i++) {
            $this->create_chart(['name' => 'Chart ' . $i]);
        }

        set_config('liveonload', 2, 'local_aicharts');
        $this->assertSame([false, false, true], array_column($this->export('grid')['cards'], 'deferred'));

        set_config('liveonload', 0, 'local_aicharts');
        $this->assertSame([true, true, true], array_column($this->export('grid')['cards'], 'deferred'));
    }

    /**
     * The skiplive parameter defers every live chart.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_skiplive_defers_all(): void {
        $this->create_chart(['name' => 'Alpha']);
        $this->create_chart(['name' => 'Beta']);

        $cards = $this->export('grid', true)['cards'];

        $this->assertSame([true, true], array_column($cards, 'deferred'));
        $this->assertArrayNotHasKey('body', $cards[0]);
    }

    /**
     * A paused chart is not run.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_paused_card_is_not_run(): void {
        $this->create_chart(['enabled' => 0]);

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['paused']);
        $this->assertFalse($card['deferred']);
        $this->assertArrayNotHasKey('body', $card);
        $this->assertStringNotContainsString('data-action="runnow"', $card['menu']);
    }

    /**
     * A scheduled card renders its latest stored result instead of querying.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_scheduled_card_uses_stored_result(): void {
        $id = $this->create_chart(['runmode' => 'weekly', 'runhour' => 6]);
        $chart = chart_repository::get($id);
        result_store::store($chart, [['label' => 1, 'total' => 3]], 'scheduled', 0);

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['scheduled']);
        $this->assertTrue($card['hasresult']);
        $this->assertStringContainsString('chart-area', $card['body']);
        $this->assertStringContainsString('scheduled weekly', $card['runmodelabel']);
        $this->assertSame('06:00', $card['runhourformatted']);
        $this->assertStringContainsString('last run', $card['lastrunformatted']);
    }

    /**
     * The card footer, the hidden label and the list Mode column name the run day.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_schedule_label_names_the_day(): void {
        $this->create_chart(['name' => 'Weekly', 'runmode' => 'weekly', 'runhour' => 6, 'runday' => 3]);
        $this->create_chart(['name' => 'Monthly', 'runmode' => 'monthly', 'runhour' => 7, 'runday' => 31]);

        $cards = $this->export('grid')['cards'];
        $rows = $this->export('list')['rows'];

        $this->assertSame('scheduled monthly on the last day', $cards[0]['runmodelabel']);
        $this->assertSame('Scheduled monthly on the last day at 07:00', $cards[0]['schedulelabel']);
        $this->assertSame('scheduled weekly on Wednesday', $cards[1]['runmodelabel']);
        $this->assertSame('Scheduled weekly on Wednesday at 06:00', $cards[1]['schedulelabel']);
        $this->assertSame('Monthly on the last day · 07:00', $rows[0]['mode']);
        $this->assertSame('Weekly on Wednesday · 06:00', $rows[1]['mode']);
    }

    /**
     * A scheduled card with no stored result offers its next run time.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_scheduled_card_without_result_is_not_run_yet(): void {
        $this->create_chart(['runmode' => 'daily', 'runhour' => 4]);

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['notrunyet']);
        $this->assertFalse($card['queued']);
        $this->assertArrayNotHasKey('body', $card);
        $this->assertStringContainsString('Next run', $card['nextrunformatted']);
    }

    /**
     * A chart whose run waits for cron is flagged as queued.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_queued_card_is_flagged(): void {
        $id = $this->create_chart(['runmode' => 'daily']);
        \core\task\manager::queue_adhoc_task(run_chart::instance($id, 'scheduled'));

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['queued']);
    }

    /**
     * A late cron is reported while a scheduled chart waits for it.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_stalecron_flag(): void {
        $this->create_chart(['runmode' => 'daily']);
        set_config('lastcronstart', time() - DAYSECS, 'tool_task');

        $context = $this->export('grid');

        $this->assertTrue($context['stalecron']);
        $this->assertStringContainsString('Cron last ran', $context['stalecronmessage']);
    }

    /**
     * The list view honours the user preference and queries nothing.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_list_view_preference(): void {
        $this->create_chart(['name' => 'Alpha']);
        set_user_preference('local_aicharts_view', 'list');

        $context = $this->export();

        $this->assertTrue($context['listview']);
        $this->assertSame([], $context['cards']);
        $this->assertSame('Alpha', $context['rows'][0]['name']);
        $this->assertSame('Live', $context['rows'][0]['mode']);
    }

    /**
     * Every card and row links its name to the chart page and offers View in its menu.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     * @covers \local_aicharts\output\dashboard::view_url
     */
    public function test_name_links_to_the_chart_page(): void {
        global $OUTPUT;

        $live = $this->create_chart(['name' => 'Live one']);
        $this->create_chart(['name' => 'Daily one', 'runmode' => 'daily']);

        $cards = $this->export('grid')['cards'];
        $rows = $this->export('list')['rows'];
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $cards[1]);

        $this->assertStringContainsString('/local/aicharts/view.php?id=' . $live, $cards[1]['viewurl']);
        $this->assertStringContainsString('/local/aicharts/view.php?id=' . $live, $rows[1]['viewurl']);
        $this->assertStringContainsString('href="' . $cards[1]['viewurl'] . '">Live one</a>', $html);
        $this->assertStringContainsString('>View</', $cards[1]['menu']);
        $this->assertStringContainsString('>View</', $cards[0]['menu']);
        $this->assertStringNotContainsString('history.php', $html);
    }

    /**
     * The list State column names a scheduled chart that never ran or whose last run failed.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_list_state_names_not_run_and_failed(): void {
        $this->create_chart(['name' => 'Fresh', 'runmode' => 'daily']);
        $failed = $this->create_chart(['name' => 'Broken', 'runmode' => 'daily']);
        $this->create_chart(['name' => 'Live one']);
        $chart = chart_repository::get($failed);
        result_store::store($chart, [['label' => 1, 'total' => 3]], 'scheduled', 0);
        $chart->queries[0]->sqltext = 'SELECT id FROM {nosuchtable}';
        run_chart::run($chart, 'scheduled');

        $rows = $this->export('list')['rows'];

        $this->assertSame(['Broken', 'Fresh', 'Live one'], array_column($rows, 'name'));
        $this->assertSame(['last run failed', 'Not run yet.', '—'], array_column($rows, 'state'));
    }

    /**
     * A paused scheduled card keeps its schedule in the footer before the paused word.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_paused_card_footer_keeps_schedule(): void {
        global $OUTPUT;

        $this->create_chart(['runmode' => 'daily', 'runhour' => 22, 'enabled' => 0]);

        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $this->export('grid')['cards'][0]);

        $this->assertStringContainsString('scheduled daily · 22:00 · Paused', $html);
    }

    /**
     * A live card offers Pause in its menu, like a scheduled one, and Run now nowhere in it.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_live_card_menu_has_pause_and_no_run_now(): void {
        $this->create_chart();

        $menu = $this->export('grid')['cards'][0]['menu'];

        $this->assertStringNotContainsString('data-action="runnow"', $menu);
        $this->assertStringContainsString('data-action="togglechart"', $menu);
        $this->assertStringContainsString('>Pause</', $menu);
    }

    /**
     * A live card whose manual run waits for cron is flagged as queued in its footer.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_live_queued_card_is_flagged(): void {
        global $OUTPUT;

        $id = $this->create_chart();
        \core\task\manager::queue_adhoc_task(run_chart::instance($id, 'manual', 2));

        $card = $this->export('grid')['cards'][0];
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $card);

        $this->assertTrue($card['queued']);
        $this->assertTrue($card['hasresult']);
        $this->assertStringContainsString('· queued', $html);
    }

    /**
     * A not-run-yet card carries a Run now button in its body, disabled once queued.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_not_run_yet_card_body_has_run_now_button(): void {
        global $OUTPUT;

        $id = $this->create_chart(['runmode' => 'daily']);

        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $this->export('grid')['cards'][0]);
        $this->assertMatchesRegularExpression('/<button[^>]*data-action="runnow"[^>]*data-id="' . $id . '"[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-action="runnow"[^>]*disabled/', $html);

        \core\task\manager::queue_adhoc_task(run_chart::instance($id, 'manual', 2));
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $this->export('grid')['cards'][0]);
        $this->assertMatchesRegularExpression('/<button[^>]*data-action="runnow"[^>]*disabled/', $html);
    }

    /**
     * The first run replaces the Run now button of a card with Run again beside the stored result.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_first_run_turns_run_now_into_run_again(): void {
        global $OUTPUT;

        $id = $this->create_chart(['runmode' => 'daily']);

        $card = $this->export('grid')['cards'][0];
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $card);
        $this->assertTrue($card['notrunyet']);
        $this->assertArrayNotHasKey('runagain', $card);
        $this->assertStringContainsString('Run now', $html);
        $this->assertStringNotContainsString('Run again', $html);

        result_store::store(chart_repository::get($id), [['label' => 1, 'total' => 3]], 'manual', 2);

        $card = $this->export('grid')['cards'][0];
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $card);
        $this->assertArrayNotHasKey('notrunyet', $card);
        $this->assertTrue($card['runagain']);
        $this->assertStringContainsString('last run', $card['lastrunformatted']);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-action="runnow"[^>]*data-id="' . $id . '"[^>]*>\s*<i[^>]*><\/i>Run again/',
            $html
        );
        $this->assertStringNotContainsString('Run now', $html);
    }

    /**
     * A card whose only run failed keeps Run again, so the run can be repeated.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_failed_card_offers_run_again(): void {
        $id = $this->create_chart(['runmode' => 'daily']);
        result_store::store(chart_repository::get($id), [], 'manual', 2, 'Database error');

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['failed']);
        $this->assertTrue($card['runagain']);
    }

    /**
     * Add chart, Edit and Duplicate are links into the wizard, not modal launchers.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_edit_links_to_wizard(): void {
        global $OUTPUT;

        $id = $this->create_chart();
        $context = $this->export('grid');
        $html = $OUTPUT->render_from_template('local_aicharts/dashboard', $context);
        $menu = $context['cards'][0]['menu'];

        $this->assertStringContainsString('/local/aicharts/edit.php', $context['addurl']);
        $this->assertStringNotContainsString('data-action="addchart"', $html);
        $this->assertStringNotContainsString('data-action="editchart"', $menu);
        $this->assertStringNotContainsString('data-action="duplicatechart"', $menu);
        $this->assertStringContainsString('edit.php?id=' . $id . '"', $menu);
        $this->assertStringContainsString('edit.php?id=' . $id . '&amp;duplicate=1"', $menu);
    }

    /**
     * A trend card carries the trend icon and how many points it holds.
     *
     * @covers \local_aicharts\output\dashboard::export_trend
     */
    public function test_trend_card_shows_its_points(): void {
        global $OUTPUT;

        $id = $this->create_chart(['runmode' => 'daily', 'kind' => 'trend']);
        $chart = chart_repository::get($id);
        point_store::append($chart, ['Total' => 2.0], time() - DAYSECS, null);
        point_store::append($chart, ['Total' => 3.0], time(), null);

        $card = $this->export('grid')['cards'][0];
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $card);

        $this->assertTrue($card['trend']);
        $this->assertSame(get_string('pointscount', 'local_aicharts', 2), $card['pointsline']);
        $this->assertStringContainsString('fa-arrow-trend-up', $html);
        $this->assertStringContainsString(get_string('trendmarker', 'local_aicharts'), $html);
        $this->assertStringContainsString('· 2 points', $html);

        $row = $this->export('list')['rows'][0];
        $html = $OUTPUT->render_from_template('local_aicharts/chart_list', ['rows' => [$row]]);
        $this->assertTrue($row['trend']);
        $this->assertStringContainsString('fa-arrow-trend-up', $html);
    }

    /**
     * A live card renders live, offers Run again and names its last stored run.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_live_card_offers_run_again_and_names_its_last_stored_run(): void {
        global $OUTPUT, $USER;

        $id = $this->create_chart();

        $card = $this->export('grid')['cards'][0];
        $this->assertTrue($card['hasresult']);
        $this->assertTrue($card['runagain']);
        $this->assertArrayNotHasKey('lastrunformatted', $card);

        run_chart::run(chart_repository::get($id), 'manual', (int) $USER->id);

        $card = $this->export('grid')['cards'][0];
        $html = $OUTPUT->render_from_template('local_aicharts/chart_card', $card);
        $this->assertTrue($card['hasresult']);
        $this->assertStringContainsString('last run', $card['lastrunformatted']);
        $this->assertStringContainsString($card['lastrunformatted'], $html);
        $this->assertMatchesRegularExpression(
            '/<button[^>]*data-action="runnow"[^>]*data-id="' . $id . '"[^>]*>\s*<i[^>]*><\/i>Run again/',
            $html
        );
    }

    /**
     * Loading the dashboard renders a live card without storing a run.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_live_card_load_stores_no_run(): void {
        $id = $this->create_chart();

        $this->export('grid');
        $this->export('grid');

        $this->assertSame([], result_store::list_for_chart($id));
    }

    /**
     * The name filter is rendered as soon as one chart exists.
     *
     * @covers \local_aicharts\output\dashboard::export_for_template
     */
    public function test_filter_is_shown_with_one_chart(): void {
        global $OUTPUT;

        $this->create_chart(['name' => 'Alpha']);

        $html = $OUTPUT->render_from_template('local_aicharts/dashboard', $this->export('grid'));

        $this->assertStringContainsString('data-region="aic-filter"', $html);
        $this->assertStringContainsString('fa-magnifying-glass', $html);
    }
}
