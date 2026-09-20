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
use core\output\templatable;
use core\task\manager;
use core\url;
use core_text;
use local_aicharts\local\chart_factory;
use local_aicharts\local\chart_repository;
use local_aicharts\local\chart_spec;
use local_aicharts\local\point_store;
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
    /** @var int Live charts run when the page loads unless the site setting says otherwise. */
    public const LIVE_ON_LOAD = 6;

    /** @var int Seconds after which cron is reported as not running. */
    protected const CRON_STALE = 300;

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

    /** @var int The chart that was just saved, shown first so the person who saved it sees it. */
    protected int $saved;

    /**
     * Constructor.
     *
     * @param string|null $view grid or list; null takes the user preference.
     * @param bool $skiplive Whether to defer every live chart instead of running it.
     * @param int $saved The chart that was just saved, 0 for none.
     */
    public function __construct(?string $view = null, bool $skiplive = false, int $saved = 0) {
        $view = $view ?? get_user_preferences('local_aicharts_view', 'grid');
        $this->view = $view === 'list' ? 'list' : 'grid';
        $this->skiplive = $skiplive;
        $this->saved = $saved;
    }

    /**
     * Export the page for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        $charts = self::saved_first(chart_repository::list_all(), $this->saved);
        $canmanage = has_capability('local/aicharts:manage', system::instance());

        $queued = $charts ? $this->queued_chart_ids() : [];
        $cards = [];
        $rows = [];
        if ($this->view === 'list') {
            foreach ($charts as $chart) {
                $rows[] = $this->export_row($output, $chart, $canmanage, $queued);
            }
        } else {
            $points = point_store::timepoints_for(array_keys(array_filter($charts, [schedule::class, 'is_trend'])));
            $live = 0;
            $onload = self::live_on_load();
            foreach ($charts as $chart) {
                $deferred = $this->skiplive || $live >= $onload;
                if ($chart->enabled && $chart->runmode === 'live') {
                    $live++;
                }
                $cards[] = $this->export_card($output, $chart, $canmanage, $deferred, $queued, $points);
            }
        }

        return [
            'listview' => $this->view === 'list',
            'canmanage' => $canmanage,
            'hascharts' => (bool) $charts,
            'addurl' => (new url('/local/aicharts/edit.php'))->out(false),
            'gridurl' => (new url('/local/aicharts/index.php', ['view' => 'grid']))->out(false),
            'listurl' => (new url('/local/aicharts/index.php', ['view' => 'list']))->out(false),
            'cronurl' => (new url('/admin/tool/task/scheduledtasks.php'))->out(false),
            'cards' => $cards,
            'rows' => $rows,
        ] + self::export_cron_state($charts);
    }

    /**
     * How many live charts run while the page loads, 0 for none.
     *
     * The install and the upgrade both store the default, so false only happens on a site whose settings have
     * never been applied; it keeps that site on LIVE_ON_LOAD rather than deferring every card.
     *
     * @return int
     */
    protected static function live_on_load(): int {
        $setting = get_config('local_aicharts', 'liveonload');
        return $setting === false ? self::LIVE_ON_LOAD : max(0, (int) $setting);
    }

    /**
     * Move one chart to the front of the list, so a chart that was just saved is seen at once.
     *
     * @param stdClass[] $charts Charts keyed by id.
     * @param int $first The chart to show first, 0 for none.
     * @return stdClass[] Charts keyed by id.
     */
    protected static function saved_first(array $charts, int $first): array {
        if (!$first || !isset($charts[$first])) {
            return $charts;
        }

        return [$first => $charts[$first]] + $charts;
    }

    /**
     * Export one card, running the chart unless it is paused or deferred.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @param bool $canmanage Whether the user may act on the chart.
     * @param bool $deferred Whether a live chart is left to be loaded on request.
     * @param array $queued Ids of the charts waiting for their queued run.
     * @param int[] $points Timepoints of each trend chart, keyed by chart id.
     * @return array The card context.
     */
    protected function export_card(
        renderer_base $output,
        stdClass $chart,
        bool $canmanage,
        bool $deferred,
        array $queued,
        array $points
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
            'menu' => $this->menu($output, $chart, $canmanage),
            'viewurl' => self::view_url($chart)->out(false),
            'canmanage' => $canmanage,
            'live' => $live,
            'paused' => $paused,
            'deferred' => $deferred,
            'queued' => isset($queued[(int) $chart->id]),
            'refreshurl' => (new url('/local/aicharts/index.php'))->out(false),
            'maxrows' => (int) $chart->maxrows,
        ] + $this->export_recipients($chart) + self::export_trend($chart, $points[(int) $chart->id] ?? 0);

        if (!$live) {
            $card += $this->export_schedule($chart);
        } else if ($chart->lastrun) {
            $card['lastrunformatted'] = get_string('lastrunat', 'local_aicharts', userdate((int) $chart->lastrun));
        }

        if ($paused) {
            return $card;
        }

        return $card + ($live && !$deferred ? $this->run($output, $chart) : $this->stored($output, $chart));
    }

    /**
     * Export the schedule of a chart: its mode and hour.
     *
     * @param stdClass $chart The chart record.
     * @return array The scheduled part of the card context.
     */
    protected function export_schedule(stdClass $chart): array {
        $describe = schedule::describe($chart);
        $hour = sprintf('%02d:00', (int) $chart->runhour);

        return [
            'scheduled' => true,
            'runmodelabel' => get_string('scheduledmode', 'local_aicharts', $describe),
            'schedulelabel' => get_string('scheduledmodeat', 'local_aicharts', (object) [
                'schedule' => $describe,
                'hour' => $hour,
            ]),
            'runhourformatted' => $hour,
        ];
    }

    /**
     * Export the trend marker of a chart and how many points it holds.
     *
     * @param stdClass $chart The chart record.
     * @param int $points Timepoints the chart has stored.
     * @param int|null $retention Timepoints the chart keeps, null to leave it out of the line.
     * @return array The trend part of the card context.
     */
    public static function export_trend(stdClass $chart, int $points, ?int $retention = null): array {
        if (!schedule::is_trend($chart)) {
            return ['trend' => false];
        }

        $line = get_string('pointscount', 'local_aicharts', $points);
        if ($retention !== null) {
            $line .= ' · ' . get_string('pointskept', 'local_aicharts', $retention);
        }

        return [
            'trend' => true,
            'trendlabel' => get_string('trendmarker', 'local_aicharts'),
            'pointsline' => $line,
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
     * Export the stored result of a chart, or the reason it has none.
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
                return ['failed' => true, 'errormessage' => $latest->errormessage] + self::run_again();
            }

            if ($chart->runmode === 'live') {
                return ['notloaded' => true] + self::run_again('runnow');
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
        $body += self::run_again();
        $body['lastrunformatted'] = get_string('lastrunat', 'local_aicharts', userdate($ok->timecreated));
        if ($latest->id != $ok->id) {
            $body['lastrunfailed'] = true;
            $body['errormessage'] = $latest->errormessage;
        }

        return $body;
    }

    /**
     * The button that runs the chart and stores the result, named for what the chart already holds.
     *
     * @param string $key Lang string of the label.
     * @return array The run-again part of the card context.
     */
    public static function run_again(string $key = 'runagain'): array {
        return ['runagain' => true, 'runlabel' => get_string($key, 'local_aicharts')];
    }

    /**
     * The page of one chart.
     *
     * @param stdClass $chart The chart record.
     * @return url
     */
    public static function view_url(stdClass $chart): url {
        return new url('/local/aicharts/view.php', ['id' => (int) $chart->id]);
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
     * The render is not stored; the button lets the user ask for a run that is.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @return array The result part of the card context.
     */
    protected function run(renderer_base $output, stdClass $chart): array {
        $again = self::run_again($chart->lastrun ? 'runagain' : 'runnow');
        $result = query_runner::run_all($chart->queries ?? [], (int) $chart->maxrows);
        if ($result->has_error()) {
            return ['failed' => true, 'errormessage' => $result->errormessage] + $again;
        }

        return self::render_rows($output, $chart, $result->rows, $result->rowcount, $result->truncated) + $again;
    }

    /**
     * Render result rows as the chart or table the definition asks for.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $chart The chart record.
     * @param array $rows The result rows.
     * @param int $rowcount How many rows the run returned.
     * @param bool $truncated Whether the row limit cut the result.
     * @param bool $withtable Whether the chart carries its data table and a table shows every row.
     * @return array The result part of the card context.
     */
    public static function render_rows(
        renderer_base $output,
        stdClass $chart,
        array $rows,
        int $rowcount,
        bool $truncated,
        bool $withtable = false
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
                $viewurl = $withtable ? '' : self::view_url($chart)->out(false);
                $body = $output->render(new result_table($rows, 5, $viewurl));
            } else {
                $body = $output->render_chart(chart_factory::create($spec, $rows), $withtable);
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
        $mode = schedule::describe($chart);
        $mode = core_text::strtoupper(core_text::substr($mode, 0, 1)) . core_text::substr($mode, 1);

        return [
            'id' => (int) $chart->id,
            'name' => $chart->name,
            'dataname' => core_text::strtolower($chart->name),
            'icon' => self::icon($chart),
            'iconlabel' => self::icon_label($chart),
            'menu' => $this->menu($output, $chart, $canmanage),
            'viewurl' => self::view_url($chart)->out(false),
            'scheduled' => $scheduled,
            'trend' => schedule::is_trend($chart),
            'trendlabel' => get_string('trendmarker', 'local_aicharts'),
            'mode' => $chart->enabled
                ? ($scheduled ? $mode . ' · ' . sprintf('%02d:00', (int) $chart->runhour) : $mode)
                : get_string('paused', 'local_aicharts'),
            'lastrun' => $chart->lastrun ? userdate($chart->lastrun) : '—',
            'numrows' => isset($chart->lastrowcount) ? (string) $chart->lastrowcount : '—',
            'state' => $this->row_state($chart, isset($queued[(int) $chart->id])),
        ];
    }

    /**
     * The State column of a list row: queued, not run yet, last run failed, or a dash.
     *
     * @param stdClass $chart The chart record.
     * @param bool $queued Whether a run of this chart is waiting for cron.
     * @return string
     */
    protected function row_state(stdClass $chart, bool $queued): string {
        if ($queued) {
            return get_string('queued', 'local_aicharts');
        }
        if ($chart->runmode === 'live' || !$chart->enabled) {
            return '—';
        }

        $results = result_store::list_for_chart((int) $chart->id);
        if (!$results) {
            return get_string('notrunyet', 'local_aicharts');
        }
        if (reset($results)->status !== 'ok') {
            return get_string('lastrunfailed', 'local_aicharts');
        }

        return '—';
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
     * @return string The rendered menu, empty when it holds no action.
     */
    protected function menu(renderer_base $output, stdClass $chart, bool $canmanage): string {
        if (!$canmanage) {
            return '';
        }

        $menu = new action_menu();
        $menu->add(new link_secondary(
            new url('/local/aicharts/edit.php', ['id' => (int) $chart->id]),
            new pix_icon('t/edit', ''),
            get_string('edit')
        ));
        $menu->add(new link_secondary(
            new url('/local/aicharts/edit.php', ['id' => (int) $chart->id, 'duplicate' => 1]),
            new pix_icon('t/copy', ''),
            get_string('duplicate')
        ));
        $menu->add(new link_secondary(
            self::view_url($chart),
            new pix_icon('i/preview', ''),
            get_string('view', 'local_aicharts')
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
