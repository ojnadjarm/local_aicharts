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
use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use core\task\manager;
use core\url;
use core_text;
use local_aicharts\local\result_store;
use local_aicharts\local\schedule;
use stdClass;

/**
 * The stored runs of one chart: the selected run and the table of every kept run.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history implements renderable, templatable {
    /** @var string Ad hoc task that runs a scheduled chart. */
    protected const RUN_TASK = '\\local_aicharts\\task\\run_chart';

    /** @var int Stored runs kept per chart when the site setting is missing. */
    protected const RETENTION_DEFAULT = 30;

    /** @var stdClass The chart the runs belong to. */
    protected stdClass $chart;

    /** @var stdClass|null The run to render, the newest successful one when null. */
    protected ?stdClass $selected;

    /**
     * Constructor.
     *
     * @param stdClass $chart The chart record.
     * @param stdClass|null $selected The run asked for, the newest successful one when null.
     */
    public function __construct(stdClass $chart, ?stdClass $selected = null) {
        $this->chart = $chart;
        $this->selected = $selected;
    }

    /**
     * Export the page for the template.
     *
     * @param renderer_base $output The renderer.
     * @return array The template context.
     */
    public function export_for_template(renderer_base $output): array {
        $chart = $this->chart;
        $results = result_store::list_for_chart((int) $chart->id);
        $latest = $this->latest_ok($results);
        $selected = $this->selected ?? $latest;
        $scheduled = $chart->runmode !== 'live';
        $names = $this->recipient_names();

        $context = [
            'tabs' => dashboard::tabs($output, 'charts'),
            'id' => (int) $chart->id,
            'name' => $chart->name,
            'icon' => dashboard::icon($chart),
            'iconlabel' => dashboard::icon_label($chart),
            'canmanage' => has_capability('local/aicharts:manage', system::instance()),
            'scheduled' => $scheduled,
            'queued' => $this->is_queued(),
            'hasresults' => (bool) $results,
            'backurl' => (new url('/local/aicharts/index.php'))->out(false),
            'refreshurl' => $this->page_url()->out(false),
            'cronurl' => (new url('/admin/tool/task/scheduledtasks.php'))->out(false),
            'runskept' => get_string('runskept', 'local_aicharts', $this->retention()),
            'rows' => $this->export_rows($results, $selected, (bool) $names),
            'showemailed' => (bool) $names,
        ] + dashboard::export_cron_state([$chart]);

        if ($scheduled) {
            $mode = core_text::strtolower(schedule::label($chart->runmode));
            $context['schedulelabel'] = get_string('scheduledmodeat', 'local_aicharts', (object) [
                'mode' => $mode,
                'hour' => sprintf('%02d:00', (int) $chart->runhour),
            ]);
            $context['paused'] = !$chart->enabled;
        }

        if ($names) {
            $context['emailinfo'] = get_string('emailinfo', 'local_aicharts', (object) [
                'names' => implode(', ', $names),
                'when' => get_string('emailwhen_' . ($chart->emailwhen === 'rows' ? 'rows' : 'always'), 'local_aicharts'),
            ]);
        }

        if ($selected) {
            $context += $this->export_selected($output, $selected, $latest);
        }

        return $context;
    }

    /**
     * Export the card showing one run.
     *
     * @param renderer_base $output The renderer.
     * @param stdClass $selected The run to render.
     * @param stdClass|null $latest The newest successful run.
     * @return array The card part of the page context.
     */
    protected function export_selected(renderer_base $output, stdClass $selected, ?stdClass $latest): array {
        $card = [
            'hasselected' => true,
            'runof' => get_string('runof', 'local_aicharts', userdate($selected->timecreated)),
        ];

        if ($latest && $latest->id != $selected->id) {
            $card['olderrun'] = get_string('viewingolderrun', 'local_aicharts', userdate($latest->timecreated));
        }

        if ($selected->status !== 'ok') {
            return $card + ['failed' => true, 'errormessage' => $selected->errormessage];
        }

        $body = dashboard::render_rows(
            $output,
            $this->chart,
            result_store::load_rows($selected),
            (int) $selected->numrows,
            (bool) $selected->truncated
        );
        if (!empty($body['hasresult'])) {
            $body['runmeta'] = get_string('runmeta', 'local_aicharts', (object) [
                'rows' => $body['rowsline'],
                'ms' => (int) $selected->durationms,
                'trigger' => $this->trigger_label($selected),
            ]);
        }

        return $card + $body;
    }

    /**
     * Export one row of the runs table.
     *
     * @param stdClass[] $results Every stored run, newest first.
     * @param stdClass|null $selected The run the card shows.
     * @param bool $showemailed Whether the emailed column is rendered.
     * @return array The rows of the runs table.
     */
    protected function export_rows(array $results, ?stdClass $selected, bool $showemailed): array {
        $rows = [];
        foreach ($results as $result) {
            $ok = $result->status === 'ok';
            $url = result_store::download_url($result);
            $rows[] = [
                'date' => userdate($result->timecreated),
                'trigger' => $this->trigger_label($result),
                'status' => get_string($ok ? 'status_ok' : 'status_db_error', 'local_aicharts'),
                'failed' => !$ok,
                'errormessage' => $result->errormessage,
                'numrows' => $ok ? (string) (int) $result->numrows : '—',
                'durationms' => (string) (int) $result->durationms,
                'downloadurl' => $url ? $url->out(false) : '',
                'viewurl' => $ok ? $this->page_url((int) $result->id)->out(false) : '',
                'current' => $selected && $selected->id == $result->id,
                'showemailed' => $showemailed,
                'emailed' => (bool) $result->emailed,
            ];
        }

        return $rows;
    }

    /**
     * The human label of the trigger of a run.
     *
     * @param stdClass $result Result record.
     * @return string
     */
    protected function trigger_label(stdClass $result): string {
        global $DB;

        if ($result->runtrigger === 'manual') {
            $user = $DB->get_record('user', ['id' => (int) $result->userid]);
            return get_string('trigger_manual', 'local_aicharts', $user ? fullname($user) : '');
        }

        return get_string($result->runtrigger === 'save' ? 'trigger_save' : 'trigger_scheduled', 'local_aicharts');
    }

    /**
     * The names of the users the result is emailed to.
     *
     * @return string[]
     */
    protected function recipient_names(): array {
        global $DB;

        $ids = array_filter(array_map('intval', explode(',', (string) $this->chart->emailto)));
        if (!$ids) {
            return [];
        }

        $names = [];
        foreach ($DB->get_records_list('user', 'id', $ids) as $user) {
            $names[] = fullname($user);
        }

        return $names;
    }

    /**
     * The newest successful run of the chart.
     *
     * @param stdClass[] $results Every stored run, newest first.
     * @return stdClass|null
     */
    protected function latest_ok(array $results): ?stdClass {
        foreach ($results as $result) {
            if ($result->status === 'ok') {
                return $result;
            }
        }

        return null;
    }

    /**
     * Whether a run of this chart is waiting for cron.
     *
     * @return bool
     */
    protected function is_queued(): bool {
        foreach (manager::get_adhoc_tasks(self::RUN_TASK) as $task) {
            if ((int) ($task->get_custom_data()->chartid ?? 0) === (int) $this->chart->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many runs are kept for each chart.
     *
     * @return int
     */
    protected function retention(): int {
        $keep = (int) get_config('local_aicharts', 'resultretention');

        return $keep > 0 ? $keep : self::RETENTION_DEFAULT;
    }

    /**
     * The URL of this page, optionally showing one run.
     *
     * @param int $resultid Run to show, 0 for the newest successful one.
     * @return url
     */
    protected function page_url(int $resultid = 0): url {
        $params = ['id' => (int) $this->chart->id];
        if ($resultid) {
            $params['resultid'] = $resultid;
        }

        return new url('/local/aicharts/history.php', $params);
    }
}
