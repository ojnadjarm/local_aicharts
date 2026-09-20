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
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_php_time_limit;
use local_aicharts\local\chart_repository;
use local_aicharts\task\run_chart;
use moodle_exception;

/**
 * Runs one chart at once and stores the result.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_chart_now extends external_api {
    /** @var int Seconds a run asked for from the browser may take. */
    protected const TIME_LIMIT = 120;

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
     * Runs the chart while the request is open, so the result is stored before the answer returns.
     *
     * @param int $chartid The chart to run.
     * @return array The stored run.
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
        if (!$chart->enabled) {
            notification::warning(get_string('runnowpaused', 'local_aicharts'));
            return ['runid' => 0, 'status' => 'paused', 'numrows' => 0];
        }

        core_php_time_limit::raise(self::TIME_LIMIT);
        $result = run_chart::run($chart, 'manual', (int) $USER->id);

        if ($result->status === 'ok') {
            notification::success(get_string('runnowdone', 'local_aicharts', (int) $result->numrows));
        } else {
            notification::error(get_string('runnowfailed', 'local_aicharts'));
        }

        return [
            'runid' => (int) $result->id,
            'status' => $result->status,
            'numrows' => (int) $result->numrows,
        ];
    }

    /**
     * Return description of the function.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'runid' => new external_value(PARAM_INT, 'The stored run, 0 when the chart is paused.'),
            'status' => new external_value(PARAM_ALPHAEXT, 'ok, db_error or paused.'),
            'numrows' => new external_value(PARAM_INT, 'How many rows the run returned.'),
        ]);
    }
}
