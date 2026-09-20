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
use stdClass;

/**
 * Tests for the run history renderable.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class history_test extends \advanced_testcase {
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
            'sqltext' => 'SELECT id AS label, id AS total FROM {user} ORDER BY id',
            'params' => '{}',
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
     * Export the page as history.php does.
     *
     * @param stdClass $chart The chart record.
     * @param stdClass|null $selected The run asked for.
     * @return array The template context.
     */
    private function export(stdClass $chart, ?stdClass $selected = null): array {
        global $PAGE;

        return (new history($chart, $selected))->export_for_template($PAGE->get_renderer('core'));
    }

    /**
     * Every stored run is listed newest first with human trigger and status labels.
     *
     * @covers \local_aicharts\output\history::export_for_template
     */
    public function test_lists_results_newest_first_with_labels(): void {
        global $DB, $USER;

        $chart = $this->create_chart();
        $first = run_chart::run($chart, 'save');
        $manual = run_chart::run($chart, 'manual', (int) $USER->id);
        $DB->set_field('local_aicharts_result', 'timecreated', $first->timecreated - 100, ['id' => $first->id]);

        $context = $this->export($chart);

        $this->assertTrue($context['hasresults']);
        $this->assertSame('Scheduled weekly at 06:00', $context['schedulelabel']);
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
     * @covers \local_aicharts\output\history::export_for_template
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
        $this->assertArrayHasKey('olderrun', $context);
        $this->assertStringContainsString('ms', $context['runmeta']);
        $this->assertTrue($context['rows'][1]['current']);
    }

    /**
     * A user who only receives the results can open the page.
     *
     * @covers \local_aicharts\output\history::export_for_template
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

        $this->assertFalse($context['canmanage']);
        $this->assertTrue($context['hasselected']);
        $this->assertCount(1, $context['rows']);
    }

    /**
     * A chart that never ran shows the empty state and no run.
     *
     * @covers \local_aicharts\output\history::export_for_template
     */
    public function test_chart_without_results_shows_empty_state(): void {
        $context = $this->export($this->create_chart());

        $this->assertFalse($context['hasresults']);
        $this->assertArrayNotHasKey('hasselected', $context);
        $this->assertSame([], $context['rows']);
    }
}
