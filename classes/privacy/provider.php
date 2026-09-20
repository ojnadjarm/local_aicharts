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
 * Privacy Subsystem implementation for local_aicharts.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aicharts\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_aicharts\local\chart_repository;

/**
 * Privacy Subsystem implementation for local_aicharts.
 *
 * All data is held in the system context. Charts are site content, so a deletion request
 * anonymises the chart rows instead of removing them, drops the generation log of the user
 * and takes the user id out of the recipient lists.
 *
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * Return the fields which contain personal data.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this plugin.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_aicharts_chart',
            [
                'userid' => 'privacy:metadata:local_aicharts_chart:userid',
                'usermodified' => 'privacy:metadata:local_aicharts_chart:usermodified',
                'prompt' => 'privacy:metadata:local_aicharts_chart:prompt',
                'emailto' => 'privacy:metadata:local_aicharts_chart:emailto',
                'timecreated' => 'privacy:metadata:local_aicharts_chart:timecreated',
                'timemodified' => 'privacy:metadata:local_aicharts_chart:timemodified',
            ],
            'privacy:metadata:local_aicharts_chart'
        );

        $collection->add_database_table(
            'local_aicharts_query',
            [
                'label' => 'privacy:metadata:local_aicharts_query:label',
                'hint' => 'privacy:metadata:local_aicharts_query:hint',
                'sqltext' => 'privacy:metadata:local_aicharts_query:sqltext',
            ],
            'privacy:metadata:local_aicharts_query'
        );

        $collection->add_database_table(
            'local_aicharts_point',
            [
                'serieslabel' => 'privacy:metadata:local_aicharts_point:serieslabel',
                'timepoint' => 'privacy:metadata:local_aicharts_point:timepoint',
                'value' => 'privacy:metadata:local_aicharts_point:value',
            ],
            'privacy:metadata:local_aicharts_point'
        );

        $collection->add_database_table(
            'local_aicharts_run',
            [
                'userid' => 'privacy:metadata:local_aicharts_run:userid',
                'prompt' => 'privacy:metadata:local_aicharts_run:prompt',
                'sqltext' => 'privacy:metadata:local_aicharts_run:sqltext',
                'status' => 'privacy:metadata:local_aicharts_run:status',
                'errormessage' => 'privacy:metadata:local_aicharts_run:errormessage',
                'timecreated' => 'privacy:metadata:local_aicharts_run:timecreated',
            ],
            'privacy:metadata:local_aicharts_run'
        );

        $collection->add_database_table(
            'local_aicharts_result',
            [
                'userid' => 'privacy:metadata:local_aicharts_result:userid',
                'runtrigger' => 'privacy:metadata:local_aicharts_result:runtrigger',
                'timecreated' => 'privacy:metadata:local_aicharts_result:timecreated',
            ],
            'privacy:metadata:local_aicharts_result'
        );

        $collection->add_subsystem_link('core_files', [], 'privacy:metadata:filearea:result');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        $collection->add_user_preference('local_aicharts_view', 'privacy:metadata:preference:view');

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contexts containing user information.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $hasdata = $DB->record_exists_select(
            'local_aicharts_chart',
            'userid = :userid OR usermodified = :usermodified',
            ['userid' => $userid, 'usermodified' => $userid]
        )
            || $DB->record_exists('local_aicharts_run', ['userid' => $userid])
            || $DB->record_exists('local_aicharts_result', ['userid' => $userid])
            || !empty(self::get_charts_for_recipient($userid));

        if ($hasdata) {
            $contextlist->add_system_context();
        }

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add the users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_aicharts_chart} WHERE userid <> 0', []);
        $userlist->add_from_sql('usermodified', 'SELECT usermodified FROM {local_aicharts_chart} WHERE usermodified <> 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_aicharts_run} WHERE userid <> 0', []);
        $userlist->add_from_sql('userid', 'SELECT userid FROM {local_aicharts_result} WHERE userid <> 0', []);

        $recipients = [];
        foreach ($DB->get_records('local_aicharts_chart', null, '', 'id, emailto') as $chart) {
            $recipients = array_merge($recipients, self::parse_recipients($chart->emailto));
        }
        if ($recipients) {
            $userlist->add_users(array_unique($recipients));
        }
    }

    /**
     * Export the user preferences of this plugin.
     *
     * @param int $userid The user to export for.
     */
    public static function export_user_preferences(int $userid) {
        $view = get_user_preferences('local_aicharts_view', null, $userid);
        if ($view !== null) {
            writer::export_user_preference(
                'local_aicharts',
                'local_aicharts_view',
                $view,
                get_string('privacy:metadata:preference:view', 'local_aicharts')
            );
        }
    }

    /**
     * Export all user data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $context = self::get_system_context($contextlist->get_contexts());
        if (!$context) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        $root = [get_string('pluginname', 'local_aicharts')];

        $charts = [];
        $records = $DB->get_records_select(
            'local_aicharts_chart',
            'userid = :userid OR usermodified = :usermodified',
            ['userid' => $userid, 'usermodified' => $userid],
            'id ASC'
        );
        foreach (self::get_charts_for_recipient($userid) as $record) {
            if (!isset($records[$record->id])) {
                $records[$record->id] = $record;
            }
        }
        chart_repository::attach_queries($records);
        foreach ($records as $record) {
            $charts[] = self::export_chart_fields($record, $userid);
        }
        if ($charts) {
            writer::with_context($context)->export_data(
                array_merge($root, [get_string('privacy:path:charts', 'local_aicharts')]),
                (object) ['charts' => $charts]
            );
        }

        $runs = [];
        foreach ($DB->get_records('local_aicharts_run', ['userid' => $userid], 'id ASC') as $record) {
            $runs[] = (object) [
                'chartid' => $record->chartid,
                'prompt' => $record->prompt,
                'sqltext' => $record->sqltext,
                'status' => $record->status,
                'errormessage' => $record->errormessage,
                'numrows' => $record->numrows,
                'timecreated' => transform::datetime($record->timecreated),
            ];
        }
        if ($runs) {
            writer::with_context($context)->export_data(
                array_merge($root, [get_string('privacy:path:runs', 'local_aicharts')]),
                (object) ['runs' => $runs]
            );
        }

        $results = [];
        foreach ($DB->get_records('local_aicharts_result', ['userid' => $userid], 'id ASC') as $record) {
            $results[] = (object) [
                'chartid' => $record->chartid,
                'status' => $record->status,
                'runtrigger' => $record->runtrigger,
                'numrows' => $record->numrows,
                'timecreated' => transform::datetime($record->timecreated),
            ];
        }
        if ($results) {
            writer::with_context($context)->export_data(
                array_merge($root, [get_string('privacy:path:results', 'local_aicharts')]),
                (object) ['results' => $results]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The context to delete in.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        $DB->delete_records('local_aicharts_run');
        $DB->set_field('local_aicharts_result', 'userid', 0);
        $DB->set_field('local_aicharts_chart', 'userid', 0);
        $DB->set_field('local_aicharts_chart', 'usermodified', 0);
        $DB->set_field('local_aicharts_chart', 'emailto', '');
    }

    /**
     * Delete all data for the user in the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to delete in.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (self::get_system_context($contextlist->get_contexts())) {
            self::delete_data_for_userid($contextlist->get_user()->id);
        }
    }

    /**
     * Delete all data for the approved users in the approved context.
     *
     * @param approved_userlist $userlist The approved context and users to delete for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if ($userlist->get_context()->contextlevel != CONTEXT_SYSTEM) {
            return;
        }

        foreach ($userlist->get_userids() as $userid) {
            self::delete_data_for_userid($userid);
        }
    }

    /**
     * Remove every trace of one user from the plugin tables and preferences.
     *
     * @param int $userid The user to remove.
     */
    protected static function delete_data_for_userid(int $userid) {
        global $DB;

        $DB->delete_records('local_aicharts_run', ['userid' => $userid]);
        $DB->set_field('local_aicharts_result', 'userid', 0, ['userid' => $userid]);
        $DB->set_field('local_aicharts_chart', 'userid', 0, ['userid' => $userid]);
        $DB->set_field('local_aicharts_chart', 'usermodified', 0, ['usermodified' => $userid]);

        foreach (self::get_charts_for_recipient($userid) as $chart) {
            $remaining = array_diff(self::parse_recipients($chart->emailto), [$userid]);
            $DB->set_field('local_aicharts_chart', 'emailto', implode(',', $remaining), ['id' => $chart->id]);
        }

        unset_user_preference('local_aicharts_view', $userid);
    }

    /**
     * The charts that email their result to the given user.
     *
     * @param int $userid The recipient to look for.
     * @return array Chart records keyed by id.
     */
    protected static function get_charts_for_recipient(int $userid): array {
        global $DB;

        $charts = [];
        foreach ($DB->get_records('local_aicharts_chart', null, 'id ASC') as $chart) {
            if (in_array($userid, self::parse_recipients($chart->emailto), true)) {
                $charts[$chart->id] = $chart;
            }
        }

        return $charts;
    }

    /**
     * Turn a comma separated recipient list into user ids.
     *
     * @param string $emailto The stored list.
     * @return int[] The user ids.
     */
    protected static function parse_recipients(string $emailto): array {
        $ids = [];
        foreach (explode(',', $emailto) as $id) {
            if (trim($id) !== '') {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * The system context out of an approved list, if it is there.
     *
     * @param \context[] $contexts The approved contexts.
     * @return \context|null The system context.
     */
    protected static function get_system_context(array $contexts): ?\context {
        foreach ($contexts as $context) {
            if ($context->contextlevel == CONTEXT_SYSTEM) {
                return $context;
            }
        }

        return null;
    }

    /**
     * The exportable fields of one chart record.
     *
     * @param \stdClass $record The chart record.
     * @param int $userid The user the export is for.
     * @return \stdClass The exported fields.
     */
    protected static function export_chart_fields(\stdClass $record, int $userid): \stdClass {
        return (object) [
            'name' => $record->name,
            'prompt' => $record->prompt,
            'queries' => array_map(
                fn(\stdClass $query) => (object) [
                    'label' => $query->label,
                    'hint' => $query->hint,
                    'sqltext' => $query->sqltext,
                ],
                $record->queries
            ),
            'points' => self::export_points($record->id),
            'creator' => transform::yesno($record->userid == $userid),
            'lastmodifiedby' => transform::yesno($record->usermodified == $userid),
            'recipient' => transform::yesno(in_array($userid, self::parse_recipients($record->emailto), true)),
            'timecreated' => transform::datetime($record->timecreated),
            'timemodified' => transform::datetime($record->timemodified),
        ];
    }

    /**
     * The stored points of a trend chart, oldest first.
     *
     * @param int $chartid The chart id.
     * @return \stdClass[] One entry per point.
     */
    protected static function export_points(int $chartid): array {
        global $DB;

        $points = [];
        foreach ($DB->get_records('local_aicharts_point', ['chartid' => $chartid], 'timepoint, id') as $point) {
            $points[] = (object) [
                'series' => $point->serieslabel,
                'time' => transform::datetime($point->timepoint),
                'value' => $point->value,
            ];
        }
        return $points;
    }
}
