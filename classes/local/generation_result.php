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

/**
 * Outcome of one chart generation.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_result {
    /** @var string[] Statuses of a generation that produced rows. */
    public const OK_STATUSES = ['chart', 'table'];

    /**
     * Constructor.
     *
     * @param string $status chart, table, refused, invalid_json, validation_failed, db_error or llm_error.
     * @param string $name Suggested chart name.
     * @param string $sql Generated query, tables as {name} placeholders.
     * @param array $params Named parameter values.
     * @param string $chartjson Chart definition as JSON.
     * @param array $rows Result rows.
     * @param int $rowcount Rows returned, after truncation.
     * @param bool $truncated Whether the row limit cut the result.
     * @param int $durationms Time the query took.
     * @param string $errormessage Sanitised detail of the failure, empty on success.
     * @param string $notes Sentence the model added for the user.
     * @param string $errorcode Code of a provider failure: nokey, unreachable, httperror or badanswer.
     */
    public function __construct(
        /** @var string One of chart, table, refused, invalid_json, validation_failed, db_error or llm_error. */
        public readonly string $status,
        /** @var string Suggested chart name. */
        public readonly string $name = '',
        /** @var string Generated query, tables as {name} placeholders. */
        public readonly string $sql = '',
        /** @var array Named parameter values. */
        public readonly array $params = [],
        /** @var string Chart definition as JSON. */
        public readonly string $chartjson = '',
        /** @var array Result rows. */
        public readonly array $rows = [],
        /** @var int Rows returned, after truncation. */
        public readonly int $rowcount = 0,
        /** @var bool Whether the row limit cut the result. */
        public readonly bool $truncated = false,
        /** @var int Time the query took. */
        public readonly int $durationms = 0,
        /** @var string Sanitised detail of the failure, empty on success. */
        public readonly string $errormessage = '',
        /** @var string Sentence the model added for the user. */
        public readonly string $notes = '',
        /** @var string Code of a provider failure: nokey, unreachable, httperror or badanswer. */
        public readonly string $errorcode = '',
    ) {
    }

    /**
     * Whether the generation produced no rows to show.
     *
     * @return bool
     */
    public function has_error(): bool {
        return !in_array($this->status, self::OK_STATUSES, true);
    }
}
