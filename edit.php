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
 * The chart wizard: one page per step, state kept in the session under a token.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\notification;
use local_aicharts\form\step_chart;
use local_aicharts\form\step_kind;
use local_aicharts\form\step_queries;
use local_aicharts\form\step_review;
use local_aicharts\form\step_schedule;
use local_aicharts\local\chart_repository;
use local_aicharts\local\chart_saver;
use local_aicharts\local\wizard_state;
use local_aicharts\output\wizard_page;

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$token = optional_param('w', '', PARAM_ALPHANUM);
$step = optional_param('step', 1, PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
$duplicate = optional_param('duplicate', 0, PARAM_BOOL);
$action = optional_param('action', '', PARAM_ALPHA);
$index = optional_param('index', -1, PARAM_INT);

$urlparams = $token === '' ? [] : ['w' => $token, 'step' => $step];
admin_externalpage_setup('local_aicharts_edit', '', $urlparams, new moodle_url('/local/aicharts/edit.php', $urlparams));
$dashboard = new moodle_url('/local/aicharts/index.php');

if ($token === '') {
    $chart = $id ? chart_repository::get($id) : null;
    if ($id && !$chart) {
        throw new moodle_exception('chartnotfound', 'local_aicharts');
    }
    $token = wizard_state::start($chart, $duplicate);
    redirect(new moodle_url('/local/aicharts/edit.php', ['w' => $token, 'step' => 1]));
}

$state = wizard_state::open($token);
if (!$state) {
    redirect($dashboard, get_string('wizardexpired', 'local_aicharts'), null, notification::WARNING);
}

if ($action !== '') {
    require_sesskey();
    if ($action === 'discard') {
        $state->discard();
        redirect($dashboard);
    }
    if ($action === 'up' || $action === 'down') {
        $state->move($index, $action === 'up' ? -1 : 1);
    } else if ($action === 'remove') {
        $state->remove($index);
    }
    $state->data->columns = [];
    $state->save();
    redirect($state->url(2));
}

$total = count(wizard_state::STEPS);
if ($step < 1 || $step > $total || ($step > 1 && $state->data->kind === '')) {
    redirect($state->url(1));
}
if ($step > 2 && !$state->data->columns) {
    redirect($state->url(2));
}
if ($step > 3 && $state->data->chartjson === '') {
    redirect($state->url(3));
}

$attributes = ['id' => wizard_page::FORM_ID, 'class' => 'local-aicharts-step'];
$hasnext = true;
$hassave = false;
$asidehtml = '';
switch ($step) {
    case 1:
        $attributes['class'] .= ' local-aicharts-kind';
        $form = new step_kind($state->url(1)->out(false), ['state' => $state], 'post', '', $attributes);
        if ($data = $form->get_data()) {
            if ($state->data->kind !== '' && $state->data->kind !== $data->kind && $state->data->queries) {
                $state->reset_runs();
                $state->data->kindchanged = true;
            }
            $state->data->kind = $data->kind;
            $state->save();
            redirect($state->url(2));
        }
        break;
    case 2:
        $form = new step_queries($state->url(2)->out(false), ['state' => $state], 'post', '', $attributes);
        if ($form->get_data()) {
            $state->data->columns = $form->columns();
            $state->data->kindchanged = false;
            $state->save();
            redirect($state->url(3));
        }
        break;
    case 3:
        $form = new step_chart($state->url(3)->out(false), ['state' => $state], 'post', '', $attributes);
        if ($data = $form->get_data()) {
            $state->data->chartjson = $form->chartjson();
            $state->data->charthint = trim($data->charthint);
            $state->save();
            redirect($state->url(4));
        }
        if ($form->prepare_chart()) {
            redirect($state->url(3));
        }
        $asidehtml = $form->preview_html();
        break;
    case 4:
        $form = new step_schedule($state->url(4)->out(false), ['state' => $state], 'post', '', $attributes);
        if ($data = $form->get_data()) {
            $form->apply($data, $state);
            $state->save();
            redirect($state->url(5));
        }
        break;
    default:
        $form = new step_review($state->url(5)->out(false), ['state' => $state], 'post', '', $attributes);
        if ($data = $form->get_data()) {
            $state->data->name = $data->name;
            $savedid = chart_saver::save($state);
            $state->discard();
            redirect(
                new moodle_url('/local/aicharts/index.php', ['saved' => $savedid]),
                get_string('chartsaved', 'local_aicharts'),
                null,
                notification::SUCCESS
            );
        }
        $form->prepare_review();
        $hasnext = false;
        $hassave = $form->can_save();
}

$page = new wizard_page($state, $step, $form->render(), $hasnext, $asidehtml, $hassave);

$PAGE->set_title($page->title());
$PAGE->navbar->ignore_active();
$PAGE->navbar->add(get_string('administrationsite'), new moodle_url('/admin/search.php'));
$PAGE->navbar->add(get_string('reports'), new moodle_url('/admin/category.php', ['category' => 'reports']));
$PAGE->navbar->add(get_string('dashboard', 'local_aicharts'), $dashboard);
$PAGE->navbar->add($page->title());

echo $OUTPUT->header();
echo $OUTPUT->render($page);
echo $OUTPUT->footer();
