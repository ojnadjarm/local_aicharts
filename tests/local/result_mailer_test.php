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

use context_system;
use local_aicharts\task\run_chart;
use stdClass;

/**
 * Tests for the scheduled result mailer.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_mailer_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('maxrowsmax', 500, 'local_aicharts');
        set_config('resultretention', 30, 'local_aicharts');
    }

    /**
     * Creates a user holding the recipient capability in the system context.
     *
     * @return stdClass
     */
    protected function create_recipient(): stdClass {
        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/aicharts:receiveresults', CAP_ALLOW, $roleid, context_system::instance());
        role_assign($roleid, $user->id, context_system::instance());

        return $user;
    }

    /**
     * Saves a scheduled chart with the given recipients.
     *
     * @param array $recipients Users the result is emailed to.
     * @param string $emailwhen always or rows.
     * @param string $sql The query to store.
     * @return stdClass The saved chart.
     */
    protected function create_chart(
        array $recipients,
        string $emailwhen = 'always',
        string $sql = 'SELECT shortname, 1 AS total FROM {role}'
    ): stdClass {
        $ids = array_map(fn($user) => $user->id, $recipients);
        $id = chart_repository::save((object) [
            'name' => 'Roles',
            'prompt' => 'roles',
            'queries' => [[
                'label' => 'Roles',
                'sqltext' => $sql,
                'params' => '{}',
            ]],
            'chartjson' => '{"type":"bar","labels":"shortname","series":["total"]}',
            'runmode' => 'daily',
            'runhour' => 4,
            'maxrows' => 100,
            'emailto' => implode(',', $ids),
            'emailwhen' => $emailwhen,
        ]);

        return chart_repository::get($id);
    }

    /**
     * Only the listed users that still hold the capability are recipients.
     *
     * @covers \local_aicharts\local\result_mailer::recipients
     * @covers \local_aicharts\local\result_mailer::send_for_result
     */
    public function test_sends_to_capability_holders_only(): void {
        $allowed = $this->create_recipient();
        $other = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart([$allowed, $other]);
        $result = result_store::store($chart, [['shortname' => 'student', 'total' => 1]], 'scheduled', 0);

        $this->assertSame([(int) $allowed->id], array_map('intval', array_keys(result_mailer::recipients($chart))));

        $sink = $this->redirectMessages();
        $this->assertSame(1, result_mailer::send_for_result($chart, $result));
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertSame((string) $allowed->id, (string) $messages[0]->useridto);
        $this->assertStringContainsString('Roles', $messages[0]->subject);
        $this->assertSame('1', result_store::get($result->id)->emailed);
    }

    /**
     * In rows mode an empty result is not sent.
     *
     * @covers \local_aicharts\local\result_mailer::should_send
     */
    public function test_rows_mode_skips_empty_result(): void {
        $recipient = $this->create_recipient();
        $chart = $this->create_chart([$recipient], 'rows');
        $result = result_store::store($chart, [], 'scheduled', 0);

        $sink = $this->redirectMessages();
        $this->assertSame(0, result_mailer::send_for_result($chart, $result));
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame('0', result_store::get($result->id)->emailed);
    }

    /**
     * In always mode a failed run is reported to the recipients.
     *
     * @covers \local_aicharts\local\result_mailer::send_for_result
     * @covers \local_aicharts\local\result_mailer::subject
     */
    public function test_always_mode_sends_failure_notice(): void {
        $recipient = $this->create_recipient();
        $chart = $this->create_chart([$recipient]);
        $result = result_store::store($chart, [], 'scheduled', 0, 'The query failed.');

        $sink = $this->redirectMessages();
        $this->assertSame(1, result_mailer::send_for_result($chart, $result));
        $messages = $sink->get_messages();
        $this->assertStringContainsString(get_string('emailsubjectfailed', 'local_aicharts', 'Roles'), $messages[0]->subject);
        $this->assertStringContainsString('The query failed.', $messages[0]->fullmessage);
    }

    /**
     * The CSV of the run travels with the email when attachments are allowed.
     *
     * @covers \local_aicharts\local\result_mailer::send_for_result
     */
    public function test_attaches_csv(): void {
        global $CFG;

        $this->preventResetByRollback();
        $CFG->allowattachments = 1;
        $recipient = $this->create_recipient();
        $chart = $this->create_chart([$recipient]);
        $result = result_store::store($chart, [['shortname' => 'student', 'total' => 1]], 'scheduled', 0);
        $file = result_store::get_file($result);

        $sink = $this->redirectEmails();
        $this->assertSame(1, result_mailer::send_for_result($chart, $result));
        $emails = $sink->get_messages();

        $this->assertCount(1, $emails);
        $this->assertStringContainsString($file->get_filename(), $emails[0]->body);
    }

    /**
     * A run of a chart with recipients mails the result only when it is scheduled.
     *
     * @covers \local_aicharts\local\result_mailer::send_for_result
     * @covers \local_aicharts\task\run_chart::run
     */
    public function test_only_scheduled_trigger_sends(): void {
        $recipient = $this->create_recipient();
        $chart = $this->create_chart([$recipient]);

        $sink = $this->redirectMessages();
        run_chart::run($chart, 'manual', 0);
        $this->assertCount(0, $sink->get_messages());

        run_chart::run($chart, 'scheduled', 0);
        $this->assertCount(1, $sink->get_messages());
    }

    /**
     * The scheduled email of a two-query chart carries the merged CSV and the PNG.
     *
     * @covers \local_aicharts\local\result_mailer::send_for_result
     * @covers \local_aicharts\task\run_chart::run
     */
    public function test_two_query_chart_email_has_csv_and_image(): void {
        global $CFG;

        $this->preventResetByRollback();
        $CFG->allowattachments = 1;
        $recipient = $this->create_recipient();
        $id = chart_repository::save((object) [
            'name' => 'Roles',
            'prompt' => 'roles',
            'queries' => [
                ['label' => 'One', 'sqltext' => 'SELECT shortname, 1 AS total FROM {role}', 'params' => '{}'],
                ['label' => 'Two', 'sqltext' => 'SELECT shortname, 2 AS total FROM {role}', 'params' => '{}'],
            ],
            'chartjson' => '{"type":"bar","labelcolumn":"shortname","series":[{"column":"One"},{"column":"Two"}]}',
            'runmode' => 'daily',
            'runhour' => 4,
            'maxrows' => 100,
            'emailto' => (string) $recipient->id,
            'emailwhen' => 'always',
        ]);
        $chart = chart_repository::get($id);

        $sink = $this->redirectEmails();
        $result = run_chart::run($chart, 'scheduled');
        $emails = $sink->get_messages();

        $this->assertSame('ok', $result->status);
        $this->assertNotNull(result_store::get_image($result));
        $this->assertCount(1, $emails);
        $this->assertStringContainsString(result_store::get_file($result)->get_filename(), $emails[0]->body);
        $rows = result_store::load_rows($result);
        $this->assertSame(['shortname', 'One', 'Two'], array_keys(reset($rows)));
    }
}
