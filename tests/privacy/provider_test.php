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

namespace local_aicharts\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Tests for the privacy provider.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_aicharts\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    #[\Override]
    protected function setUp(): void {
        global $DB;

        parent::setUp();
        $this->resetAfterTest();
        $DB->delete_records('local_aicharts_chart');
    }

    /**
     * The metadata names the three tables, the two subsystems and the preference.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new \core_privacy\local\metadata\collection('local_aicharts'));

        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
        }

        $this->assertEqualsCanonicalizing([
            'local_aicharts_chart',
            'local_aicharts_run',
            'local_aicharts_result',
            'core_files',
            'core_message',
            'local_aicharts_view',
        ], $names);
    }

    /**
     * A user with a chart, a run, a result or a recipient entry is in the system context.
     */
    public function test_get_contexts_for_userid(): void {
        $author = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $this->create_chart($author->id, [$recipient->id]);

        $this->assertEquals(
            [\context_system::instance()->id],
            provider::get_contexts_for_userid($author->id)->get_contextids()
        );
        $this->assertEquals(
            [\context_system::instance()->id],
            provider::get_contexts_for_userid($recipient->id)->get_contextids()
        );
        $this->assertEmpty(provider::get_contexts_for_userid($stranger->id)->get_contextids());
    }

    /**
     * Every author, modifier, runner and recipient is listed in the system context.
     */
    public function test_get_users_in_context(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $runner = $this->getDataGenerator()->create_user();
        $stranger = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart($author->id, [$recipient->id]);
        $DB->insert_record('local_aicharts_run', (object) [
            'chartid' => $chart->id,
            'userid' => $runner->id,
            'prompt' => 'users per country',
            'status' => 'ok',
            'timecreated' => time(),
        ]);

        $userlist = new userlist(\context_system::instance(), 'local_aicharts');
        provider::get_users_in_context($userlist);

        $this->assertEqualsCanonicalizing([$author->id, $recipient->id, $runner->id], $userlist->get_userids());
        $this->assertNotContains($stranger->id, $userlist->get_userids());
    }

    /**
     * The export holds the charts, the generation attempts and the stored runs of the user.
     */
    public function test_export_user_data(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart($author->id, []);
        $DB->insert_record('local_aicharts_run', (object) [
            'chartid' => $chart->id,
            'userid' => $author->id,
            'prompt' => 'enrolments per course',
            'status' => 'ok',
            'numrows' => 3,
            'timecreated' => time(),
        ]);
        $DB->insert_record('local_aicharts_result', (object) [
            'chartid' => $chart->id,
            'status' => 'ok',
            'numrows' => 3,
            'runtrigger' => 'manual',
            'userid' => $author->id,
            'timecreated' => time(),
        ]);

        $this->export_all_data_for_user($author->id, 'local_aicharts');

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());

        $root = get_string('pluginname', 'local_aicharts');
        $charts = $writer->get_data([$root, get_string('privacy:path:charts', 'local_aicharts')]);
        $this->assertCount(1, $charts->charts);
        $this->assertEquals('users per country', $charts->charts[0]->prompt);
        $this->assertEquals(get_string('yes'), $charts->charts[0]->creator);

        $runs = $writer->get_data([$root, get_string('privacy:path:runs', 'local_aicharts')]);
        $this->assertCount(1, $runs->runs);
        $this->assertEquals('enrolments per course', $runs->runs[0]->prompt);

        $results = $writer->get_data([$root, get_string('privacy:path:results', 'local_aicharts')]);
        $this->assertCount(1, $results->results);
        $this->assertEquals('manual', $results->results[0]->runtrigger);
    }

    /**
     * The dashboard view preference is exported.
     */
    public function test_export_user_preferences(): void {
        $user = $this->getDataGenerator()->create_user();
        set_user_preference('local_aicharts_view', 'list', $user->id);

        provider::export_user_preferences($user->id);

        $preferences = writer::with_context(\context_system::instance())->get_user_preferences('local_aicharts');
        $this->assertEquals('list', $preferences->local_aicharts_view->value);
    }

    /**
     * Deleting one user takes their id out of the recipient list and leaves the others.
     */
    public function test_delete_data_for_user_removes_recipient_id(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $gone = $this->getDataGenerator()->create_user();
        $kept = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart($author->id, [$gone->id, $kept->id]);
        $DB->insert_record('local_aicharts_run', (object) [
            'chartid' => $chart->id,
            'userid' => $gone->id,
            'prompt' => 'users per country',
            'status' => 'ok',
            'timecreated' => time(),
        ]);
        set_user_preference('local_aicharts_view', 'list', $gone->id);

        provider::delete_data_for_user(new approved_contextlist(
            $gone,
            'local_aicharts',
            [\context_system::instance()->id]
        ));

        $this->assertEquals((string) $kept->id, $DB->get_field('local_aicharts_chart', 'emailto', ['id' => $chart->id]));
        $this->assertFalse($DB->record_exists('local_aicharts_run', ['userid' => $gone->id]));
        $this->assertNull(get_user_preferences('local_aicharts_view', null, $gone->id));
        $this->assertEquals($author->id, $DB->get_field('local_aicharts_chart', 'userid', ['id' => $chart->id]));
    }

    /**
     * Deleting the author anonymises the chart instead of removing it.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart($author->id, []);
        $otherchart = $this->create_chart($other->id, []);

        provider::delete_data_for_users(new approved_userlist(
            \context_system::instance(),
            'local_aicharts',
            [$author->id]
        ));

        $this->assertTrue($DB->record_exists('local_aicharts_chart', ['id' => $chart->id]));
        $this->assertEquals(0, $DB->get_field('local_aicharts_chart', 'userid', ['id' => $chart->id]));
        $this->assertEquals(0, $DB->get_field('local_aicharts_chart', 'usermodified', ['id' => $chart->id]));
        $this->assertEquals($other->id, $DB->get_field('local_aicharts_chart', 'userid', ['id' => $otherchart->id]));
    }

    /**
     * Deleting the whole system context clears every user id and every generation attempt.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $author = $this->getDataGenerator()->create_user();
        $recipient = $this->getDataGenerator()->create_user();
        $chart = $this->create_chart($author->id, [$recipient->id]);
        $DB->insert_record('local_aicharts_run', (object) [
            'chartid' => $chart->id,
            'userid' => $author->id,
            'prompt' => 'users per country',
            'status' => 'ok',
            'timecreated' => time(),
        ]);

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        $this->assertEquals(0, $DB->count_records('local_aicharts_run'));
        $record = $DB->get_record('local_aicharts_chart', ['id' => $chart->id]);
        $this->assertEquals(0, $record->userid);
        $this->assertEquals('', $record->emailto);
    }

    /**
     * Insert one chart owned by the given user.
     *
     * @param int $userid The author.
     * @param int[] $recipients The users the result is emailed to.
     * @return \stdClass The stored record.
     */
    protected function create_chart(int $userid, array $recipients): \stdClass {
        global $DB;

        $now = time();
        $record = (object) [
            'name' => 'Users per country',
            'prompt' => 'users per country',
            'schemahint' => '',
            'charthint' => '',
            'sqlhint' => '',
            'sqltext' => 'SELECT country, COUNT(*) FROM {user} GROUP BY country',
            'params' => '{}',
            'chartjson' => '{"type":"bar"}',
            'emailto' => implode(',', $recipients),
            'userid' => $userid,
            'usermodified' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_aicharts_chart', $record);

        return $record;
    }
}
