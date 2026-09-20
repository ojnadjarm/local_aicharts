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

namespace local_aicharts\task;

use local_aicharts\local\chart_repository;
use local_aicharts\local\query_runner;
use local_aicharts\local\result_mailer;
use local_aicharts\local\result_store;
use stdClass;

/**
 * Runs the query of one chart and stores its result.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class run_chart extends \core\task\adhoc_task {
    /**
     * Builds the task for one chart. The trigger travels in the custom data so that a
     * scheduled run and a manual one are not treated as duplicates of each other.
     *
     * @param int $chartid Chart to run.
     * @param string $trigger scheduled, manual or save.
     * @param int $userid User who asked for the run, 0 for a scheduled one.
     * @return self
     */
    public static function instance(int $chartid, string $trigger, int $userid = 0): self {
        $task = new self();
        $task->set_custom_data(['chartid' => $chartid, 'trigger' => $trigger]);
        if ($userid > 0) {
            $task->set_userid($userid);
        }

        return $task;
    }

    /**
     * Name shown to admins.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('runchart', 'local_aicharts');
    }

    /**
     * A failing query is a stored result, not a reason to run the chart again.
     *
     * @return bool
     */
    public function retry_until_success(): bool {
        return false;
    }

    /**
     * Runs the chart named in the custom data, unless it is gone or paused.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $chartid = (int) $data->chartid;

        $chart = chart_repository::get($chartid);
        if (!$chart) {
            mtrace("Chart {$chartid} no longer exists, nothing to run.");
            return;
        }
        if (!$chart->enabled) {
            mtrace("Chart {$chartid} is paused, nothing to run.");
            return;
        }

        $result = self::run($chart, (string) $data->trigger, (int) $this->get_userid());
        mtrace("Chart {$chartid} ran with status {$result->status} in {$result->durationms} ms.");
    }

    /**
     * Runs the query of a chart, stores the outcome and updates the chart.
     *
     * @param stdClass $chart Chart record.
     * @param string $trigger scheduled, manual or save.
     * @param int $userid User the run is recorded for, 0 for a scheduled one.
     * @return stdClass The stored result record.
     */
    public static function run(stdClass $chart, string $trigger, int $userid = 0): stdClass {
        global $DB;

        $params = json_decode((string) $chart->params, true);
        $outcome = query_runner::run($chart->sqltext, is_array($params) ? $params : [], (int) $chart->maxrows);

        $result = result_store::store(
            $chart,
            $outcome->rows,
            $trigger,
            $userid,
            $outcome->has_error() ? $outcome->errormessage : null,
            $outcome->durationms,
            $outcome->truncated
        );

        if ($trigger === 'scheduled') {
            result_mailer::send_for_result($chart, $result);
        }

        $DB->update_record('local_aicharts_chart', (object) [
            'id' => $chart->id,
            'lastrun' => $result->timecreated,
            'lastrowcount' => $result->numrows,
        ]);
        result_store::prune($chart->id);

        return $result;
    }
}
