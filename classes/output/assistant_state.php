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

namespace local_aicharts\output;

use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use html_writer;
use local_aicharts\local\generation_result;
use moodle_url;

/**
 * A neutral box with an icon and a message: what the assistant answered, or why it did not.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assistant_state implements renderable, templatable {
    /**
     * Constructor.
     *
     * @param string $icon Font Awesome class.
     * @param string $body Message HTML.
     */
    public function __construct(
        /** @var string Font Awesome class. */
        protected string $icon,
        /** @var string Message HTML. */
        protected string $body,
    ) {
    }

    /**
     * The box of a failed generation: refusal, rejected query, missing key or provider error.
     *
     * @param generation_result $result A generation without rows.
     * @return self
     */
    public static function from_result(generation_result $result): self {
        if ($result->status === 'refused') {
            $body = get_string('refusal', 'local_aicharts')
                . html_writer::div(get_string('refusalexamples', 'local_aicharts'), 'mt-1')
                . html_writer::div(get_string('refusalnotrun', 'local_aicharts'), 'text-muted small mt-1');
            return new self('fa-ban', $body);
        }

        if ($result->status === 'validation_failed') {
            $message = $result->errormessage !== ''
                ? s($result->errormessage)
                : get_string('error_validation', 'local_aicharts');
            return new self('fa-triangle-exclamation', $message . html_writer::div(self::settings_link(), 'mt-1'));
        }

        if ($result->status === 'llm_error' && $result->errorcode === 'nokey') {
            $body = get_string('error_llm_nokey', 'local_aicharts') . html_writer::div(self::settings_link(), 'mt-1');
            return new self('fa-triangle-exclamation', $body);
        }

        $strings = [
            'invalid_json' => 'error_invalidjson',
            'db_error' => 'error_db',
            'llm_error' => match ($result->errorcode) {
                'badanswer' => 'error_llm_badanswer',
                'unreachable' => 'error_llm_unreachable',
                default => 'error_llm_noanswer',
            },
        ];
        $body = get_string($strings[$result->status] ?? 'error_llm_noanswer', 'local_aicharts');
        if ($result->errormessage !== '') {
            $body .= html_writer::tag(
                'details',
                html_writer::tag('summary', get_string('errordetails', 'local_aicharts')) . s($result->errormessage),
                ['class' => 'small mt-1']
            );
        }
        return new self('fa-triangle-exclamation', $body);
    }

    /**
     * A link to the plugin settings page.
     *
     * @return string
     */
    public static function settings_link(): string {
        $url = new moodle_url('/admin/settings.php', ['section' => 'local_aicharts']);
        $icon = html_writer::tag('i', '', ['class' => 'fa fa-gear me-1', 'aria-hidden' => 'true']);
        return html_writer::link($url, $icon . get_string('opensettings', 'local_aicharts'));
    }

    /**
     * The name of the configured provider.
     *
     * @return string
     */
    public static function provider_name(): string {
        $type = get_config('local_aicharts', 'clienttype') === 'openai' ? 'openai' : 'stub';
        return get_string('clienttype' . $type, 'local_aicharts');
    }

    #[\Override]
    public function export_for_template(renderer_base $output): array {
        return ['icon' => $this->icon, 'body' => $this->body];
    }
}
