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

use core_text;
use stdClass;

/**
 * Reads and writes the saved chart definitions.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_repository {
    /** @var string Table holding the saved charts. */
    protected const TABLE = 'local_aicharts_chart';

    /** @var string Table holding the queries of each chart. */
    protected const QUERY_TABLE = 'local_aicharts_query';

    /** @var string Table holding the points of each trend chart. */
    protected const POINT_TABLE = 'local_aicharts_point';

    /** @var int Shortest word taken into account when ranking examples. */
    protected const EXAMPLE_WORD_LENGTH = 4;

    /** @var int Longest statement an example may carry. */
    protected const EXAMPLE_MAX_SQL = 2000;

    /** @var int Number of matches below which defaults pad the example set. */
    protected const EXAMPLE_MIN_MATCHES = 2;

    /** @var int Example count used when the site setting is missing. */
    protected const EXAMPLE_LIMIT_DEFAULT = 6;

    /**
     * Inserts a chart, or updates it when the record carries an id.
     *
     * The queries property, when set, replaces the query rows of the chart.
     *
     * @param stdClass $chart Chart record.
     * @return int The chart id.
     */
    public static function save(stdClass $chart): int {
        global $DB, $USER;

        $record = clone $chart;
        $record->timemodified = time();
        $record->usermodified = $USER->id;

        $queries = self::normalise_queries($record);
        unset($record->queries);

        if (empty($record->id)) {
            unset($record->id);
            $record->timecreated = $record->timemodified;
            $record->emailto ??= '';
            $record->userid ??= $USER->id;
            $id = (int) $DB->insert_record(self::TABLE, $record);
        } else {
            $DB->update_record(self::TABLE, $record);
            $id = (int) $record->id;
        }

        if ($queries !== null) {
            $DB->delete_records(self::QUERY_TABLE, ['chartid' => $id]);
            foreach ($queries as $sortorder => $query) {
                $query->chartid = $id;
                $query->sortorder = $sortorder;
                $DB->insert_record(self::QUERY_TABLE, $query);
            }
        }

        return $id;
    }

    /**
     * Builds the query rows a chart record describes.
     *
     * @param stdClass $chart Chart record.
     * @return stdClass[]|null Rows with label, hint, sqltext and params, or null when the record carries none.
     */
    protected static function normalise_queries(stdClass $chart): ?array {
        if (!isset($chart->queries)) {
            return null;
        }
        $queries = [];
        foreach (array_values($chart->queries) as $query) {
            $query = (object) $query;
            $queries[] = (object) [
                'label' => $query->label,
                'hint' => $query->hint ?? null,
                'sqltext' => $query->sqltext,
                'params' => empty($query->params) ? '{}' : $query->params,
            ];
        }
        return $queries;
    }

    /**
     * Returns one chart.
     *
     * @param int $id Chart id.
     * @return stdClass|null The chart, or null when it does not exist.
     */
    public static function get(int $id): ?stdClass {
        global $DB;

        $chart = $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
        if ($chart) {
            self::attach_queries([$chart->id => $chart]);
        }
        return $chart;
    }

    /**
     * Sets the queries property of each chart, in sort order.
     *
     * @param stdClass[] $charts Charts keyed by id.
     */
    public static function attach_queries(array $charts): void {
        global $DB;

        foreach ($charts as $chart) {
            $chart->queries = [];
        }
        if (!$charts) {
            return;
        }
        $rows = $DB->get_records_list(self::QUERY_TABLE, 'chartid', array_keys($charts), 'chartid, sortorder, id');
        foreach ($rows as $row) {
            $charts[$row->chartid]->queries[] = $row;
        }
    }

    /**
     * Returns every chart, scheduled ones first, then by name.
     *
     * @return stdClass[] Charts keyed by id.
     */
    public static function list_all(): array {
        global $DB;

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
              ORDER BY CASE WHEN runmode = :live THEN 1 ELSE 0 END, name, id";
        $charts = $DB->get_records_sql($sql, ['live' => 'live']);
        self::attach_queries($charts);
        return $charts;
    }

    /**
     * Returns the enabled scheduled charts that are due to run.
     *
     * @param int $now Reference time.
     * @return stdClass[] Charts keyed by id.
     */
    public static function list_due(int $now): array {
        global $DB;

        $sql = "SELECT *
                  FROM {" . self::TABLE . "}
                 WHERE enabled = 1 AND runmode <> :live
              ORDER BY id";
        $due = [];
        foreach ($DB->get_records_sql($sql, ['live' => schedule::MODE_LIVE]) as $chart) {
            if (schedule::is_due($chart, $now)) {
                $due[$chart->id] = $chart;
            }
        }
        self::attach_queries($due);

        return $due;
    }

    /**
     * Marks a chart as handled for the current period.
     *
     * @param int $chartid Chart id.
     * @param int $now Time the run was queued.
     */
    public static function mark_queued(int $chartid, int $now): void {
        global $DB;

        $DB->set_field(self::TABLE, 'lastrun', $now, ['id' => $chartid]);
    }

    /**
     * Pauses or resumes a chart.
     *
     * @param int $id Chart id.
     * @param bool $enabled False to pause the chart.
     */
    public static function set_enabled(int $id, bool $enabled): void {
        global $DB;

        $DB->set_field(self::TABLE, 'enabled', (int) $enabled, ['id' => $id]);
    }

    /**
     * Deletes a chart with its points, its stored results and their files.
     *
     * @param int $id Chart id.
     */
    public static function delete(int $id): void {
        global $DB;

        result_store::delete_for_chart($id);
        $DB->delete_records(self::POINT_TABLE, ['chartid' => $id]);
        $DB->delete_records(self::QUERY_TABLE, ['chartid' => $id]);
        $DB->delete_records(self::TABLE, ['id' => $id]);
    }

    /**
     * Returns the saved charts that best match a prompt, to be sent as examples.
     *
     * Charts are ranked by the number of words they share with the prompt, ties going to
     * the most recently modified one. When fewer than two charts match, the newest default
     * charts pad the set.
     *
     * @param string $prompt The prompt the user typed.
     * @return stdClass[] Chart records, best match first.
     */
    public static function find_examples(string $prompt): array {
        global $DB;

        $limit = (int) get_config('local_aicharts', 'examplelimit');
        if ($limit <= 0) {
            $limit = self::EXAMPLE_LIMIT_DEFAULT;
        }

        $words = self::words($prompt);
        $candidates = [];
        $scores = [];
        $charts = $DB->get_records(self::TABLE, ['enabled' => 1], 'timemodified DESC, id DESC');
        self::attach_queries($charts);
        foreach ($charts as $chart) {
            if (!$chart->queries || core_text::strlen($chart->queries[0]->sqltext) > self::EXAMPLE_MAX_SQL) {
                continue;
            }
            $candidates[$chart->id] = $chart;
            $scores[$chart->id] = count(array_intersect($words, self::words($chart->name . ' ' . $chart->prompt)));
        }

        $matches = array_filter($candidates, fn(stdClass $chart) => $scores[$chart->id] > 0);
        uasort($matches, fn(stdClass $a, stdClass $b) => $scores[$b->id] <=> $scores[$a->id]);
        $examples = array_slice($matches, 0, $limit, true);

        if (count($examples) < self::EXAMPLE_MIN_MATCHES) {
            foreach ($candidates as $chart) {
                if (count($examples) >= $limit) {
                    break;
                }
                if ($chart->isdefault && !isset($examples[$chart->id])) {
                    $examples[$chart->id] = $chart;
                }
            }
        }

        return array_values($examples);
    }

    /**
     * Splits a text into the distinct lowercase words used for ranking.
     *
     * @param string $text Text to split.
     * @return string[] Distinct words of at least self::EXAMPLE_WORD_LENGTH characters.
     */
    protected static function words(string $text): array {
        $words = preg_split('/[^\p{L}\p{N}]+/u', core_text::strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $found = [];
        foreach ($words as $word) {
            if (core_text::strlen($word) >= self::EXAMPLE_WORD_LENGTH) {
                $found[$word] = true;
            }
        }
        return array_keys($found);
    }
}
