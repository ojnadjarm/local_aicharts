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
 * Outcome of one query run.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class query_result {
    /**
     * Constructor. Use one of the factory methods instead.
     *
     * @param array $rows Result rows, empty on error.
     * @param int $rowcount Rows returned, after truncation.
     * @param bool $truncated Whether the row limit cut the result.
     * @param int $durationms Time the query took.
     * @param string $status ok, validation_failed or db_error.
     * @param string $errormessage Sanitised detail of the failure, empty on success.
     * @param string $errordetail What the database reported, empty when there is nothing to add.
     */
    protected function __construct(
        /** @var array Result rows, empty on error. */
        public readonly array $rows,
        /** @var int Rows returned, after truncation. */
        public readonly int $rowcount,
        /** @var bool Whether the row limit cut the result. */
        public readonly bool $truncated,
        /** @var int Time the query took. */
        public readonly int $durationms,
        /** @var string One of ok, validation_failed or db_error. */
        public readonly string $status = 'ok',
        /** @var string Sanitised detail of the failure, empty on success. */
        public readonly string $errormessage = '',
        /** @var string What the database reported, empty when there is nothing to add. */
        public readonly string $errordetail = '',
    ) {
    }

    /**
     * Result of a query that ran.
     *
     * @param array $rows Result rows.
     * @param bool $truncated Whether the row limit cut the result.
     * @param int $durationms Time the query took.
     * @return self
     */
    public static function success(array $rows, bool $truncated, int $durationms): self {
        return new self($rows, count($rows), $truncated, $durationms);
    }

    /**
     * Result of a query that was rejected or failed.
     *
     * @param string $status validation_failed or db_error.
     * @param string $errormessage Sanitised detail, never the query or its parameters.
     * @param int $durationms Time spent before the failure.
     * @param string $errordetail What the database reported, never the query or its parameters.
     * @return self
     */
    public static function error(
        string $status,
        string $errormessage,
        int $durationms = 0,
        string $errordetail = ''
    ): self {
        return new self([], 0, false, $durationms, $status, $errormessage, $errordetail);
    }

    /**
     * Whether the query ran.
     *
     * @return bool
     */
    public function has_error(): bool {
        return $this->status !== 'ok';
    }
}
