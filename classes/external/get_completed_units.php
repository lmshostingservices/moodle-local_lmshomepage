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
 * External function: local_lmshomepage_get_completed_units
 *
 * Returns one row per FULLY-COMPLETED VET UNIT (Moodle course) per student,
 * sourced from mdl_course_completions.timecompleted.
 *
 * In Wombat's Moodle setup, each VET unit is represented as a separate Moodle
 * course.  A "completed unit" therefore means course_completions.timecompleted
 * IS NOT NULL — i.e. the student has met all course-completion criteria for
 * that unit.  This is NOT the same as completing individual activities
 * (course_modules_completion), which would produce inflated record counts.
 *
 * One row = one student × one fully-completed VET unit.
 * Duplicates are eliminated by using a subquery for enrolments (no cartesian
 * product from multiple active enrolments in the same course).
 *
 * Trainer is resolved from the 'wombat_trainer' custom profile field
 * ("Your Trainer Is").  Falls back to an empty trainer when the field is
 * not configured or not populated for the student.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_completed_units
 *   date_from=0          (int, unix timestamp — 0 = no lower bound on timecompleted)
 *   date_to=0            (int, unix timestamp — 0 = now)
 *   cohort_id=0          (int, optional)
 *   trainer_userid=0     (int, optional)
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_completed_units extends \external_api {
    public static function execute_parameters (): \external_function_parameters {
        return new \external_function_parameters([
            'date_from' => new \external_value(
                PARAM_INT,
                'Start of date range (unix timestamp on timecompleted). 0 = no lower bound.',
                VALUE_DEFAULT,
                0
            ),
            'date_to' => new \external_value(
                PARAM_INT,
                'End of date range (unix timestamp on timecompleted). 0 = current time.',
                VALUE_DEFAULT,
                0
            ),
            'cohort_id' => new \external_value(
                PARAM_INT,
                'Filter to a specific cohort ID. 0 = all cohorts.',
                VALUE_DEFAULT,
                0
            ),
            'trainer_userid' => new \external_value(
                PARAM_INT,
                'Filter to a specific trainer user ID. 0 = all trainers.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Return one row per student per fully-completed VET unit (Moodle course).
     * Source: mdl_course_completions.timecompleted (NOT course_modules_completion).
     *
     * @param int $date_from      Start unix timestamp (0 = no lower bound).
     * @param int $date_to        End unix timestamp (0 = now).
     * @param int $cohort_id      Optional cohort filter.
     * @param int $trainer_userid Optional trainer filter.
     * @return array
     */
    public static function execute (int $date_from = 0, int $date_to = 0, int $cohort_id = 0, int $trainer_userid = 0): array {
        global $DB;

        $params = [];

        if ($date_to <= 0) {
            $date_to = time();
        }
        $params['date_to'] = $date_to;

        $dateFromWhere = '';
        if ($date_from > 0) {
            $params['date_from'] = $date_from;
            $dateFromWhere = 'AND cc.timecompleted >= :date_from';
        }

        // ── Trainer from 'wombat_trainer' custom profile field ────────────────
        // Resolve trainer by matching the field value against user fullnames.
        // Falls back to fieldid = -1 when the field does not exist, producing
        // no matches (trainer = NULL / unassigned) without throwing SQL errors.
        $trainerFieldId = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'wombat_trainer']);
        $params['tf_id'] = $trainerFieldId > 0 ? $trainerFieldId : -1;

        $trainerJoin = "
            LEFT JOIN {user_info_data} uid_tr
                ON uid_tr.userid = u.id AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
                ON trainer.deleted = 0 AND trainer.suspended = 0
               AND LOWER(TRIM(CONCAT(trainer.firstname, ' ', trainer.lastname)))
                 = LOWER(TRIM(uid_tr.data))
        ";

        $trainerWhere = '';
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            $trainerWhere = "AND trainer.id = :trainer_userid";
        }

        $cohortJoin  = '';
        $cohortWhere = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortJoin  = "JOIN {cohort_members} cm_flt ON cm_flt.userid = u.id AND cm_flt.cohortid = :cohort_id";
        }

        // ── Core query ────────────────────────────────────────────────────────
        // One row per student per fully-completed course.
        // Enrolments are collapsed into a subquery (enrol_sub) to guarantee no
        // duplicate rows when a student has multiple active enrolments in the
        // same course (e.g. cohort-sync + manual enrolment).
        $sql = "
            SELECT
                cc.userid                                                 AS userid,
                CONCAT(u.firstname, ' ', u.lastname)                     AS fullname,
                u.email,
                c.id                                                      AS courseid,
                c.shortname                                               AS course_shortname,
                COALESCE(NULLIF(c.fullname, ''), c.shortname)            AS course_fullname,
                COALESCE(coh_sub.cohort_name, '')                        AS cohort_name,
                COALESCE(grp_sub.group_name,  '')                        AS group_name,
                COALESCE(enrol_sub.min_start, 0)                         AS enrol_date,
                cc.timecompleted                                          AS completed_date,
                COALESCE(trainer.id, 0)                                  AS trainer_id,
                COALESCE(
                    CONCAT(trainer.firstname, ' ', trainer.lastname),
                    COALESCE(uid_tr.data, '')
                )                                                         AS trainer_name
            FROM {course_completions} cc
            JOIN {user}   u  ON u.id = cc.userid
                             AND u.deleted = 0 AND u.suspended = 0
                             AND u.id != 1 AND u.username != 'guest'
            JOIN {course} c  ON c.id = cc.course AND c.id != 1
            -- Active enrolment subquery: one row per (student, course) regardless of
            -- how many enrolment methods are active.  Eliminates duplicate rows.
            JOIN (
                SELECT ue2.userid, en2.courseid, MIN(ue2.timestart) AS min_start
                FROM   {user_enrolments} ue2
                JOIN   {enrol} en2 ON en2.id = ue2.enrolid
                WHERE  ue2.status = 0
                  AND  (ue2.timeend = 0 OR ue2.timeend > :uenow)
                GROUP  BY ue2.userid, en2.courseid
            ) enrol_sub ON enrol_sub.userid = u.id AND enrol_sub.courseid = c.id
            -- ONE cohort per student — MIN(name) for determinism across multiple cohorts
            LEFT JOIN (
                SELECT cm_i.userid, MIN(coh_i.name) AS cohort_name
                FROM   {cohort_members} cm_i
                JOIN   {cohort} coh_i ON coh_i.id = cm_i.cohortid
                GROUP  BY cm_i.userid
            ) coh_sub ON coh_sub.userid = u.id
            -- ONE group per student per course — MIN(name) for determinism
            LEFT JOIN (
                SELECT gm_i.userid, g_i.courseid, MIN(g_i.name) AS group_name
                FROM   {groups_members} gm_i
                JOIN   {groups} g_i ON g_i.id = gm_i.groupid
                GROUP  BY gm_i.userid, g_i.courseid
            ) grp_sub ON grp_sub.userid = u.id AND grp_sub.courseid = c.id
            $trainerJoin
            $cohortJoin
            WHERE cc.timecompleted IS NOT NULL
              AND cc.timecompleted <= :date_to
              $dateFromWhere
              $trainerWhere
              $cohortWhere
            ORDER BY cc.timecompleted DESC
        ";

        $params['uenow'] = time();
        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $unitName = (string)$row->course_fullname;
            $result[] = [
                'userid'           => (int)    $row->userid,
                'fullname'         => (string) $row->fullname,
                'email'            => (string) $row->email,
                'courseid'         => (int)    $row->courseid,
                'course_shortname' => (string) $row->course_shortname,
                'course_fullname'  => $unitName,
                // unit_code = VET unit code (course shortname, e.g. BSBCMM211)
                // unit_name = full VET unit name (course fullname, with shortname fallback)
                'unit_code'        => (string) $row->course_shortname,
                'unit_name'        => $unitName,
                'cohort_name'      => (string) $row->cohort_name,
                'group_name'       => (string) $row->group_name,
                'enrol_date'       => (int)    $row->enrol_date,
                'completed_date'   => (int)    $row->completed_date,
                'trainer_id'       => (int)    $row->trainer_id,
                'trainer_name'     => (string) $row->trainer_name,
            ];
        }

        return $result;
    }

    public static function execute_returns (): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'           => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'         => new \external_value(PARAM_TEXT, 'Student full name'),
                'email'            => new \external_value(PARAM_TEXT, 'Student email'),
                'courseid'         => new \external_value(PARAM_INT,  'Course ID (= VET unit)'),
                'course_shortname' => new \external_value(PARAM_TEXT, 'VET unit code (course shortname, e.g. CHCDIV001)'),
                'course_fullname'  => new \external_value(PARAM_TEXT, 'Full VET unit name (course fullname, falls back to shortname)'),
                'unit_code'        => new \external_value(PARAM_TEXT, 'Alias for course_shortname — VET unit code'),
                'unit_name'        => new \external_value(PARAM_TEXT, 'Alias for course_fullname — full VET unit name'),
                'cohort_name'      => new \external_value(PARAM_TEXT, 'Cohort name'),
                'group_name'       => new \external_value(PARAM_TEXT, 'Group name'),
                'enrol_date'       => new \external_value(PARAM_INT,  'Enrolment date (unix timestamp)'),
                'completed_date'   => new \external_value(PARAM_INT,  'Unit completion date (unix timestamp from course_completions.timecompleted)'),
                'trainer_id'       => new \external_value(PARAM_INT,  'Trainer user ID (0 = unassigned or unmatched)'),
                'trainer_name'     => new \external_value(PARAM_TEXT, 'Trainer name from wombat_trainer profile field'),
            ])
        );
    }
}
