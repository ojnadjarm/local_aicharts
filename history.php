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
 * The stored runs of one chart.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$id = required_param('id', PARAM_INT);
$resultid = optional_param('resultid', 0, PARAM_INT);

$urlparams = ['id' => $id];
if ($resultid) {
    $urlparams['resultid'] = $resultid;
}

admin_externalpage_setup(
    'local_aicharts_history',
    '',
    $urlparams,
    new moodle_url('/local/aicharts/history.php', $urlparams)
);

$chart = \local_aicharts\local\chart_repository::get($id);
if (!$chart) {
    throw new moodle_exception('chartnotfound', 'local_aicharts');
}

$selected = null;
if ($resultid) {
    $selected = \local_aicharts\local\result_store::get($resultid);
    if (!$selected || (int) $selected->chartid !== (int) $chart->id) {
        throw new moodle_exception('resultnotfound', 'local_aicharts');
    }
}

$PAGE->set_title(format_string($chart->name));
$PAGE->navbar->add(format_string($chart->name));

echo $OUTPUT->header();
echo $OUTPUT->render(new \local_aicharts\output\history($chart, $selected));
echo $OUTPUT->footer();
