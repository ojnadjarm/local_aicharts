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

/**
 * Callbacks for local_aicharts.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_aicharts\local\result_store;

/**
 * Serves the stored result files.
 *
 * @param stdClass $course Course, unused.
 * @param stdClass $cm Course module, unused.
 * @param context $context Context of the file.
 * @param string $filearea File area.
 * @param array $args Item id followed by the file path and name.
 * @param bool $forcedownload Whether the browser is asked to download the file.
 * @param array $options Options passed on to the file serving.
 * @return bool False when the file cannot be served.
 */
function local_aicharts_pluginfile(
    $course,
    $cm,
    $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    if ($context->contextlevel !== CONTEXT_SYSTEM || $filearea !== result_store::FILEAREA) {
        return false;
    }

    require_login();
    if (!has_any_capability(['local/aicharts:view', 'local/aicharts:receiveresults'], $context)) {
        require_capability('local/aicharts:view', $context);
    }

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $file = get_file_storage()->get_file(
        $context->id,
        result_store::COMPONENT,
        $filearea,
        $itemid,
        $filepath,
        $filename
    );
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, true, $options);
}
