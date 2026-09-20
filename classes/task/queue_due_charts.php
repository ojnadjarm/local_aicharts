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

use core\task\manager;
use local_aicharts\local\chart_repository;

/**
 * Queues an ad hoc run for every scheduled chart that is due.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_due_charts extends \core\task\scheduled_task {
    /**
     * Name shown to admins.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('queueduecharts', 'local_aicharts');
    }

    /**
     * Queues the due charts. The queries themselves run in the ad hoc tasks.
     */
    public function execute(): void {
        $now = time();
        $queued = 0;

        foreach (chart_repository::list_due($now) as $chart) {
            $task = run_chart::instance($chart->id, 'scheduled');
            if (manager::queue_adhoc_task($task, true) === false) {
                mtrace("Chart {$chart->id} is already queued, skipped.");
            } else {
                $queued++;
            }
            chart_repository::mark_queued($chart->id, $now);
        }

        mtrace("Queued {$queued} chart runs.");
    }
}
