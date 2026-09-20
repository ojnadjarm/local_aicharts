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
        set_config('allowedtables', "user\ncourse\n", 'local_aicharts');
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
            'sqltext' => 'SELECT id AS label, id AS total FROM {user} ORDER BY id',
            'params' => '{}',
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
    private function export(?string $view = null, bool $skiplive = false): array {
        global $PAGE;

        return (new dashboard($view, $skiplive))->export_for_template($PAGE->get_renderer('core'));
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
        $this->create_chart(['sqltext' => 'SELECT id AS label, id AS total FROM {user} WHERE id < 0']);

        $card = $this->export('grid')['cards'][0];

        $this->assertTrue($card['hasresult']);
        $this->assertTrue($card['norows']);
        $this->assertArrayNotHasKey('failed', $card);
        $this->assertArrayNotHasKey('body', $card);
        $this->assertStringContainsString('0 rows', $card['rowsline']);
    }

    /**
     * The tab strip links the dashboard and, for site administrators, the settings page.
     *
     * @covers \local_aicharts\output\dashboard::tabs
     */
    public function test_tabs_include_settings_for_admins(): void {
        $tabs = $this->export('grid')['tabs'];

        $this->assertStringContainsString('section=local_aicharts', $tabs);
        $this->assertStringContainsString(get_string('charts', 'local_aicharts'), $tabs);
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
}
