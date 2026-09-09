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
 * Admin settings for local_aicharts.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_aicharts', get_string('settings', 'local_aicharts'));
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_aicharts/headingprovider',
        get_string('headingprovider', 'local_aicharts'),
        ''
    ));
    $settings->add(new admin_setting_configselect(
        'local_aicharts/clienttype',
        get_string('clienttype', 'local_aicharts'),
        get_string('clienttype_desc', 'local_aicharts'),
        'stub',
        [
            'stub' => get_string('clienttypestub', 'local_aicharts'),
            'openai' => get_string('clienttypeopenai', 'local_aicharts'),
        ]
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/baseurl',
        get_string('baseurl', 'local_aicharts'),
        get_string('baseurl_desc', 'local_aicharts'),
        'https://api.openai.com/v1',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_aicharts/apikey',
        get_string('apikey', 'local_aicharts'),
        get_string('apikey_desc', 'local_aicharts'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/model',
        get_string('model', 'local_aicharts'),
        get_string('model_desc', 'local_aicharts'),
        'gpt-4o-mini',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/timeout',
        get_string('timeout', 'local_aicharts'),
        get_string('timeout_desc', 'local_aicharts'),
        60,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/temperature',
        get_string('temperature', 'local_aicharts'),
        get_string('temperature_desc', 'local_aicharts'),
        0,
        PARAM_FLOAT
    ));

    $settings->add(new admin_setting_heading(
        'local_aicharts/headingsafety',
        get_string('headingsafety', 'local_aicharts'),
        ''
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_aicharts/allowedtables',
        get_string('allowedtables', 'local_aicharts'),
        get_string('allowedtables_desc', 'local_aicharts'),
        implode("\n", [
            'user',
            'course',
            'course_categories',
            'enrol',
            'user_enrolments',
            'role',
            'role_assignments',
            'context',
            'course_modules',
            'modules',
            'course_completions',
            'course_modules_completion',
            'user_lastaccess',
            'logstore_standard_log',
            'grade_items',
            'grade_grades',
            'cohort',
            'cohort_members',
        ]),
        PARAM_RAW,
        60,
        20
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/maxrowsdefault',
        get_string('maxrowsdefault', 'local_aicharts'),
        get_string('maxrowsdefault_desc', 'local_aicharts'),
        500,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/maxrowsmax',
        get_string('maxrowsmax', 'local_aicharts'),
        get_string('maxrowsmax_desc', 'local_aicharts'),
        5000,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/querytimeout',
        get_string('querytimeout', 'local_aicharts'),
        get_string('querytimeout_desc', 'local_aicharts'),
        20,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_aicharts/headingprompting',
        get_string('headingprompting', 'local_aicharts'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/examplelimit',
        get_string('examplelimit', 'local_aicharts'),
        get_string('examplelimit_desc', 'local_aicharts'),
        6,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtextarea(
        'local_aicharts/extrainstructions',
        get_string('extrainstructions', 'local_aicharts'),
        get_string('extrainstructions_desc', 'local_aicharts'),
        '',
        PARAM_RAW,
        60,
        8
    ));

    $settings->add(new admin_setting_heading(
        'local_aicharts/headinglogging',
        get_string('headinglogging', 'local_aicharts'),
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/logretentiondays',
        get_string('logretentiondays', 'local_aicharts'),
        get_string('logretentiondays_desc', 'local_aicharts'),
        90,
        PARAM_INT
    ));

    $settings->add(new admin_setting_heading(
        'local_aicharts/headingscheduled',
        get_string('headingscheduled', 'local_aicharts'),
        ''
    ));
    $hours = [];
    for ($hour = 0; $hour < 24; $hour++) {
        $hours[$hour] = sprintf('%02d:00', $hour);
    }
    $settings->add(new admin_setting_configselect(
        'local_aicharts/runhour',
        get_string('runhour', 'local_aicharts'),
        get_string('runhour_desc', 'local_aicharts'),
        4,
        $hours
    ));
    $settings->add(new admin_setting_configtext(
        'local_aicharts/resultretention',
        get_string('resultretention', 'local_aicharts'),
        get_string('resultretention_desc', 'local_aicharts'),
        30,
        PARAM_INT
    ));
}

$ADMIN->add('reports', new admin_externalpage(
    'local_aicharts_dashboard',
    get_string('dashboard', 'local_aicharts'),
    new moodle_url('/local/aicharts/index.php'),
    'local/aicharts:view'
));

$ADMIN->add('reports', new admin_externalpage(
    'local_aicharts_history',
    get_string('runhistory', 'local_aicharts'),
    new moodle_url('/local/aicharts/history.php'),
    ['local/aicharts:view', 'local/aicharts:receiveresults'],
    true
));
