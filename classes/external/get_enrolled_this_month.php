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
 * External function: local_lmshomepage_get_enrolled_this_month
 *
 * ADDED (v2.11.48) — Dashboard Data Review (12 Aug 2026), Issue 2.
 *
 * The previous "Enrolled this Month" figure (built by the dashboard server
 * from local_lmshomepage_get_enrolled_students) counts one row per
 * (courseid, userid) pair — a student enrolled in 5 courses this month is
 * counted 5 times. That function is left unchanged since other reports
 * genuinely need the per-course rows; this is a NEW, purpose-built function
 * for the "Enrolled this Month" KPI.
 *
 * Per the client's request, enrolment is recognised once per student, dated
 * by the EARLIEST mdl_cohort_members.timeadded across all their cohorts —
 * i.e. when the student was assigned to their cohort / had their Training
 * Plan generated — rather than by individual Moodle course-enrolment dates.
 * A student therefore appears at most once, in the month they joined their
 * (first) cohort.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_enrolled_this_month
 *   date_from=0   (int, unix timestamp on cohort timeadded. 0 = start of current calendar month.)
 *   date_to=0     (int, unix timestamp on cohort timeadded. 0 = start of next calendar month.)
 *   cohort_id=0   (int, optional — 0 = all cohorts)
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_enrolled_this_month extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'date_from' => new \external_value(
                PARAM_INT,
                'Start of date range (unix timestamp on cohort_members.timeadded). 0 = start of current calendar month.',
                VALUE_DEFAULT,
                0
            ),
            'date_to' => new \external_value(
                PARAM_INT,
                'End of date range (unix timestamp on cohort_members.timeadded). 0 = start of next calendar month.',
                VALUE_DEFAULT,
                0
            ),
            'cohort_id' => new \external_value(
                PARAM_INT,
                'Filter to a specific cohort ID. 0 = all cohorts.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(int $date_from = 0, int $date_to = 0, int $cohort_id = 0): array {
        global $DB;

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'date_from' => $date_from,
            'date_to'   => $date_to,
            'cohort_id' => $cohort_id,
        ]);

        if ($date_from <= 0 || $date_to <= 0) {
            $now        = time();
            $monthstart = mktime(0, 0, 0, (int)date('n', $now), 1, (int)date('Y', $now));
            $nextmonth  = mktime(0, 0, 0, (int)date('n', $now) + 1, 1, (int)date('Y', $now));
            $date_from  = $monthstart;
            $date_to    = $nextmonth;
        }

        $params = [
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ];

        $trainerFieldId = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'trainername']);
        $params['tf_id'] = $trainerFieldId > 0 ? $trainerFieldId : -1;

        $sqlFullname        = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $sqlTrainerFullname = $DB->sql_concat('trainer.firstname', "' '", 'trainer.lastname');

        $cohortWhere = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            // Restrict the "earliest cohort" resolution itself to this cohort,
            // so cohort_id filters the enrolment event, not just the student.
            $cohortWhere = 'AND cm_min.cohortid = :cohort_id';
        }

        // One row per student, guaranteed by two-stage aggregation (no risk
        // of duplicate rows even if two cohort_members rows share an exact
        // timeadded — the second stage deterministically picks the lowest
        // cohortid to break the tie):
        //   1. min_ta   — earliest timeadded per student (optionally scoped
        //                 to a single cohort via $cohortWhere).
        //   2. tie_break — of the (usually one) cohort_members rows matching
        //                 that earliest timeadded, pick the lowest cohortid.
        $sql = "
            SELECT
                u.id                                                      AS userid,
                {$sqlFullname}                                            AS fullname,
                u.email,
                tie_break.cohortid                                        AS cohortid,
                coh.name                                                  AS cohort_name,
                min_ta.enrol_date                                         AS enrol_date,
                COALESCE(trainer.id, 0)                                  AS trainer_id,
                COALESCE({$sqlTrainerFullname}, NULLIF(TRIM(uid_tr.data), ''), '') AS trainer_name
            FROM (
                SELECT cm_min.userid, MIN(cm_min.timeadded) AS enrol_date
                FROM   {cohort_members} cm_min
                WHERE  1=1 $cohortWhere
                GROUP  BY cm_min.userid
            ) min_ta
            JOIN (
                SELECT cm2.userid, cm2.timeadded, MIN(cm2.cohortid) AS cohortid
                FROM   {cohort_members} cm2
                GROUP  BY cm2.userid, cm2.timeadded
            ) tie_break ON tie_break.userid = min_ta.userid
                        AND tie_break.timeadded = min_ta.enrol_date
            JOIN {cohort} coh ON coh.id = tie_break.cohortid
            JOIN {user} u ON u.id = min_ta.userid
                          AND u.deleted = 0 AND u.suspended = 0
                          AND u.id != 1 AND u.username != 'guest'
            LEFT JOIN {user_info_data} uid_tr
                ON uid_tr.userid = u.id AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
                ON trainer.deleted = 0 AND trainer.suspended = 0
               AND LOWER(TRIM({$sqlTrainerFullname}))
                 = LOWER(TRIM(uid_tr.data))
            WHERE min_ta.enrol_date >= :date_from
              AND min_ta.enrol_date <  :date_to
            ORDER BY min_ta.enrol_date DESC
        ";

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'userid'       => (int)    $row->userid,
                'fullname'     => (string) $row->fullname,
                'email'        => (string) $row->email,
                'cohortid'     => (int)    $row->cohortid,
                'cohort_name'  => (string) $row->cohort_name,
                'enrol_date'   => (int)    $row->enrol_date,
                'trainer_id'   => (int)    $row->trainer_id,
                'trainer_name' => (string) $row->trainer_name,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'       => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'     => new \external_value(PARAM_TEXT, 'Student full name'),
                'email'        => new \external_value(PARAM_TEXT, 'Student email'),
                'cohortid'     => new \external_value(PARAM_INT,  'Earliest cohort ID the student was assigned to'),
                'cohort_name'  => new \external_value(PARAM_TEXT, 'Name of that earliest cohort'),
                'enrol_date'   => new \external_value(PARAM_INT,  'cohort_members.timeadded for the earliest cohort — the enrolment date'),
                'trainer_id'   => new \external_value(PARAM_INT,  'Trainer user ID (0 = unassigned or unmatched)'),
                'trainer_name' => new \external_value(PARAM_TEXT, 'Trainer name from trainername profile field'),
            ])
        );
    }
}
