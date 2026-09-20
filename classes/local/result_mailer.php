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
use core\message\message;
use core\output\renderer_base;
use core_user;
use local_aicharts\output\email_result;
use moodle_url;
use stdClass;

/**
 * Sends the result of a scheduled run to the recipients of a chart.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_mailer {
    /**
     * Users listed on the chart that may still receive results.
     *
     * @param stdClass $chart Chart record.
     * @return stdClass[] User records, keyed by user id.
     */
    public static function recipients(stdClass $chart): array {
        global $DB;

        $ids = array_filter(array_map('intval', explode(',', (string) $chart->emailto)));
        if (!$ids) {
            return [];
        }

        $context = context_system::instance();
        $recipients = [];
        foreach ($DB->get_records_list('user', 'id', $ids, 'id', '*') as $user) {
            if ($user->deleted || $user->suspended) {
                continue;
            }
            if (has_capability('local/aicharts:receiveresults', $context, $user)) {
                $recipients[$user->id] = $user;
            }
        }

        return $recipients;
    }

    /**
     * Whether the result of a run has to be sent at all.
     *
     * @param stdClass $chart Chart record.
     * @param stdClass $result Result record.
     * @return bool
     */
    public static function should_send(stdClass $chart, stdClass $result): bool {
        if ($chart->emailwhen === 'rows') {
            return $result->status === 'ok' && (int) $result->numrows > 0;
        }

        return true;
    }

    /**
     * Sends one message per recipient and marks the result as emailed.
     *
     * @param stdClass $chart Chart record.
     * @param stdClass $result Result record.
     * @return int How many recipients the result was sent to.
     */
    public static function send_for_result(stdClass $chart, stdClass $result): int {
        global $DB;

        if (!self::should_send($chart, $result)) {
            return 0;
        }

        $recipients = self::recipients($chart);
        if (!$recipients) {
            return 0;
        }

        $attachment = $result->status === 'ok' ? result_store::get_file($result) : null;
        $rows = $result->status === 'ok' ? result_store::load_rows($result) : [];
        $image = result_store::get_image($result);
        $body = new email_result(
            $chart,
            $result,
            $rows,
            $image ? $image->get_content() : null,
            (bool) $attachment
        );

        $output = self::renderer();
        $subject = self::subject($chart, $result);
        $html = $output->render_from_template('local_aicharts/email_result', $body->export_for_template($output));
        $text = $body->plain_text();
        $url = new moodle_url('/local/aicharts/view.php', ['id' => $chart->id, 'resultid' => $result->id]);

        $sent = 0;
        foreach ($recipients as $user) {
            $message = new message();
            $message->component = 'local_aicharts';
            $message->name = 'scheduledresult';
            $message->userfrom = core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $text;
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = $html;
            $message->smallmessage = $subject;
            $message->notification = 1;
            $message->contexturl = $url->out(false);
            $message->contexturlname = get_string('emailopenhistory', 'local_aicharts');
            if ($attachment) {
                $message->attachment = $attachment;
                $message->attachname = $attachment->get_filename();
            }

            if (message_send($message)) {
                $sent++;
            }
        }

        if ($sent) {
            $DB->set_field('local_aicharts_result', 'emailed', 1, ['id' => $result->id]);
            $result->emailed = 1;
        }

        return $sent;
    }

    /**
     * Subject line of the message.
     *
     * @param stdClass $chart Chart record.
     * @param stdClass $result Result record.
     * @return string
     */
    protected static function subject(stdClass $chart, stdClass $result): string {
        $name = format_string($chart->name);
        if ($result->status !== 'ok') {
            return get_string('emailsubjectfailed', 'local_aicharts', $name);
        }

        return get_string('emailsubject', 'local_aicharts', ['name' => $name, 'rows' => (int) $result->numrows]);
    }

    /**
     * Renderer used for the message body, usable outside a page.
     *
     * @return renderer_base
     */
    protected static function renderer(): renderer_base {
        global $PAGE;

        return $PAGE->get_renderer('core');
    }
}
