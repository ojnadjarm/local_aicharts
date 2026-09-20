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

use moodle_url;
use stdClass;

/**
 * The chart being built in the wizard, kept in the session under a per-user token.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wizard_state {
    /** @var string[] Chart kinds, the default first. */
    public const KINDS = ['oneshot', 'trend'];

    /** @var string[] Step names in order. */
    public const STEPS = ['kind', 'data', 'chart', 'schedule', 'review'];

    /** @var int Rows of each Data step run kept for the Chart step preview. */
    public const PREVIEW_ROWS = 50;

    /** @var int Length of a token. */
    protected const TOKEN_LENGTH = 8;

    /** @var string Session property holding every open wizard. */
    protected const SESSION_KEY = 'local_aicharts_wizard';

    /** @var string The token of this wizard. */
    public readonly string $token;

    /** @var stdClass What the person has entered so far. */
    public stdClass $data;

    /**
     * Constructor. Use start() or open().
     *
     * @param string $token The token of the wizard.
     * @param stdClass $data The stored state.
     */
    protected function __construct(string $token, stdClass $data) {
        $this->token = $token;
        $this->data = $data;
    }

    /**
     * Starts a wizard, seeded from a saved chart or from the defaults.
     *
     * @param stdClass|null $chart The chart being edited or duplicated, null for a new one.
     * @param bool $duplicate Whether the chart is copied instead of edited.
     * @return string The token of the new wizard.
     */
    public static function start(?stdClass $chart, bool $duplicate = false): string {
        $data = (object) [
            'id' => $chart && !$duplicate ? (int) $chart->id : 0,
            'duplicate' => $duplicate,
            'kind' => '',
            'queries' => [],
            'chartjson' => $chart->chartjson ?? '',
            'charthint' => '',
            'name' => '',
            'notes' => '',
            'proposal' => null,
            'maxrows' => $chart->maxrows ?? (int) get_config('local_aicharts', 'maxrowsdefault'),
            'runmode' => $chart->runmode ?? schedule::MODE_LIVE,
            'runhour' => $chart->runhour ?? (int) get_config('local_aicharts', 'runhour'),
            'runday' => $chart->runday ?? 1,
            'pointsretention' => $chart->pointsretention ?? 0,
            'emailto' => $chart->emailto ?? '',
            'emailwhen' => $chart->emailwhen ?? 'always',
            'columns' => [],
            'kindchanged' => false,
            'lastrun' => null,
            'review' => null,
        ];
        if ($chart) {
            $data->kind = in_array($chart->kind ?? '', self::KINDS, true) ? $chart->kind : self::KINDS[0];
            $data->name = $duplicate ? get_string('duplicatename', 'local_aicharts', $chart->name) : $chart->name;
            foreach ($chart->queries ?? [] as $query) {
                $data->queries[] = self::query(
                    $query->label,
                    $query->hint ?? (count($data->queries) ? '' : $chart->prompt),
                    $query->sqltext,
                    $query->params
                );
            }
        }

        $token = random_string(self::TOKEN_LENGTH);
        (new self($token, $data))->save();
        return $token;
    }

    /**
     * Opens the wizard of a token.
     *
     * @param string $token The token from the URL.
     * @return self|null Null when the token is unknown to this session.
     */
    public static function open(string $token): ?self {
        global $SESSION;

        $key = self::SESSION_KEY;
        if ($token === '' || !isset($SESSION->{$key}[$token])) {
            return null;
        }
        return new self($token, $SESSION->{$key}[$token]);
    }

    /**
     * Writes the state back to the session.
     */
    public function save(): void {
        global $SESSION;

        $key = self::SESSION_KEY;
        $SESSION->{$key} ??= [];
        $SESSION->{$key}[$this->token] = $this->data;
    }

    /**
     * Drops the state from the session.
     */
    public function discard(): void {
        global $SESSION;

        $key = self::SESSION_KEY;
        unset($SESSION->{$key}[$this->token]);
    }

    /**
     * A series record for the state.
     *
     * @param string $label Series name.
     * @param string|null $hint What the query should return.
     * @param string $sqltext The query.
     * @param string $params JSON object of parameters.
     * @param stdClass|null $run Outcome of the last run of this exact query, null when not run.
     * @return stdClass
     */
    public static function query(string $label, ?string $hint, string $sqltext, string $params, ?stdClass $run = null): stdClass {
        return (object) [
            'label' => $label,
            'hint' => (string) $hint,
            'sqltext' => $sqltext,
            'params' => $params === '' ? '{}' : $params,
            'run' => $run,
        ];
    }

    /**
     * Forgets the run of every series, so each is run again under the current kind.
     */
    public function reset_runs(): void {
        foreach ($this->data->queries as $query) {
            $query->run = null;
        }
        $this->data->columns = [];
        $this->data->lastrun = null;
    }

    /**
     * Moves a series one place up or down.
     *
     * @param int $index Position of the series.
     * @param int $offset -1 for up, 1 for down.
     */
    public function move(int $index, int $offset): void {
        $queries = $this->data->queries;
        $target = $index + $offset;
        if (!isset($queries[$index]) || !isset($queries[$target])) {
            return;
        }
        [$queries[$index], $queries[$target]] = [$queries[$target], $queries[$index]];
        $this->data->queries = array_values($queries);
    }

    /**
     * Removes a series.
     *
     * @param int $index Position of the series.
     */
    public function remove(int $index): void {
        unset($this->data->queries[$index]);
        $this->data->queries = array_values($this->data->queries);
    }

    /**
     * The URL of a step of this wizard.
     *
     * @param int $step Step number, 1 to 5.
     * @return moodle_url
     */
    public function url(int $step): moodle_url {
        return new moodle_url('/local/aicharts/edit.php', ['w' => $this->token, 'step' => $step]);
    }

    /**
     * The URL of an action on the series list.
     *
     * @param string $action up, down, remove or discard.
     * @param int $index Position of the series the action applies to.
     * @return moodle_url
     */
    public function action_url(string $action, int $index = -1): moodle_url {
        $params = ['w' => $this->token, 'action' => $action, 'sesskey' => sesskey()];
        if ($index >= 0) {
            $params['index'] = $index;
        }
        return new moodle_url('/local/aicharts/edit.php', $params);
    }
}
