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
require_once($CFG->libdir . '/upgradelib.php');
require_once($CFG->dirroot . '/local/aicharts/db/upgrade.php');

/**
 * Tests for the plugin upgrade steps.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_test extends \advanced_testcase {
    /**
     * The run day column is added and weekly charts keep the site's first weekday.
     *
     * @covers ::xmldb_local_aicharts_upgrade
     */
    public function test_upgrade_adds_runday(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $CFG->calendar_startwday = 3;

        $weekly = $this->create_chart('weekly');
        $monthly = $this->create_chart('monthly');
        $this->restore_query_columns();

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_aicharts_chart');
        $field = new \xmldb_field('runday');
        $dbman->drop_field($table, $field);
        set_config('version', 2026090807, 'local_aicharts');

        $this->assertTrue(xmldb_local_aicharts_upgrade(2026090807));

        $this->assertTrue($dbman->field_exists($table, $field));
        $this->assertSame(3, (int) $DB->get_field('local_aicharts_chart', 'runday', ['id' => $weekly]));
        $this->assertSame(1, (int) $DB->get_field('local_aicharts_chart', 'runday', ['id' => $monthly]));
        $this->assertGreaterThanOrEqual(2026091400, (int) get_config('local_aicharts', 'version'));
    }

    /**
     * The query table is created and every chart gets one query row named after it.
     *
     * @covers ::xmldb_local_aicharts_upgrade
     */
    public function test_upgrade_migrates_queries(): void {
        global $DB;

        $this->resetAfterTest();

        $weekly = $this->create_chart('weekly');
        $daily = $this->create_chart('daily');
        $this->restore_query_columns();
        $DB->set_field('local_aicharts_chart', 'params', '', ['id' => $daily]);

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_aicharts_query');
        $dbman->drop_table($table);
        set_config('version', 2026090900, 'local_aicharts');

        $this->assertTrue(xmldb_local_aicharts_upgrade(2026090900));

        $this->assertTrue($dbman->table_exists($table));
        $this->assertSame($DB->count_records('local_aicharts_chart'), $DB->count_records('local_aicharts_query'));
        $query = $DB->get_record('local_aicharts_query', ['chartid' => $weekly]);
        $this->assertSame('Weekly', $query->label);
        $this->assertSame('SELECT id, fullname FROM {course}', $query->sqltext);
        $this->assertSame('{}', $query->params);
        $this->assertSame('0', $query->sortorder);
        $this->assertSame('{}', $DB->get_field('local_aicharts_query', 'params', ['chartid' => $daily]));
        $this->assertGreaterThanOrEqual(2026091400, (int) get_config('local_aicharts', 'version'));
    }

    /**
     * The query columns are dropped from the chart table once the query rows exist.
     *
     * @covers ::xmldb_local_aicharts_upgrade
     */
    public function test_upgrade_drops_query_columns(): void {
        global $DB;

        $this->resetAfterTest();

        $weekly = $this->create_chart('weekly');
        $this->restore_query_columns();
        set_config('version', 2026090901, 'local_aicharts');

        $this->assertTrue(xmldb_local_aicharts_upgrade(2026090901));

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_aicharts_chart');
        $this->assertFalse($dbman->field_exists($table, new \xmldb_field('sqltext')));
        $this->assertFalse($dbman->field_exists($table, new \xmldb_field('params')));
        $this->assertSame(1, $DB->count_records('local_aicharts_query', ['chartid' => $weekly]));
        $this->assertGreaterThanOrEqual(2026091400, (int) get_config('local_aicharts', 'version'));
    }

    /**
     * The kind, retention and hint columns and the point table are added; existing charts are one-shot.
     *
     * @covers ::xmldb_local_aicharts_upgrade
     */
    public function test_upgrade_adds_kind_and_points_table(): void {
        global $DB;

        $this->resetAfterTest();

        $daily = $this->create_chart('daily');

        $dbman = $DB->get_manager();
        $charttable = new \xmldb_table('local_aicharts_chart');
        $querytable = new \xmldb_table('local_aicharts_query');
        $pointtable = new \xmldb_table('local_aicharts_point');
        $dbman->drop_field($charttable, new \xmldb_field('kind'));
        $dbman->drop_field($charttable, new \xmldb_field('pointsretention'));
        $dbman->drop_field($querytable, new \xmldb_field('hint'));
        $dbman->drop_table($pointtable);
        set_config('version', 2026090902, 'local_aicharts');

        $this->assertTrue(xmldb_local_aicharts_upgrade(2026090902));

        $this->assertTrue($dbman->field_exists($charttable, new \xmldb_field('kind')));
        $this->assertTrue($dbman->field_exists($charttable, new \xmldb_field('pointsretention')));
        $this->assertTrue($dbman->field_exists($querytable, new \xmldb_field('hint')));
        $this->assertTrue($dbman->table_exists($pointtable));
        $chart = $DB->get_record('local_aicharts_chart', ['id' => $daily]);
        $this->assertSame('oneshot', $chart->kind);
        $this->assertSame('0', $chart->pointsretention);
        $this->assertNull($DB->get_field('local_aicharts_query', 'hint', ['chartid' => $daily]));
        $this->assertGreaterThanOrEqual(2026091400, (int) get_config('local_aicharts', 'version'));
    }

    /**
     * The chart level hint columns the modal form wrote are dropped.
     *
     * @covers ::xmldb_local_aicharts_upgrade
     */
    public function test_upgrade_drops_chart_hint_columns(): void {
        global $DB;

        $this->resetAfterTest();

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_aicharts_chart');
        foreach (['schemahint', 'charthint', 'sqlhint'] as $name) {
            $dbman->add_field($table, new \xmldb_field($name, XMLDB_TYPE_TEXT, null, null, null, null, null, 'prompt'));
        }
        set_config('version', 2026091400, 'local_aicharts');

        $this->assertTrue(xmldb_local_aicharts_upgrade(2026091400));

        foreach (['schemahint', 'charthint', 'sqlhint'] as $name) {
            $this->assertFalse($dbman->field_exists($table, new \xmldb_field($name)));
        }
        $this->assertGreaterThanOrEqual(2026091500, (int) get_config('local_aicharts', 'version'));
    }

    /**
     * The allowed tables setting is removed.
     *
     * @covers ::xmldb_local_aicharts_upgrade
     */
    public function test_upgrade_removes_allowed_tables_setting(): void {
        $this->resetAfterTest();

        set_config('allowedtables', "user\ncourse", 'local_aicharts');
        set_config('version', 2026091501, 'local_aicharts');

        $this->assertTrue(xmldb_local_aicharts_upgrade(2026091501));

        $this->assertFalse(get_config('local_aicharts', 'allowedtables'));
        $this->assertGreaterThanOrEqual(2026092000, (int) get_config('local_aicharts', 'version'));
    }

    /**
     * Puts the sqltext and params columns back on the chart table, filled from the query rows.
     */
    private function restore_query_columns(): void {
        global $DB;

        $dbman = $DB->get_manager();
        $table = new \xmldb_table('local_aicharts_chart');
        foreach (['sqltext', 'params'] as $name) {
            $dbman->add_field($table, new \xmldb_field($name, XMLDB_TYPE_TEXT, null, null, null, null, null));
        }
        foreach ($DB->get_records('local_aicharts_query') as $query) {
            $DB->set_field('local_aicharts_chart', 'sqltext', $query->sqltext, ['id' => $query->chartid]);
            $DB->set_field('local_aicharts_chart', 'params', $query->params, ['id' => $query->chartid]);
        }
    }

    /**
     * Saves a scheduled chart.
     *
     * @param string $runmode Run mode.
     * @return int Chart id.
     */
    private function create_chart(string $runmode): int {
        return chart_repository::save((object) [
            'name' => ucfirst($runmode),
            'prompt' => 'Courses',
            'queries' => [[
                'label' => ucfirst($runmode),
                'sqltext' => 'SELECT id, fullname FROM {course}',
                'params' => '{}',
            ]],
            'chartjson' => '{}',
            'runmode' => $runmode,
            'runhour' => 6,
        ]);
    }
}
