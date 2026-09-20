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

use context_system;
use moodle_exception;
use moodle_url;
use stdClass;
use stored_file;

/**
 * Stores the outcome of a chart run as a database row plus a CSV file.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_store {
    /** @var string Table holding the stored runs. */
    protected const TABLE = 'local_aicharts_result';

    /** @var string Component owning the result files. */
    public const COMPONENT = 'local_aicharts';

    /** @var string File area holding the result rows. */
    public const FILEAREA = 'result';

    /** @var int Stored runs kept per chart when the site setting is missing. */
    protected const RETENTION_DEFAULT = 30;

    /**
     * Records one run of a chart and, when it succeeded, writes its rows as CSV.
     *
     * @param stdClass $chart Chart the run belongs to.
     * @param array $rows Result rows, empty when the query failed.
     * @param string $trigger scheduled, manual or save.
     * @param int $userid User who asked for the run, 0 for a task.
     * @param string|null $error Sanitised failure detail, null when the query ran.
     * @param int $durationms Time the query took.
     * @param bool $truncated Whether the row limit cut the result.
     * @return stdClass The stored result record.
     */
    public static function store(
        stdClass $chart,
        array $rows,
        string $trigger,
        int $userid,
        ?string $error = null,
        int $durationms = 0,
        bool $truncated = false
    ): stdClass {
        global $DB;

        $failed = $error !== null;
        $record = (object) [
            'chartid' => $chart->id,
            'status' => $failed ? 'db_error' : 'ok',
            'numrows' => $failed ? null : count($rows),
            'truncated' => $truncated ? 1 : 0,
            'durationms' => $durationms,
            'errormessage' => $error,
            'runtrigger' => $trigger,
            'userid' => $userid,
            'emailed' => 0,
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);

        if (!$failed) {
            self::write_csv($record, $rows);
            self::write_image($record, $chart, $rows);
        }

        return $record;
    }

    /**
     * Returns the newest successful result of a chart.
     *
     * @param int $chartid Chart id.
     * @return stdClass|null
     */
    public static function get_latest_ok(int $chartid): ?stdClass {
        global $DB;

        $records = $DB->get_records(
            self::TABLE,
            ['chartid' => $chartid, 'status' => 'ok'],
            'timecreated DESC, id DESC',
            '*',
            0,
            1
        );

        return $records ? reset($records) : null;
    }

    /**
     * Returns one result.
     *
     * @param int $id Result id.
     * @return stdClass|null
     */
    public static function get(int $id): ?stdClass {
        global $DB;

        return $DB->get_record(self::TABLE, ['id' => $id]) ?: null;
    }

    /**
     * Returns the stored runs of a chart, newest first.
     *
     * @param int $chartid Chart id.
     * @return stdClass[]
     */
    public static function list_for_chart(int $chartid): array {
        global $DB;

        return $DB->get_records(self::TABLE, ['chartid' => $chartid], 'timecreated DESC, id DESC');
    }

    /**
     * Reads the rows of a stored result back from its CSV file.
     *
     * @param stdClass $result Result record.
     * @return array Associative rows, empty when the result carries no file.
     */
    public static function load_rows(stdClass $result): array {
        $file = self::get_file($result);
        if (!$file) {
            return [];
        }

        $handle = $file->get_content_file_handle();
        $columns = fgetcsv($handle, 0, ',', '"', '\\');
        $rows = [];
        while ($columns && ($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $rows[] = array_combine($columns, $values);
        }
        fclose($handle);

        return $rows;
    }

    /**
     * Returns the forced-download URL of a result file.
     *
     * @param stdClass $result Result record.
     * @return moodle_url|null Null when the result carries no file.
     */
    public static function download_url(stdClass $result): ?moodle_url {
        $file = self::get_file($result);
        if (!$file) {
            return null;
        }

        return moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            $file->get_component(),
            $file->get_filearea(),
            $file->get_itemid(),
            $file->get_filepath(),
            $file->get_filename(),
            true
        );
    }

    /**
     * Deletes the stored runs of a chart beyond the retention count, newest kept.
     *
     * @param int $chartid Chart id.
     * @param int|null $keep How many runs to keep, the site setting when null.
     * @return void
     */
    public static function prune(int $chartid, ?int $keep = null): void {
        if ($keep === null) {
            $keep = (int) get_config('local_aicharts', 'resultretention');
        }
        if ($keep < 1) {
            $keep = self::RETENTION_DEFAULT;
        }

        $results = self::list_for_chart($chartid);
        foreach (array_slice($results, $keep) as $result) {
            self::delete($result);
        }
    }

    /**
     * Deletes every stored run of a chart with its files.
     *
     * @param int $chartid Chart id.
     * @return void
     */
    public static function delete_for_chart(int $chartid): void {
        foreach (self::list_for_chart($chartid) as $result) {
            self::delete($result);
        }
    }

    /**
     * Returns the CSV file of a result.
     *
     * @param stdClass $result Result record.
     * @return stored_file|null
     */
    public static function get_file(stdClass $result): ?stored_file {
        return self::find_file($result, 'csv');
    }

    /**
     * Returns the chart image of a result, drawn only when the chart has recipients.
     *
     * @param stdClass $result Result record.
     * @return stored_file|null
     */
    public static function get_image(stdClass $result): ?stored_file {
        return self::find_file($result, 'png');
    }

    /**
     * Returns the file of a result with the given extension.
     *
     * @param stdClass $result Result record.
     * @param string $extension File extension without the dot.
     * @return stored_file|null
     */
    protected static function find_file(stdClass $result, string $extension): ?stored_file {
        $files = get_file_storage()->get_area_files(
            context_system::instance()->id,
            self::COMPONENT,
            self::FILEAREA,
            $result->id,
            'filename',
            false
        );

        foreach ($files as $file) {
            if (pathinfo($file->get_filename(), PATHINFO_EXTENSION) === $extension) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Deletes one result row and everything stored with it.
     *
     * @param stdClass $result Result record.
     * @return void
     */
    protected static function delete(stdClass $result): void {
        global $DB;

        get_file_storage()->delete_area_files(
            context_system::instance()->id,
            self::COMPONENT,
            self::FILEAREA,
            $result->id
        );
        point_store::unlink_result($result->id);
        $DB->delete_records(self::TABLE, ['id' => $result->id]);
    }

    /**
     * Writes the rows of a result as a CSV file, column names on the first line.
     *
     * @param stdClass $result Result record.
     * @param array $rows Result rows.
     * @return void
     */
    protected static function write_csv(stdClass $result, array $rows): void {
        $handle = fopen('php://temp', 'r+');
        if ($rows) {
            $columns = array_keys((array) reset($rows));
            fputcsv($handle, $columns, ',', '"', '\\');
            foreach ($rows as $row) {
                fputcsv($handle, array_values((array) $row), ',', '"', '\\');
            }
        }
        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        self::save_file($result, 'csv', $content);
    }

    /**
     * Draws the rows as a PNG for the result email, for charts that have recipients.
     *
     * @param stdClass $result Result record.
     * @param stdClass $chart Chart the run belongs to.
     * @param array $rows Result rows.
     * @return void
     */
    protected static function write_image(stdClass $result, stdClass $chart, array $rows): void {
        if (trim((string) ($chart->emailto ?? '')) === '') {
            return;
        }

        try {
            $spec = chart_spec::from_json((string) $chart->chartjson);
        } catch (moodle_exception $e) {
            return;
        }

        $png = chart_image::png($spec, $rows);
        if ($png === null) {
            return;
        }

        self::save_file($result, 'png', $png);
    }

    /**
     * Stores one file of a result.
     *
     * @param stdClass $result Result record.
     * @param string $extension File extension without the dot.
     * @param string $content File content.
     * @return void
     */
    protected static function save_file(stdClass $result, string $extension, string $content): void {
        $filerecord = (object) [
            'contextid' => context_system::instance()->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $result->id,
            'filepath' => '/',
            'filename' => 'chart-' . $result->chartid . '-' . date('Ymd-His', $result->timecreated) . '.' . $extension,
        ];
        get_file_storage()->create_file_from_string($filerecord, $content);
    }
}
