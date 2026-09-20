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
 * Tests for the generation log cleanup task.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cleanup_runs_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Adds a run row created the given number of days ago.
     *
     * @param int $daysago
     * @return int
     */
    private function create_run(int $daysago): int {
        global $DB;

        return $DB->insert_record('local_aicharts_run', (object) [
            'userid' => 0,
            'status' => 'ok',
            'timecreated' => time() - ($daysago * DAYSECS),
        ]);
    }

    /**
     * Only rows older than the retention are deleted.
     *
     * @covers \local_aicharts\task\cleanup_runs::execute
     */
    public function test_deletes_old_runs_only(): void {
        global $DB;

        set_config('logretentiondays', 90, 'local_aicharts');
        $old = $this->create_run(120);
        $recent = $this->create_run(10);

        ob_start();
        (new cleanup_runs())->execute();
        ob_end_clean();

        $this->assertFalse($DB->record_exists('local_aicharts_run', ['id' => $old]));
        $this->assertTrue($DB->record_exists('local_aicharts_run', ['id' => $recent]));
    }

    /**
     * A retention of zero keeps everything.
     *
     * @covers \local_aicharts\task\cleanup_runs::execute
     */
    public function test_zero_retention_deletes_nothing(): void {
        global $DB;

        set_config('logretentiondays', 0, 'local_aicharts');
        $this->create_run(400);

        ob_start();
        (new cleanup_runs())->execute();
        ob_end_clean();

        $this->assertSame(1, $DB->count_records('local_aicharts_run'));
    }

    /**
     * The task name comes from the plugin language file.
     *
     * @covers \local_aicharts\task\cleanup_runs::get_name
     */
    public function test_get_name(): void {
        $this->assertSame(get_string('cleanupruns', 'local_aicharts'), (new cleanup_runs())->get_name());
    }
}
