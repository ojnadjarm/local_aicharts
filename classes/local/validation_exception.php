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

use moodle_exception;

/**
 * Thrown when a generated query breaks a validation rule.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validation_exception extends moodle_exception {
    /**
     * Constructor.
     *
     * @param string $errorcode Language string naming the broken rule.
     * @param mixed $a Value inserted in the message, usually the offending table, keyword or literal.
     */
    public function __construct(string $errorcode, $a = null) {
        parent::__construct($errorcode, 'local_aicharts', '', $a);
    }
}
