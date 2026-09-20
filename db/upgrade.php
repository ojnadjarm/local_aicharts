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

/**
 * Upgrade steps for local_aicharts.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrades the plugin database from an older version.
 *
 * @param int $oldversion Installed version.
 * @return bool
 */
function xmldb_local_aicharts_upgrade(int $oldversion): bool {
    global $CFG, $DB;

    require_once($CFG->dirroot . '/calendar/lib.php');

    $dbman = $DB->get_manager();

    if ($oldversion < 2026090900) {
        $table = new xmldb_table('local_aicharts_chart');
        $field = new xmldb_field('runday', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'runhour');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $startwday = $CFG->calendar_startwday ?? CALENDAR_DEFAULT_STARTING_WEEKDAY;
        $DB->set_field('local_aicharts_chart', 'runday', $startwday, ['runmode' => 'weekly']);

        upgrade_plugin_savepoint(true, 2026090900, 'local', 'aicharts');
    }

    if ($oldversion < 2026090901) {
        $table = new xmldb_table('local_aicharts_query');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('chartid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('label', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('sqltext', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('params', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('chartid', XMLDB_KEY_FOREIGN, ['chartid'], 'local_aicharts_chart', ['id']);
        $table->add_index('chartid-sortorder', XMLDB_INDEX_NOTUNIQUE, ['chartid', 'sortorder']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $charts = $DB->get_recordset('local_aicharts_chart', null, 'id', 'id, name, sqltext, params');
        foreach ($charts as $chart) {
            if ($DB->record_exists('local_aicharts_query', ['chartid' => $chart->id])) {
                continue;
            }
            $DB->insert_record('local_aicharts_query', (object) [
                'chartid' => $chart->id,
                'label' => $chart->name,
                'sqltext' => $chart->sqltext,
                'params' => $chart->params !== '' && $chart->params !== null ? $chart->params : '{}',
                'sortorder' => 0,
            ]);
        }
        $charts->close();

        upgrade_plugin_savepoint(true, 2026090901, 'local', 'aicharts');
    }

    if ($oldversion < 2026090902) {
        $table = new xmldb_table('local_aicharts_chart');
        foreach (['sqltext', 'params'] as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026090902, 'local', 'aicharts');
    }

    if ($oldversion < 2026091400) {
        $table = new xmldb_table('local_aicharts_chart');
        $field = new xmldb_field('kind', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'oneshot', 'runday');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $field = new xmldb_field('pointsretention', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'kind');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_aicharts_query');
        $field = new xmldb_field('hint', XMLDB_TYPE_TEXT, null, null, null, null, null, 'label');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        $table = new xmldb_table('local_aicharts_point');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('chartid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('resultid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('serieslabel', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timepoint', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('value', XMLDB_TYPE_NUMBER, '20, 5', null, null, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('chartid', XMLDB_KEY_FOREIGN, ['chartid'], 'local_aicharts_chart', ['id']);
        $table->add_index('chartid-timepoint', XMLDB_INDEX_NOTUNIQUE, ['chartid', 'timepoint']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026091400, 'local', 'aicharts');
    }

    if ($oldversion < 2026091500) {
        $table = new xmldb_table('local_aicharts_chart');
        foreach (['schemahint', 'charthint', 'sqlhint'] as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026091500, 'local', 'aicharts');
    }

    if ($oldversion < 2026092000) {
        unset_config('allowedtables', 'local_aicharts');

        upgrade_plugin_savepoint(true, 2026092000, 'local', 'aicharts');
    }

    return true;
}
