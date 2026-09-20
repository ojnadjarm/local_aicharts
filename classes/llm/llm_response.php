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

namespace local_aicharts\llm;

/**
 * Raw answer of a client, or the reason there is none.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class llm_response {
    /**
     * Constructor. Use one of the factory methods instead.
     *
     * @param string $content Answer text, empty on error.
     * @param string $errorcode Short code of the failure, empty when the call succeeded.
     * @param string $errormessage Sanitised detail of the failure.
     */
    protected function __construct(
        /** @var string Answer text, empty on error. */
        public readonly string $content,
        /** @var string Short code of the failure, empty when the call succeeded. */
        public readonly string $errorcode = '',
        /** @var string Sanitised detail of the failure. */
        public readonly string $errormessage = '',
    ) {
    }

    /**
     * Answer of a successful call.
     *
     * @param string $content Answer text.
     * @return self
     */
    public static function answer(string $content): self {
        return new self($content);
    }

    /**
     * Failed call.
     *
     * @param string $errorcode Short code of the failure, for example nokey or unreachable.
     * @param string $errormessage Sanitised detail, never a key or a raw provider body.
     * @return self
     */
    public static function error(string $errorcode, string $errormessage = ''): self {
        return new self('', $errorcode, $errormessage);
    }

    /**
     * Whether the call failed.
     *
     * @return bool
     */
    public function has_error(): bool {
        return $this->errorcode !== '';
    }
}
