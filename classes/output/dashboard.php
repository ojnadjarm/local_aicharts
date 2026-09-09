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

use core\context\system;
use core\output\action_menu;
use core\output\action_menu\link_secondary;
use core\output\pix_icon;
use core\output\renderable;
use core\output\renderer_base;
use core\output\tabobject;
use core\output\templatable;
use core\task\manager;
use core\url;
use core_text;
use local_aicharts\local\chart_factory;
use local_aicharts\local\chart_repository;
use local_aicharts\local\chart_spec;
use local_aicharts\local\query_runner;
use local_aicharts\local\result_store;
use local_aicharts\local\schedule;
use moodle_exception;
use stdClass;

/**
 * The chart dashboard: one card per saved chart, or one row per chart in list view.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard implements renderable, templatable {
    /** @var int Live charts run when the page loads; the rest are loaded on request. */
    public const LIVE_ON_LOAD = 6;

    /** @var int Seconds after which cron is reported as not running. */
    protected const CRON_STALE = 300;

    /** @var int Items above which the name filter is shown. */
    protected const FILTER_FROM = 6;

    /** @var string Ad hoc task that runs a scheduled chart. */
    protected const RUN_TASK = '\\local_aicharts\\task\\run_chart';

    /** @var string[] Icon of each chart type. */
    protected const ICONS = [
        'bar' => 'fa-chart-column',
        'line' => 'fa-chart-line',
        'pie' => 'fa-chart-pie',
        'table' => 'fa-table',
    ];

    /** @var string Either grid or list. */
    protected string $view;

    /** @var bool Whether live charts are all left to be loaded on request. */
    protected bool $skiplive;

    /**
     * Constructor.
     *
     * @param string|null $view grid or list; null takes the user preference.
     * @param bool $skiplive Whether to defer every live chart instead of running it.
     */
    public function __construct(?string $view = null, bool $skiplive = false) {
        $view = $view ?? get_user_preferences('local_aicharts_view', 'grid');
        $this->view = $view === 'list' ? 'list' : 'grid';
        $this->skiplive = $skiplive;
    }

    /**
     * Export the page for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        $charts = chart_repository::list_all();
        $canmanage = has_capability('local/aicharts:manage', system::instance());

        $queued = $charts ? $this->queued_chart_ids() : [];
        $cards = [];
        $rows = [];
        if ($this->view === 'list') {
            foreach ($charts as $chart) {
                $rows[] = $this->export_row($output, $chart, $canmanage, $queued);
            }
        } else {
            $live = 0;
            foreach ($charts as $chart) {
                $deferred = $this->skiplive || $live >= self::LIVE_ON_LOAD;
                if ($chart->enabled && $chart->runmode === 'live') {
                    $live++;
                }
                $cards[] = $this->export_card($output, $chart, $canmanage, $deferred, $queued);
            }
        }

        return [
            'tabs' => self::tabs($output, 'charts'),
            'listview' => $this->view === 'list',
            'canmanage' => $canmanage,
            'hascharts' => (bool) $charts,
            'showfilter' => count($charts) > self::FILTER_FROM,
            'gridurl' => (new url('/local/aicharts/index.php', ['view' => 'grid']))->out(false),
            'listurl' => (new url('/local/aicharts/index.php', ['view' => 'list']))->out(false),
            'cronurl' => (new url('/admin/tool/task/scheduledtasks.php'))->out(false),
            'cards' => $cards,
            'rows' => $rows,
        ] + self::export_cron_state($charts);
    }

    /**
     * Export one card, running the chart unless it is paused or deferred.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @param bool $canmanage Whether the user may act on the chart.
     * @param bool $deferred Whether a live chart is left to be loaded on request.
     * @param array $queued Ids of the charts waiting for their queued run.
     * @return array The card context.
     */
    protected function export_card(
        renderer_base $output,
        stdClass $chart,
        bool $canmanage,
        bool $deferred,
        array $queued
    ): array {
        $live = $chart->runmode === 'live';
        $paused = !$chart->enabled;
        $deferred = $deferred && $live && !$paused;

        $card = [
            'id' => (int) $chart->id,
            'name' => $chart->name,
            'dataname' => core_text::strtolower($chart->name),
            'icon' => self::icon($chart),
            'iconlabel' => self::icon_label($chart),
            'menu' => $this->menu($output, $chart, $canmanage, isset($queued[(int) $chart->id])),
            'live' => $live,
            'paused' => $paused,
            'deferred' => $deferred,
            'maxrows' => (int) $chart->maxrows,
        ] + $this->export_recipients($chart);

        if (!$live) {
            $card += $this->export_schedule($chart, isset($queued[(int) $chart->id]));
        }

        if ($paused || $deferred) {
            return $card;
        }

        return $card + ($live ? $this->run($output, $chart) : $this->stored($output, $chart));
    }

    /**
     * Export the schedule of a chart: its mode, hour, history link and queued state.
     *
     * @param stdClass $chart The chart record.
     * @param bool $queued Whether a run of this chart is waiting for cron.
     * @return array The scheduled part of the card context.
     */
    protected function export_schedule(stdClass $chart, bool $queued): array {
        $mode = core_text::strtolower(schedule::label($chart->runmode));
        $hour = sprintf('%02d:00', (int) $chart->runhour);

        return [
            'scheduled' => true,
            'runmodelabel' => get_string('scheduledmode', 'local_aicharts', $mode),
            'schedulelabel' => get_string('scheduledmodeat', 'local_aicharts', (object) [
                'mode' => $mode,
                'hour' => $hour,
            ]),
            'runhourformatted' => $hour,
            'queued' => $queued,
            'historyurl' => (new url('/local/aicharts/history.php', ['id' => (int) $chart->id]))->out(false),
            'refreshurl' => (new url('/local/aicharts/index.php'))->out(false),
        ];
    }

    /**
     * Export the recipients of a chart, when it has any.
     *
     * @param stdClass $chart The chart record.
     * @return array The email part of the card context.
     */
    protected function export_recipients(stdClass $chart): array {
        global $DB;

        $ids = array_filter(array_map('intval', explode(',', $chart->emailto)));
        if (!$ids) {
            return ['emailed' => false];
        }

        $names = [];
        foreach ($DB->get_records_list('user', 'id', $ids) as $user) {
            $names[] = fullname($user);
        }

        return [
            'emailed' => (bool) $names,
            'emailrecipients' => get_string('emailedto', 'local_aicharts', implode(', ', $names)),
        ];
    }

    /**
     * Export the stored result of a scheduled chart, or the reason it has none.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @return array The result part of the card context.
     */
    protected function stored(renderer_base $output, stdClass $chart): array {
        $results = result_store::list_for_chart((int) $chart->id);
        $latest = $results ? reset($results) : null;

        $ok = null;
        foreach ($results as $result) {
            if ($result->status === 'ok') {
                $ok = $result;
                break;
            }
        }

        if (!$ok) {
            if ($latest) {
                return ['failed' => true, 'errormessage' => $latest->errormessage];
            }

            return [
                'notrunyet' => true,
                'nextrunformatted' => get_string(
                    'nextrun',
                    'local_aicharts',
                    userdate(schedule::next_run($chart, time()))
                ),
            ];
        }

        $body = self::render_rows(
            $output,
            $chart,
            result_store::load_rows($ok),
            (int) $ok->numrows,
            (bool) $ok->truncated
        );
        $body['lastrunformatted'] = get_string('lastrunat', 'local_aicharts', userdate($ok->timecreated));
        if ($latest->id != $ok->id) {
            $body['lastrunfailed'] = true;
            $body['errormessage'] = $latest->errormessage;
        }

        return $body;
    }

    /**
     * Export the card body of one chart, running it unless it is paused.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @return array The context of the result_card_body template.
     */
    public function export_card_body(renderer_base $output, stdClass $chart): array {
        $body = [
            'id' => (int) $chart->id,
            'paused' => !$chart->enabled,
            'deferred' => false,
        ];

        if (!$chart->enabled) {
            return $body;
        }

        if ($chart->runmode !== 'live') {
            $queued = $this->queued_chart_ids();
            return $body + $this->export_schedule($chart, isset($queued[(int) $chart->id])) + $this->stored($output, $chart);
        }

        return $body + $this->run($output, $chart);
    }

    /**
     * The ids of the charts whose run is waiting for cron.
     *
     * @return array Chart id as key, true as value.
     */
    protected function queued_chart_ids(): array {
        $ids = [];
        foreach (manager::get_adhoc_tasks(self::RUN_TASK) as $task) {
            $chartid = (int) ($task->get_custom_data()->chartid ?? 0);
            if ($chartid) {
                $ids[$chartid] = true;
            }
        }

        return $ids;
    }

    /**
     * Run a chart and export its rendered result, or the reason it has none.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @return array The result part of the card context.
     */
    protected function run(renderer_base $output, stdClass $chart): array {
        $params = json_decode($chart->params, true);
        $result = query_runner::run($chart->sqltext, is_array($params) ? $params : [], (int) $chart->maxrows);
        if ($result->has_error()) {
            return ['failed' => true, 'errormessage' => $result->errormessage];
        }

        return self::render_rows($output, $chart, $result->rows, $result->rowcount, $result->truncated);
    }

    /**
     * Render result rows as the chart or table the definition asks for.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @param array $rows The result rows.
     * @param int $rowcount How many rows the run returned.
     * @param bool $truncated Whether the row limit cut the result.
     * @return array The result part of the card context.
     */
    public static function render_rows(
        renderer_base $output,
        stdClass $chart,
        array $rows,
        int $rowcount,
        bool $truncated
    ): array {
        $key = $truncated ? 'rowcounttruncated' : 'rowcount';
        $rowsline = get_string($key, 'local_aicharts', (object) [
            'count' => $rowcount,
            'limit' => (int) $chart->maxrows,
        ]);
        if (!$rows) {
            return ['hasresult' => true, 'norows' => true, 'rowsline' => $rowsline];
        }

        try {
            $spec = chart_spec::from_json($chart->chartjson);
            if ($spec->is_table()) {
                $body = $output->render(new result_table($rows));
            } else {
                $body = $output->render(chart_factory::create($spec, $rows));
            }
        } catch (moodle_exception $e) {
            return ['failed' => true, 'errormessage' => $e->getMessage()];
        }

        return [
            'hasresult' => true,
            'body' => $body,
            'rowsline' => $rowsline,
        ];
    }

    /**
     * The Charts / Settings tab strip shared by the plugin pages.
     *
     * @param renderer_base $output The renderer.
     * @param string $active Key of the current tab.
     * @return string The rendered tabs.
     */
    public static function tabs(renderer_base $output, string $active): string {
        $tabs = [
            new tabobject('charts', new url('/local/aicharts/index.php'), get_string('charts', 'local_aicharts')),
        ];
        if (has_capability('moodle/site:config', system::instance())) {
            $tabs[] = new tabobject(
                'settings',
                new url('/admin/settings.php', ['section' => 'local_aicharts']),
                get_string('settings')
            );
        }

        return $output->tabtree($tabs, $active);
    }

    /**
     * Export one list-view row. Nothing is queried in list view.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @param bool $canmanage Whether the user may act on the chart.
     * @param array $queued Ids of the charts waiting for their queued run.
     * @return array The row context.
     */
    protected function export_row(renderer_base $output, stdClass $chart, bool $canmanage, array $queued): array {
        $scheduled = $chart->runmode !== 'live';
        $mode = get_string('runmode_' . $chart->runmode, 'local_aicharts');

        return [
            'id' => (int) $chart->id,
            'name' => $chart->name,
            'dataname' => core_text::strtolower($chart->name),
            'icon' => self::icon($chart),
            'iconlabel' => self::icon_label($chart),
            'menu' => $this->menu($output, $chart, $canmanage, isset($queued[(int) $chart->id])),
            'scheduled' => $scheduled,
            'historyurl' => $scheduled
                ? (new url('/local/aicharts/history.php', ['id' => (int) $chart->id]))->out(false)
                : '',
            'mode' => $chart->enabled
                ? ($scheduled ? $mode . ' · ' . sprintf('%02d:00', (int) $chart->runhour) : $mode)
                : get_string('paused', 'local_aicharts'),
            'lastrun' => $chart->lastrun ? userdate($chart->lastrun) : '—',
            'numrows' => isset($chart->lastrowcount) ? (string) $chart->lastrowcount : '—',
            'state' => isset($queued[(int) $chart->id]) ? get_string('queued', 'local_aicharts') : '—',
        ];
    }

    /**
     * Whether cron is late while items wait for it.
     *
     * @param stdClass[] $charts Every saved chart.
     * @return array The cron part of the page context.
     */
    public static function export_cron_state(array $charts): array {
        $scheduled = false;
        foreach ($charts as $chart) {
            $scheduled = $scheduled || $chart->runmode !== 'live';
        }

        $lastcron = (int) get_config('tool_task', 'lastcronstart');
        $since = time() - $lastcron;
        if (!$scheduled || $since < self::CRON_STALE) {
            return ['stalecron' => false];
        }

        return [
            'stalecron' => true,
            'stalecronmessage' => get_string('stalecron', 'local_aicharts', format_time($since)),
        ];
    }

    /**
     * The actions menu of one item.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @param bool $canmanage Whether the user may act on the chart.
     * @param bool $queued Whether a run of this chart is waiting for cron.
     * @return string The rendered menu, empty when it holds no action.
     */
    protected function menu(renderer_base $output, stdClass $chart, bool $canmanage, bool $queued): string {
        if (!$canmanage) {
            return '';
        }

        $menu = new action_menu();
        $menu->add(new link_secondary(
            new url('/local/aicharts/index.php'),
            new pix_icon('t/edit', ''),
            get_string('edit'),
            ['data-action' => 'editchart', 'data-id' => (int) $chart->id]
        ));
        $menu->add(new link_secondary(
            new url('/local/aicharts/index.php'),
            new pix_icon('t/copy', ''),
            get_string('duplicate'),
            ['data-action' => 'duplicatechart', 'data-id' => (int) $chart->id]
        ));
        if ($chart->runmode !== 'live') {
            $attributes = [
                'data-action' => 'runnow',
                'data-id' => (int) $chart->id,
            ];
            if ($queued) {
                $attributes['class'] = 'disabled';
                $attributes['aria-disabled'] = 'true';
            }
            $menu->add(new link_secondary(
                new url('/local/aicharts/index.php'),
                new pix_icon('t/play', ''),
                get_string('runnow', 'local_aicharts'),
                $attributes
            ));

            $menu->add(new link_secondary(
                new url('/local/aicharts/index.php'),
                new pix_icon($chart->enabled ? 't/stop' : 't/play', ''),
                get_string($chart->enabled ? 'pause' : 'resume', 'local_aicharts'),
                [
                    'data-action' => 'togglechart',
                    'data-id' => (int) $chart->id,
                    'data-enabled' => $chart->enabled ? 0 : 1,
                ]
            ));
        }

        $menu->add(new link_secondary(
            new url('/local/aicharts/index.php'),
            new pix_icon('t/delete', ''),
            get_string('delete'),
            [
                'data-action' => 'deletechart',
                'data-id' => (int) $chart->id,
                'data-name' => $chart->name,
            ]
        ));

        return $output->render($menu);
    }

    /**
     * The icon of a chart type.
     *
     * @param stdClass $chart The chart record.
     * @return string A Font Awesome class name.
     */
    public static function icon(stdClass $chart): string {
        return self::ICONS[self::type($chart)];
    }

    /**
     * The text read instead of the type icon.
     *
     * @param stdClass $chart The chart record.
     * @return string
     */
    public static function icon_label(stdClass $chart): string {
        return get_string('charttype' . self::type($chart), 'local_aicharts');
    }

    /**
     * The chart type, falling back to bar when the definition cannot be read.
     *
     * @param stdClass $chart The chart record.
     * @return string One of the keys of self::ICONS.
     */
    protected static function type(stdClass $chart): string {
        try {
            return chart_spec::from_json($chart->chartjson)->type;
        } catch (moodle_exception $e) {
            return 'bar';
        }
    }
}
