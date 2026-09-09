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

/**
 * Deletes generation log entries older than the configured retention.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cleanup_runs extends \core\task\scheduled_task {
    /**
     * Name shown to admins.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('cleanupruns', 'local_aicharts');
    }

    /**
     * Deletes run rows older than the retention setting; a retention of zero keeps everything.
     */
    public function execute(): void {
        global $DB;

        $days = (int) get_config('local_aicharts', 'logretentiondays');
        if ($days <= 0) {
            mtrace('Generation log retention is disabled, nothing deleted.');
            return;
        }

        $cutoff = time() - ($days * DAYSECS);
        $count = $DB->count_records_select('local_aicharts_run', 'timecreated < ?', [$cutoff]);
        if ($count) {
            $DB->delete_records_select('local_aicharts_run', 'timecreated < ?', [$cutoff]);
        }
        mtrace("Deleted {$count} generation log entries older than {$days} days.");
    }
}
