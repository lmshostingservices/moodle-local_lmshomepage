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

/**
 * Lightweight course list — replaces core_course_get_courses everywhere.
 *
 * Returns only the fields every cache-builder actually needs:
 *   id, fullname, shortname, categoryid, visible, numsections
 *
 * One SQL query → one HTTP call, replacing the 4-6 MB core_course_get_courses
 * response with a ~10-50 KB payload.
 *
 * Parameters:
 *   courseids=42,57,99  (optional — comma-separated IDs; omit for all visible courses)
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace local_lmshomepage\external;
defined('MOODLE_INTERNAL') || die();

class get_slim_courses extends \core_external\external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseids' => new \external_value(
                PARAM_TEXT,
                'Comma-separated course IDs (empty = all visible, non-site courses)',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Return slim course records.
     *
     * @param string $courseids  Comma-separated course IDs (empty = all).
     * @return array
     */
    public static function execute(string $courseids = ''): array {
        global $DB;

        $params     = [];
        $whereExtra = '';

        if ($courseids !== '') {
            $courseids = preg_replace('/[^0-9,]/', '', $courseids);
            $ids = array_filter(array_map('intval', explode(',', $courseids)));
            if (!empty($ids)) {
                [$inSql, $inParams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cid');
                $whereExtra = "AND c.id $inSql";
                $params     = $inParams;
            }
        }

        $rows = $DB->get_records_sql(
            "SELECT c.id,
                    c.fullname,
                    c.shortname,
                    c.category  AS categoryid,
                    c.visible,
                    c.numsections
               FROM {course} c
              WHERE c.id != 1
                AND c.visible = 1
                $whereExtra
           ORDER BY c.id",
            $params
        );

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id'          => (int)$r->id,
                'fullname'    => (string)$r->fullname,
                'shortname'   => (string)$r->shortname,
                'categoryid'  => (int)$r->categoryid,
                'visible'     => (int)$r->visible,
                'numsections' => (int)($r->numsections ?? 0),
            ];
        }
        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'id'          => new \external_value(PARAM_INT,  'Course ID'),
                'fullname'    => new \external_value(PARAM_RAW,  'Course full name'),
                'shortname'   => new \external_value(PARAM_RAW,  'Course short name'),
                'categoryid'  => new \external_value(PARAM_INT,  'Category ID'),
                'visible'     => new \external_value(PARAM_INT,  '1 = visible, 0 = hidden'),
                'numsections' => new \external_value(PARAM_INT,  'Number of course sections/modules'),
            ])
        );
    }
}
