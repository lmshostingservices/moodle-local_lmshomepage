<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// You can redistribute it and/or modify it under the terms of the GNU General Public License
// As published by the Free Software Foundation, either version 3 of the License, or any later version.
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// However, it is WITHOUT ANY WARRANTY, including implied warranties of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// You should have received a copy of the GNU General Public License along with Moodle.
// If not, see <http://www.gnu.org/licenses/>.

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
 * Trainer is resolved from the 'trainername' custom profile field
 * ("Your Trainer Is").  Falls back to an empty trainer when the field is
 * not configured or not populated for the student.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_completed_units
 *   date_from=0          (int, unix timestamp — 0 = no lower bound on timecompleted)
 *   date_to=0            (int, unix timestamp — 0 = now)
 *   cohort_id=0          (int, optional)
 *   trainer_userid=0     (int, optional)
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_completed_units extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
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
    public static function execute(int $date_from = 0, int $date_to = 0, int $cohort_id = 0, int $trainer_userid = 0): array {
        global $DB;

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'date_from'      => $date_from,
            'date_to'        => $date_to,
            'cohort_id'      => $cohort_id,
            'trainer_userid' => $trainer_userid,
        ]);

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

        // ── Trainer from 'trainername' custom profile field ────────────────
        // Resolve trainer by matching the field value against user fullnames.
        // Falls back to fieldid = -1 when the field does not exist, producing
        // No matches (trainer = NULL / unassigned) without throwing SQL errors.
        $trainerFieldId = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'trainername']);
        $params['tf_id'] = $trainerFieldId > 0 ? $trainerFieldId : -1;
        $params['tf_id_grp'] = $trainerFieldId > 0 ? $trainerFieldId : -1;

        // DB-portable name concatenation (handles NULL coalescing correctly on all DB engines).
        $sqlFullname        = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $sqlTrainerFullname = $DB->sql_concat('trainer.firstname', "' '", 'trainer.lastname');

        $trainerJoin = "
            LEFT JOIN {user_info_data} uid_tr
                ON uid_tr.userid = u.id AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
                ON trainer.deleted = 0 AND trainer.suspended = 0
               AND LOWER(TRIM({$sqlTrainerFullname}))
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

        // ── Data source guard ─────────────────────────────────────────────────
        //
        // When local_finalmarkingsheet exists, use deemedcompetentdate (status='approved')
        // As the completion signal — this matches the Dashboard Competencies KPI source
        // And the assessor-approved marking sheet workflow (RPT-3 alignment fix).
        //
        // When the table is absent (non-Wombat sites), fall back to Moodle's native
        // Course_completions.timecompleted so the report still works everywhere.
        //
        $dbman = $DB->get_manager();
        $useFms = $dbman->table_exists('local_finalmarkingsheet');

        if ($useFms) {
            // ── Approved competency date with native-completion fallback ─────────
            // The approved marking-sheet date is authoritative whenever it exists.
            // Native course completion is included only for a student/unit without
            // An approved marking sheet, preserving legitimate mixed-site records.
            if ($date_from > 0) {
                $dateFromWhere = 'AND fms.fms_date >= :date_from';
            }
            $sql = "
                SELECT
                    u.id                                                      AS userid,
                    {$sqlFullname}                                            AS fullname,
                    u.email,
                    c.id                                                      AS courseid,
                    c.shortname                                               AS course_shortname,
                    COALESCE(NULLIF(c.fullname, ''), c.shortname)            AS course_fullname,
                    COALESCE(coh_sub.cohort_name, '')                        AS cohort_name,
                    COALESCE(grp_sub.group_name,  '')                        AS group_name,
                    COALESCE(enrol_sub.min_start, 0)                         AS enrol_date,
                    fms.fms_date                                              AS completed_date,
                    COALESCE(trainer.id, 0)                                  AS trainer_id,
                    COALESCE(
                        {$sqlTrainerFullname},
                        COALESCE(uid_tr.data, '')
                    )                                                         AS trainer_name
                FROM (
                    SELECT fms2.userid,
                           fms2.courseid,
                           MAX(fms2.deemedcompetentdate) AS fms_date
                    FROM   {local_finalmarkingsheet} fms2
                    WHERE  fms2.status = 'approved'
                      AND  fms2.deemedcompetentdate > 0
                     GROUP  BY fms2.userid, fms2.courseid
                    UNION ALL
                    SELECT cc2.userid,
                           cc2.course AS courseid,
                           cc2.timecompleted AS fms_date
                    FROM   {course_completions} cc2
                    WHERE  cc2.timecompleted IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1
                          FROM {local_finalmarkingsheet} fms_native
                          WHERE fms_native.userid = cc2.userid
                            AND fms_native.courseid = cc2.course
                            AND fms_native.status = 'approved'
                            AND fms_native.deemedcompetentdate > 0
                      )
                ) fms
                JOIN {user}   u  ON u.id  = fms.userid
                                 AND u.deleted = 0 AND u.suspended = 0
                                 AND u.id != 1 AND u.username != 'guest'
                JOIN {course} c  ON c.id  = fms.courseid AND c.id != 1
                -- Active enrolment subquery
                JOIN (
                    SELECT ue2.userid, en2.courseid, MIN(ue2.timestart) AS min_start
                    FROM   {user_enrolments} ue2
                    JOIN   {enrol} en2 ON en2.id = ue2.enrolid
                    WHERE  ue2.status = 0
                      AND  (ue2.timeend = 0 OR ue2.timeend > :uenow)
                    GROUP  BY ue2.userid, en2.courseid
                ) enrol_sub ON enrol_sub.userid = u.id AND enrol_sub.courseid = c.id
                -- ONE cohort per student
                LEFT JOIN (
                    SELECT cm_i.userid, MIN(coh_i.name) AS cohort_name
                    FROM   {cohort_members} cm_i
                    JOIN   {cohort} coh_i ON coh_i.id = cm_i.cohortid
                    GROUP  BY cm_i.userid
                ) coh_sub ON coh_sub.userid = u.id
                -- ONE real group per student per visible course. Trainer-allocation
                -- pseudo-groups are excluded so this matches the Marking Report.
                LEFT JOIN (
                    SELECT gm_i.userid, g_i.courseid, MIN(g_i.name) AS group_name
                    FROM   {groups_members} gm_i
                    JOIN   {groups} g_i ON g_i.id = gm_i.groupid
                    JOIN   {course} gc_i ON gc_i.id = g_i.courseid AND gc_i.id != 1 AND gc_i.visible = 1
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM {user_info_data} tf_grp
                        WHERE tf_grp.fieldid = :tf_id_grp
                          AND TRIM(tf_grp.data) != ''
                          AND LOWER(TRIM(tf_grp.data)) = LOWER(TRIM(g_i.name))
                    )
                    GROUP  BY gm_i.userid, g_i.courseid
                ) grp_sub ON grp_sub.userid = u.id AND grp_sub.courseid = c.id
                $trainerJoin
                $cohortJoin
                WHERE fms.fms_date <= :date_to
                  $dateFromWhere
                  $trainerWhere
                  $cohortWhere
                ORDER BY fms.fms_date DESC
            ";
        } else {
            // ── Fallback: Moodle course_completions (sites without marking sheets) ──
            // One row per student per fully-completed course.
            // Enrolments collapsed into a subquery to avoid duplicate rows.
            if ($date_from > 0) {
                $dateFromWhere = 'AND cc.timecompleted >= :date_from';
            }
            $sql = "
                SELECT
                    cc.userid                                                 AS userid,
                    {$sqlFullname}                                            AS fullname,
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
                        {$sqlTrainerFullname},
                        COALESCE(uid_tr.data, '')
                    )                                                         AS trainer_name
                FROM {course_completions} cc
                JOIN {user}   u  ON u.id = cc.userid
                                 AND u.deleted = 0 AND u.suspended = 0
                                 AND u.id != 1 AND u.username != 'guest'
                JOIN {course} c  ON c.id = cc.course AND c.id != 1
                JOIN (
                    SELECT ue2.userid, en2.courseid, MIN(ue2.timestart) AS min_start
                    FROM   {user_enrolments} ue2
                    JOIN   {enrol} en2 ON en2.id = ue2.enrolid
                    WHERE  ue2.status = 0
                      AND  (ue2.timeend = 0 OR ue2.timeend > :uenow)
                    GROUP  BY ue2.userid, en2.courseid
                ) enrol_sub ON enrol_sub.userid = u.id AND enrol_sub.courseid = c.id
                LEFT JOIN (
                    SELECT cm_i.userid, MIN(coh_i.name) AS cohort_name
                    FROM   {cohort_members} cm_i
                    JOIN   {cohort} coh_i ON coh_i.id = cm_i.cohortid
                    GROUP  BY cm_i.userid
                ) coh_sub ON coh_sub.userid = u.id
                LEFT JOIN (
                    SELECT gm_i.userid, g_i.courseid, MIN(g_i.name) AS group_name
                    FROM   {groups_members} gm_i
                    JOIN   {groups} g_i ON g_i.id = gm_i.groupid
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM {user_info_data} tf_grp
                        WHERE tf_grp.fieldid = :tf_id_grp
                          AND TRIM(tf_grp.data) != ''
                          AND LOWER(TRIM(tf_grp.data)) = LOWER(TRIM(g_i.name))
                    )
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
        }

        $params['uenow'] = time();
        // Results contain one row per student × unit. get_records_sql indexes
        // By the first selected field (userid), which would silently discard
        // Every additional completed unit for the same student.
        $rows = $DB->get_recordset_sql($sql, $params);

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
                // Unit_code = VET unit code (course shortname, e.g. BSBCMM211)
                // Unit_name = full VET unit name (course fullname, with shortname fallback)
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
        $rows->close();

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
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
                'completed_date'   => new \external_value(PARAM_INT,  'Unit completion date (unix timestamp — deemedcompetentdate from local_finalmarkingsheet when available, else course_completions.timecompleted)'),
                'trainer_id'       => new \external_value(PARAM_INT,  'Trainer user ID (0 = unassigned or unmatched)'),
                'trainer_name'     => new \external_value(PARAM_TEXT, 'Trainer name from trainername profile field'),
            ])
        );
    }
}
