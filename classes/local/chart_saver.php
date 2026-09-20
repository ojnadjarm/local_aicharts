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
 * Writes the chart a finished wizard describes.
 *
 * @package    local_aicharts
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chart_saver {
    /**
     * Saves the chart of a wizard and drops the points of the series it no longer has.
     *
     * @param wizard_state $state The finished wizard.
     * @return int The chart id.
     */
    public static function save(wizard_state $state): int {
        $data = $state->data;
        $trend = $data->kind === 'trend';
        $existing = $data->id ? chart_repository::get((int) $data->id) : null;

        $queries = [];
        foreach ($data->queries as $query) {
            $queries[] = (object) [
                'label' => $query->label,
                'hint' => $query->hint,
                'sqltext' => $query->sqltext,
                'params' => $query->params,
            ];
        }

        $id = chart_repository::save((object) [
            'id' => (int) $data->id,
            'name' => $data->name,
            'kind' => $data->kind,
            'prompt' => $queries ? (string) $queries[0]->hint : '',
            'queries' => $queries,
            'chartjson' => $data->chartjson,
            'maxrows' => (int) $data->maxrows,
            'runmode' => $data->runmode,
            'runhour' => (int) $data->runhour,
            'runday' => (int) $data->runday,
            'pointsretention' => $trend ? (int) $data->pointsretention : 0,
            'emailto' => (string) $data->emailto,
            'emailwhen' => $data->emailwhen,
        ]);

        if ($existing) {
            $gone = array_diff(array_column($existing->queries, 'label'), array_column($queries, 'label'));
            point_store::delete_series($id, array_values($gone));
        }

        return $id;
    }
}
