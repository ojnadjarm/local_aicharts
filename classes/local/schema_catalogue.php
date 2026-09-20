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
 * The table catalogue and the short relation hints sent with every request.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_catalogue {
    /** @var array Hand written hint per table: key columns and foreign keys. */
    protected const HINTS = [
        'user' => 'id, username, firstname, lastname, email, deleted, suspended, ' .
            'firstaccess, lastaccess, timecreated (unix seconds).',
        'course' => 'id, category (-> course_categories.id), shortname, fullname, visible, ' .
            'startdate, enddate, timecreated (unix seconds).',
        'course_categories' => 'id, name, parent (-> course_categories.id), path, depth, visible.',
        'enrol' => 'id, enrol (method name), courseid (-> course.id), status (0 = active).',
        'user_enrolments' => 'id, enrolid (-> enrol.id), userid (-> user.id), status (0 = active), ' .
            'timestart, timeend, timecreated.',
        'role' => 'id, shortname (student, editingteacher, manager), name, archetype.',
        'role_assignments' => 'id, roleid (-> role.id), contextid (-> context.id), userid (-> user.id), timemodified.',
        'context' => 'id, contextlevel (50 = course, 70 = activity, 30 = user), instanceid ' .
            '(course.id at level 50, course_modules.id at level 70), path, depth.',
        'course_modules' => 'id, course (-> course.id), module (-> modules.id), instance ' .
            '(id in the activity table), section, visible, completion.',
        'modules' => 'id, name (assign, quiz, forum, ...), visible.',
        'course_completions' => 'id, userid (-> user.id), course (-> course.id), timeenrolled, ' .
            'timestarted, timecompleted (null while not complete).',
        'course_modules_completion' => 'id, coursemoduleid (-> course_modules.id), userid (-> user.id), ' .
            'completionstate (0 none, 1 done, 2 passed, 3 failed), timemodified.',
        'user_lastaccess' => 'id, userid (-> user.id), courseid (-> course.id), timeaccess.',
        'logstore_standard_log' => 'id, eventname, component, action, target, userid (-> user.id), ' .
            'courseid (-> course.id), contextlevel, contextinstanceid, timecreated. Large table: always filter on ' .
            'timecreated.',
        'grade_items' => 'id, courseid (-> course.id), itemtype (course, mod, manual), itemmodule, ' .
            'iteminstance, itemname, grademax, grademin.',
        'grade_grades' => 'id, itemid (-> grade_items.id), userid (-> user.id), rawgrade, finalgrade, ' .
            'timemodified.',
        'cohort' => 'id, contextid (-> context.id), name, idnumber, visible.',
        'cohort_members' => 'id, cohortid (-> cohort.id), userid (-> user.id), timeadded.',
        'groups' => 'id, courseid (-> course.id), name, idnumber, timecreated.',
        'groups_members' => 'id, groupid (-> groups.id), userid (-> user.id), timeadded.',
        'assign' => 'id, course (-> course.id), name, duedate, allowsubmissionsfromdate, grade. ' .
            'Reached from course_modules.instance where modules.name = \'assign\'.',
        'assign_submission' => 'id, assignment (-> assign.id), userid (-> user.id), status ' .
            '(submitted, draft, new), attemptnumber, timecreated, timemodified.',
        'assign_grades' => 'id, assignment (-> assign.id), userid (-> user.id), grader (-> user.id), ' .
            'grade (-1 when ungraded), attemptnumber, timemodified.',
        'quiz' => 'id, course (-> course.id), name, timeopen, timeclose, grade, sumgrades. ' .
            'Reached from course_modules.instance where modules.name = \'quiz\'.',
        'quiz_attempts' => 'id, quiz (-> quiz.id), userid (-> user.id), attempt, state ' .
            '(inprogress, finished, abandoned), timestart, timefinish, sumgrades.',
        'forum' => 'id, course (-> course.id), name, type. ' .
            'Reached from course_modules.instance where modules.name = \'forum\'.',
        'forum_discussions' => 'id, course (-> course.id), forum (-> forum.id), name, userid (-> user.id), ' .
            'timemodified.',
        'forum_posts' => 'id, discussion (-> forum_discussions.id), parent (0 for the first post), ' .
            'userid (-> user.id), created, modified, subject.',
    ];

    /**
     * Returns the hint line of every catalogued table, keyed by table name.
     *
     * @return string[] Hint per table.
     */
    public static function hints(): array {
        return self::HINTS;
    }

    /**
     * Returns the catalogued tables and their hints as the block sent to the model.
     *
     * @return string One line per table.
     */
    public static function hint_block(): string {
        $lines = [];
        foreach (self::hints() as $table => $hint) {
            $lines[] = '- {' . $table . '}' . ($hint === '' ? '' : ': ' . $hint);
        }
        return implode("\n", $lines);
    }
}
