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

namespace local_aicharts\external;

use core\context\system;
use core\notification;
use core\task\manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_aicharts\local\chart_repository;
use local_aicharts\task\run_chart;
use moodle_exception;

/**
 * Queues a manual run of one scheduled chart.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_chart_now extends external_api {
    /**
     * Parameters of the function.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'chartid' => new external_value(PARAM_INT, 'The chart to run.'),
        ]);
    }

    /**
     * Queues the ad hoc task that runs the chart, unless one is already waiting for cron.
     *
     * @param int $chartid The chart to run.
     * @return array Whether a new task was queued.
     */
    public static function execute(int $chartid): array {
        global $USER;

        ['chartid' => $chartid] = self::validate_parameters(self::execute_parameters(), ['chartid' => $chartid]);

        $context = system::instance();
        self::validate_context($context);
        require_capability('local/aicharts:manage', $context);

        $chart = chart_repository::get($chartid);
        if (!$chart) {
            throw new moodle_exception('chartnotfound', 'local_aicharts');
        }
        if ($chart->runmode === 'live') {
            throw new moodle_exception('runnownotscheduled', 'local_aicharts');
        }

        $task = run_chart::instance((int) $chart->id, 'manual', (int) $USER->id);
        $queued = manager::queue_adhoc_task($task, true) !== false;

        notification::success(get_string($queued ? 'runnowqueued' : 'runnowalreadyqueued', 'local_aicharts'));

        return ['queued' => $queued];
    }

    /**
     * Return description of the function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'queued' => new external_value(PARAM_BOOL, 'Whether a new run was queued.'),
        ]);
    }
}
