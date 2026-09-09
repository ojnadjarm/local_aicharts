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
 * The AI charts dashboard.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$view = optional_param('view', '', PARAM_ALPHA);
$skiplive = optional_param('skiplive', 0, PARAM_BOOL);

admin_externalpage_setup('local_aicharts_dashboard');

if ($view === 'grid' || $view === 'list') {
    set_user_preference('local_aicharts_view', $view);
    redirect(new moodle_url('/local/aicharts/index.php'));
}

echo $OUTPUT->header();
echo $OUTPUT->render(new \local_aicharts\output\dashboard(null, $skiplive));
echo $OUTPUT->footer();
