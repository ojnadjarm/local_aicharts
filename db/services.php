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
 * External functions of local_aicharts.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_aicharts_delete_chart' => [
        'classname' => 'local_aicharts\external\delete_chart',
        'description' => 'Deletes one chart with its stored results.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicharts:manage',
    ],
    'local_aicharts_run_chart_now' => [
        'classname' => 'local_aicharts\external\run_chart_now',
        'description' => 'Queues a manual run of one scheduled chart.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicharts:manage',
    ],
    'local_aicharts_set_chart_enabled' => [
        'classname' => 'local_aicharts\\external\\set_chart_enabled',
        'description' => 'Pauses or resumes one scheduled chart.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/aicharts:manage',
    ],
];
