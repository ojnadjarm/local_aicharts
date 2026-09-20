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

use stdClass;

/**
 * Stores one value per series per run of a trend chart and reads them back as rows.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class point_store {
    /** @var string Table holding the points. */
    protected const TABLE = 'local_aicharts_point';

    /** @var string Column carrying the run time in the rows of a trend chart. */
    public const TIME_COLUMN = 'runtime';

    /** @var int Fewest timepoints a trend chart keeps. */
    public const RETENTION_FLOOR = 30;

    /** @var int Timepoints kept when the site setting is missing. */
    protected const RETENTION_DEFAULT = 365;

    /**
     * Adds one point per series for a run.
     *
     * @param stdClass $chart Chart record.
     * @param array $values Value of each series keyed by its label, null when the query returned no row.
     * @param int $time The run time.
     * @param int|null $resultid The stored run that produced the values.
     * @return void
     */
    public static function append(stdClass $chart, array $values, int $time, ?int $resultid): void {
        global $DB;

        $records = [];
        foreach ($values as $label => $value) {
            $records[] = (object) [
                'chartid' => $chart->id,
                'resultid' => $resultid,
                'serieslabel' => (string) $label,
                'timepoint' => $time,
                'value' => $value,
            ];
        }
        // Two runs within the same second share a timepoint, which holds one point per series.
        $DB->delete_records(self::TABLE, ['chartid' => $chart->id, 'timepoint' => $time]);
        $DB->insert_records(self::TABLE, $records);
    }

    /**
     * Sets the stored run of the points appended at a run time.
     *
     * @param int $chartid Chart id.
     * @param int $time The run time.
     * @param int $resultid The stored run.
     * @return void
     */
    public static function link_result(int $chartid, int $time, int $resultid): void {
        global $DB;

        $DB->set_field(self::TABLE, 'resultid', $resultid, ['chartid' => $chartid, 'timepoint' => $time]);
    }

    /**
     * Clears the stored run of the points it produced, once that run is gone.
     *
     * @param int $resultid The deleted run.
     * @return void
     */
    public static function unlink_result(int $resultid): void {
        global $DB;

        $DB->set_field(self::TABLE, 'resultid', null, ['resultid' => $resultid]);
    }

    /**
     * Returns the points of a chart as one row per run time, oldest first.
     *
     * Each row carries the run time under self::TIME_COLUMN and one column per series
     * label, null where that run has no point for the series.
     *
     * @param int $chartid Chart id.
     * @return array Associative rows.
     */
    public static function rows(int $chartid): array {
        global $DB;

        $labels = [];
        $values = [];
        foreach ($DB->get_records(self::TABLE, ['chartid' => $chartid], 'timepoint, id') as $point) {
            $labels[$point->serieslabel] = true;
            $values[$point->timepoint][$point->serieslabel] = $point->value === null ? null : (float) $point->value;
        }

        $rows = [];
        foreach ($values as $time => $byseries) {
            $row = [self::TIME_COLUMN => $time];
            foreach (array_keys($labels) as $label) {
                $row[$label] = $byseries[$label] ?? null;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Replaces the run time of each row with its human-readable form.
     *
     * @param array $rows Rows as returned by rows().
     * @return array
     */
    public static function format_rows(array $rows): array {
        foreach ($rows as &$row) {
            $row[self::TIME_COLUMN] = userdate((int) $row[self::TIME_COLUMN], get_string('strftimedatetimeshort'));
        }
        return $rows;
    }

    /**
     * How many run times a chart has points for.
     *
     * @param int $chartid Chart id.
     * @return int
     */
    public static function timepoints(int $chartid): int {
        return self::timepoints_for([$chartid])[$chartid];
    }

    /**
     * How many run times each chart has points for, in one query.
     *
     * @param int[] $chartids Chart ids.
     * @return int[] The count of each id, zero for a chart with no point.
     */
    public static function timepoints_for(array $chartids): array {
        global $DB;

        $counts = array_fill_keys(array_map('intval', $chartids), 0);
        if (!$counts) {
            return $counts;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($counts), SQL_PARAMS_NAMED);
        $rows = $DB->get_records_sql(
            "SELECT chartid, COUNT(DISTINCT timepoint) AS points
               FROM {" . self::TABLE . "}
              WHERE chartid $insql
           GROUP BY chartid",
            $params
        );
        foreach ($rows as $row) {
            $counts[(int) $row->chartid] = (int) $row->points;
        }
        return $counts;
    }

    /**
     * The stored points of a chart followed by the values of a run that is not stored, formatted.
     *
     * @param int $chartid Chart id, 0 for a chart that has none yet.
     * @param array $values Value of each series keyed by its label.
     * @param int $time Run time of the values.
     * @return array One row per run time.
     */
    public static function preview_rows(int $chartid, array $values, int $time): array {
        $rows = [];
        foreach (self::rows($chartid) as $stored) {
            $row = [self::TIME_COLUMN => $stored[self::TIME_COLUMN]];
            foreach (array_keys($values) as $label) {
                $row[$label] = $stored[$label] ?? null;
            }
            $rows[] = $row;
        }
        $rows[] = [self::TIME_COLUMN => $time] + $values;
        return self::format_rows($rows);
    }

    /**
     * Timepoints a chart keeps: its own setting, else the site setting, never below the floor.
     *
     * @param stdClass $chart Chart record.
     * @return int
     */
    public static function retention(stdClass $chart): int {
        $keep = (int) ($chart->pointsretention ?? 0);
        if ($keep <= 0) {
            $keep = (int) get_config('local_aicharts', 'pointsretention');
        }
        if ($keep <= 0) {
            $keep = self::RETENTION_DEFAULT;
        }
        return max(self::RETENTION_FLOOR, $keep);
    }

    /**
     * Deletes the points of a chart beyond the newest timepoints kept.
     *
     * @param int $chartid Chart id.
     * @param int $keep How many timepoints to keep, as retention() returns it.
     * @return void
     */
    public static function prune(int $chartid, int $keep): void {
        global $DB;

        $times = $DB->get_fieldset_sql(
            "SELECT DISTINCT timepoint
               FROM {" . self::TABLE . "}
              WHERE chartid = :chartid
           ORDER BY timepoint DESC",
            ['chartid' => $chartid]
        );
        if (count($times) <= $keep) {
            return;
        }
        $DB->delete_records_select(
            self::TABLE,
            'chartid = :chartid AND timepoint < :cutoff',
            ['chartid' => $chartid, 'cutoff' => $times[$keep - 1]]
        );
    }

    /**
     * Deletes the points of the series that no longer belong to a chart.
     *
     * @param int $chartid Chart id.
     * @param string[] $labels Labels of the series that were removed.
     * @return void
     */
    public static function delete_series(int $chartid, array $labels): void {
        global $DB;

        if (!$labels) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($labels, SQL_PARAMS_NAMED);
        $params['chartid'] = $chartid;
        $DB->delete_records_select(self::TABLE, 'chartid = :chartid AND serieslabel ' . $insql, $params);
    }
}
