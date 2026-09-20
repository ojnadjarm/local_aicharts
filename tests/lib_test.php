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

namespace local_aicharts;

use local_aicharts\local\chart_repository;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/aicharts/lib.php');

/**
 * Tests for the plugin callbacks.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $DB->delete_records('local_aicharts_chart');
        set_config('allowedtables', "user\ncourse\n", 'local_aicharts');
        set_config('maxrowsmax', 5000, 'local_aicharts');
        set_config('querytimeout', 20, 'local_aicharts');
    }

    /**
     * Save a chart counting the users of the site.
     *
     * @return int The chart id.
     */
    private function create_chart(): int {
        return chart_repository::save((object) [
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
        ]);
    }

    /**
     * The fragment runs the chart and returns its rendered body.
     *
     * @covers ::local_aicharts_output_fragment_card
     */
    public function test_fragment_renders_card_body(): void {
        $this->setAdminUser();
        $this->getDataGenerator()->create_user();
        $id = $this->create_chart();

        $html = local_aicharts_output_fragment_card(['id' => $id]);

        $this->assertStringContainsString('chart-area', $html);
        $this->assertStringNotContainsString('data-action="loadchart"', $html);
    }

    /**
     * A user without the view capability gets nothing.
     *
     * @covers ::local_aicharts_output_fragment_card
     */
    public function test_fragment_requires_view(): void {
        $id = $this->create_chart();
        $this->setUser($this->getDataGenerator()->create_user());

        $this->expectException(\required_capability_exception::class);
        local_aicharts_output_fragment_card(['id' => $id]);
    }
}
