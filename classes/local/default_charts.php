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
 * The charts shipped with the plugin and seeded on install.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class default_charts {
    /** @var int Row limit used when the site setting is not available yet. */
    protected const MAXROWS_FALLBACK = 500;

    /** @var array Shipped definitions keyed by the idnumber that makes seeding idempotent. */
    public const CHARTS = [
        'enrolmentspercourse' => [
            'name' => 'Enrolments per course',
            'prompt' => 'How many users are enrolled in each course?',
            'sql' => "SELECT c.fullname AS coursename, COUNT(DISTINCT ue.userid) AS total
                        FROM {course} c
                   LEFT JOIN {enrol} e ON e.courseid = c.id
                   LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
                       WHERE c.id <> :siteid
                    GROUP BY c.fullname
                    ORDER BY c.fullname",
            'params' => ['siteid' => 1],
            'chart' => [
                'type' => 'bar',
                'title' => 'Enrolments per course',
                'labelcolumn' => 'coursename',
                'labelformat' => 'text',
                'series' => [['column' => 'total', 'label' => 'Users']],
                'xlabel' => 'Course',
                'ylabel' => 'Users',
            ],
        ],
        'usersperrole' => [
            'name' => 'Users per role',
            'prompt' => 'How many users hold each role?',
            'sql' => "SELECT r.shortname AS rolename, COUNT(DISTINCT ra.userid) AS total
                        FROM {role} r
                   LEFT JOIN {role_assignments} ra ON ra.roleid = r.id
                    GROUP BY r.shortname
                    ORDER BY r.shortname",
            'params' => [],
            'chart' => [
                'type' => 'pie',
                'title' => 'Users per role',
                'labelcolumn' => 'rolename',
                'labelformat' => 'text',
                'series' => [['column' => 'total', 'label' => 'Users']],
            ],
        ],
        'coursespercategory' => [
            'name' => 'Courses per category',
            'prompt' => 'How many courses are there in each category?',
            'sql' => "SELECT cc.name AS categoryname, COUNT(c.id) AS total
                        FROM {course_categories} cc
                   LEFT JOIN {course} c ON c.category = cc.id
                    GROUP BY cc.name
                    ORDER BY cc.name",
            'params' => [],
            'chart' => [
                'type' => 'bar',
                'title' => 'Courses per category',
                'labelcolumn' => 'categoryname',
                'labelformat' => 'text',
                'series' => [['column' => 'total', 'label' => 'Courses']],
                'xlabel' => 'Category',
                'ylabel' => 'Courses',
                'horizontal' => true,
            ],
        ],
        'newusersperweek' => [
            'name' => 'New users per week',
            'prompt' => 'How many user accounts were created each week?',
            'sql' => "SELECT FLOOR(u.timecreated / 604800) * 604800 AS weekstart, COUNT(u.id) AS total
                        FROM {user} u
                       WHERE u.deleted = :deleted
                    GROUP BY FLOOR(u.timecreated / 604800) * 604800
                    ORDER BY weekstart",
            'params' => ['deleted' => 0],
            'chart' => [
                'type' => 'line',
                'title' => 'New users per week',
                'labelcolumn' => 'weekstart',
                'labelformat' => 'date',
                'series' => [['column' => 'total', 'label' => 'New users']],
                'xlabel' => 'Week',
                'ylabel' => 'New users',
            ],
        ],
        'completionspercourse' => [
            'name' => 'Course completions per course',
            'prompt' => 'How many users have completed each course?',
            'sql' => "SELECT c.fullname AS coursename, COUNT(cc.id) AS total
                        FROM {course} c
                   LEFT JOIN {course_completions} cc ON cc.course = c.id AND cc.timecompleted IS NOT NULL
                       WHERE c.id <> :siteid
                    GROUP BY c.fullname
                    ORDER BY c.fullname",
            'params' => ['siteid' => 1],
            'chart' => [
                'type' => 'bar',
                'title' => 'Course completions per course',
                'labelcolumn' => 'coursename',
                'labelformat' => 'text',
                'series' => [['column' => 'total', 'label' => 'Completions']],
                'xlabel' => 'Course',
                'ylabel' => 'Completions',
            ],
        ],
        'usersneverloggedin' => [
            'name' => 'Users who have never logged in',
            'prompt' => 'List the users who have never logged in.',
            'sql' => "SELECT u.lastname AS lastname, u.firstname AS firstname, u.email AS email
                        FROM {user} u
                       WHERE u.deleted = :deleted AND u.lastlogin = :never
                    ORDER BY u.lastname, u.firstname",
            'params' => ['deleted' => 0, 'never' => 0],
            'chart' => [
                'type' => 'table',
                'title' => 'Users who have never logged in',
                'labelcolumn' => '',
                'labelformat' => 'text',
                'series' => [],
            ],
        ],
        'activeuserstrend' => [
            'name' => 'Active users, trend',
            'prompt' => 'How many users have been active in the last 30 days?',
            'sql' => "SELECT COUNT(u.id) AS total
                        FROM {user} u
                       WHERE u.deleted = :deleted AND u.lastaccess > :since",
            'params' => ['deleted' => 0, 'since' => 0],
            'sincedays' => 30,
            'kind' => 'trend',
            'runmode' => 'daily',
            'chart' => [
                'type' => 'line',
                'title' => 'Active users, trend',
                'labelcolumn' => point_store::TIME_COLUMN,
                'labelformat' => 'text',
                'series' => [['column' => 'Active users, trend', 'label' => 'Active users']],
                'xlabel' => 'Run',
                'ylabel' => 'Users',
            ],
        ],
    ];

    /**
     * Saves the shipped charts that are not on the site yet.
     *
     * @return int How many charts were created.
     */
    public static function seed(): int {
        global $DB;

        $maxrows = (int) get_config('local_aicharts', 'maxrowsdefault');
        if ($maxrows <= 0) {
            $maxrows = self::MAXROWS_FALLBACK;
        }

        $created = 0;
        foreach (self::CHARTS as $idnumber => $definition) {
            if ($DB->record_exists('local_aicharts_chart', ['idnumber' => $idnumber])) {
                continue;
            }
            chart_repository::save((object) [
                'name' => $definition['name'],
                'idnumber' => $idnumber,
                'isdefault' => 1,
                'prompt' => $definition['prompt'],
                'queries' => [[
                    'label' => $definition['name'],
                    'sqltext' => $definition['sql'],
                    'params' => json_encode(self::params($definition)),
                ]],
                'chartjson' => json_encode($definition['chart']),
                'kind' => $definition['kind'] ?? 'oneshot',
                'runmode' => $definition['runmode'] ?? schedule::MODE_LIVE,
                'maxrows' => $maxrows,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * The query parameters of a definition, the since parameter set from the seed time.
     *
     * @param array $definition One entry of self::CHARTS.
     * @return array
     */
    public static function params(array $definition): array {
        $params = $definition['params'];
        if (isset($definition['sincedays'])) {
            $params['since'] = time() - $definition['sincedays'] * DAYSECS;
        }
        return $params;
    }
}
