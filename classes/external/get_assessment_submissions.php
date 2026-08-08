<?php
/**
 * External function: local_lmshomepage_get_assessment_submissions
 *
 * Returns one row per assignment per enrolled student showing submission
 * status, due date, submission date, graded date, and overdue calculations.
 *
 * Status mapping:
 *   NYS       — no submission record, or submission status = 'new'
 *   IP        — submission status = 'draft'
 *   Submitted — submission status = 'submitted', no grade yet
 *   Graded    — submission status = 'submitted', grade record exists
 *
 * Overdue calculations:
 *   days_overdue         — (now − duedate) / 86400 if duedate passed and status != Graded; else 0
 *   days_grading_overdue — (now − submitted_date) / 86400 if status = Submitted and > 4 days; else 0
 *
 * Due date resolution (v2.11.26):
 *   Priority: block_trainingplan_userseq.enddate (manualoverride=1)
 *             → block_trainingplan_schedule.enddate (cohort-level)
 *             → assign.duedate (Moodle native)
 *   Tables are guarded — falls back gracefully if training plan is absent.
 *
 * Marking sheet competency date (v2.11.26):
 *   deemed_competent_date from local_finalmarkingsheet.deemedcompetentdate
 *   (one marking sheet per student × course; 0 if table absent or not yet marked).
 *
 * CHANGE (v2.11.25): trainer allocation switched from group co-membership to
 * the 'wombat_trainer' custom profile field ("Your Trainer Is"), consistent
 * with get_completed_units, get_trainer_allocations, and get_trainers.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_assessment_submissions
 *   cohort_id=0           (int, optional)
 *   trainer_userid=0      (int, optional)
 *   status_filter=''      (string: 'NYS','IP','Submitted','Graded',
 *                           'overdue_submission','overdue_grading', '' = all)
 *   require_completion=1  (int: 1 = only assignments with completion tracking;
 *                           0 = all assignments regardless of completion tracking)
 *   days_lookback=0       (int: 0 = no limit; >0 = only assignments with
 *                           duedate within last N days)
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_assessment_submissions extends \external_api {

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
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
            'status_filter' => new \external_value(
                PARAM_TEXT,
                'Filter by status: NYS, IP, Submitted, Graded, overdue_submission, overdue_grading, or empty string for all.',
                VALUE_DEFAULT,
                ''
            ),
            'require_completion' => new \external_value(
                PARAM_INT,
                '1 (default) = only return assignments where activity completion tracking is enabled (cm.completion > 0). '
                . '0 = return all assignments regardless of completion tracking. '
                . 'Set to 0 for sites like Wombat where completion tracking is not enabled on individual assignments.',
                VALUE_DEFAULT,
                1
            ),
            'days_lookback' => new \external_value(
                PARAM_INT,
                'Limit results to assignments whose due date falls within this many days in the past. '
                . '0 (default) = no limit (return all). Use 180 for KPI counts, 365 for the full marking report.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(int $cohort_id = 0, int $trainer_userid = 0, string $status_filter = '', int $require_completion = 1, int $days_lookback = 0): array {
        global $DB;

        $now    = time();
        $params = ['now' => $now];
        $dbman  = $DB->get_manager();

        // ── Days lookback ─────────────────────────────────────────────────────
        $lookbackWhere = '';
        if ($days_lookback > 0) {
            $params['cutoff_ts'] = $now - ($days_lookback * DAYSECS);
            $lookbackWhere = "AND (a.duedate = 0 OR a.duedate > :cutoff_ts)";
        }

        // ── Optional cohort filter ────────────────────────────────────────────
        $cohortJoin  = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortJoin  = "JOIN {cohort_members} cm_flt ON cm_flt.userid = u.id AND cm_flt.cohortid = :cohort_id";
        }

        // ── Resolve wombat_trainer profile field ID ───────────────────────────
        $trainerFieldId = (int) $DB->get_field('user_info_field', 'id', ['shortname' => 'wombat_trainer']);
        $params['tf_id'] = $trainerFieldId > 0 ? $trainerFieldId : -1;

        // ── Optional trainer filter ───────────────────────────────────────────
        $trainerWhere = '';
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            $trainerWhere = "AND trainer.id = :trainer_userid";
        }

        // Get the assign module ID once.
        $assignModuleId = (int) $DB->get_field('modules', 'id', ['name' => 'assign']);
        if (!$assignModuleId) {
            return [];
        }
        $params['assign_module_id'] = $assignModuleId;

        // ── Training Plan due date resolution ─────────────────────────────────
        //
        // Priority:
        //   1. block_trainingplan_userseq.enddate where manualoverride = 1
        //      (student-specific override set by admin/trainer)
        //   2. block_trainingplan_schedule.enddate (cohort-level schedule date)
        //   3. assign.duedate (Moodle native assignment due date)
        //
        // Both training plan tables are guarded — if neither exists (Signature,
        // TDT, AIOP, etc.) the query falls back to assign.duedate unchanged.
        //
        // The subqueries GROUP BY (userid, courseid) across all of the student's
        // cohorts and take MIN(enddate) from the non-zero entries. This correctly
        // handles the common case where a student belongs to exactly one cohort,
        // and is conservative (earliest date) for the rare multi-cohort case.
        //
        $hasTpUserseq  = $dbman->table_exists('block_trainingplan_userseq');
        $hasTpSchedule = $dbman->table_exists('block_trainingplan_schedule');

        if ($hasTpUserseq && $hasTpSchedule) {
            $tpDueSql = "COALESCE(tp_us.user_enddate, tp_sch.sched_enddate, NULLIF(a.duedate, 0), 0) AS due_date";
            $tpDueJoinSql = "
                -- Training plan: per-student override (manualoverride = 1 only)
                LEFT JOIN (
                    SELECT   tpu.userid,
                             tpu.courseid,
                             MIN(CASE WHEN tpu.manualoverride = 1 AND tpu.enddate > 0
                                      THEN tpu.enddate END)                    AS user_enddate
                    FROM     {block_trainingplan_userseq} tpu
                    GROUP BY tpu.userid, tpu.courseid
                ) tp_us ON tp_us.userid = u.id AND tp_us.courseid = c.id
                -- Training plan: cohort-level schedule (across all student cohorts)
                LEFT JOIN (
                    SELECT   cm3.userid,
                             tps.courseid,
                             MIN(CASE WHEN tps.enddate > 0
                                      THEN tps.enddate END)                    AS sched_enddate
                    FROM     {block_trainingplan_schedule} tps
                    JOIN     {cohort_members} cm3 ON cm3.cohortid = tps.cohortid
                    GROUP BY cm3.userid, tps.courseid
                ) tp_sch ON tp_sch.userid = u.id AND tp_sch.courseid = c.id";

        } elseif ($hasTpUserseq) {
            $tpDueSql = "COALESCE(tp_us.user_enddate, NULLIF(a.duedate, 0), 0) AS due_date";
            $tpDueJoinSql = "
                LEFT JOIN (
                    SELECT   tpu.userid,
                             tpu.courseid,
                             MIN(CASE WHEN tpu.manualoverride = 1 AND tpu.enddate > 0
                                      THEN tpu.enddate END)                    AS user_enddate
                    FROM     {block_trainingplan_userseq} tpu
                    GROUP BY tpu.userid, tpu.courseid
                ) tp_us ON tp_us.userid = u.id AND tp_us.courseid = c.id";

        } elseif ($hasTpSchedule) {
            $tpDueSql = "COALESCE(tp_sch.sched_enddate, NULLIF(a.duedate, 0), 0) AS due_date";
            $tpDueJoinSql = "
                LEFT JOIN (
                    SELECT   cm3.userid,
                             tps.courseid,
                             MIN(CASE WHEN tps.enddate > 0
                                      THEN tps.enddate END)                    AS sched_enddate
                    FROM     {block_trainingplan_schedule} tps
                    JOIN     {cohort_members} cm3 ON cm3.cohortid = tps.cohortid
                    GROUP BY cm3.userid, tps.courseid
                ) tp_sch ON tp_sch.userid = u.id AND tp_sch.courseid = c.id";

        } else {
            // No training plan tables — use Moodle native duedate.
            $tpDueSql     = "COALESCE(a.duedate, 0) AS due_date";
            $tpDueJoinSql = '';
        }

        // ── Final marking sheet: deemed competent date ─────────────────────────
        //
        // local_finalmarkingsheet stores the date the assessor marked a student
        // competent (deemedcompetentdate). One row per student × course.
        // The table is a custom plugin and may not exist on all sites; guard it.
        //
        if ($dbman->table_exists('local_finalmarkingsheet')) {
            $fmsSelectSql = "COALESCE(fms.deemedcompetentdate, 0) AS deemed_competent_date";
            $fmsJoinSql   = "LEFT JOIN {local_finalmarkingsheet} fms
                                ON fms.userid   = u.id
                               AND fms.courseid = c.id";
        } else {
            $fmsSelectSql = "0 AS deemed_competent_date";
            $fmsJoinSql   = '';
        }

        // ── Main query ────────────────────────────────────────────────────────
        //
        // Deduplication strategy (prevents Cartesian product row explosion):
        //
        //  • cohort_dedup  — pre-grouped subquery: one row per student, picks
        //                    the alphabetically first cohort name (MIN).
        //
        //  • g_dedup       — pre-grouped subquery: one row per (student, course),
        //                    picks the lowest group ID (MIN).
        //
        //  • trainer: resolved from wombat_trainer profile field (same as
        //    get_completed_units and get_trainer_allocations).  One row per
        //    student because the profile field holds exactly one trainer name.
        //
        $sql = "
            SELECT
                CONCAT(u.id, '_', cm.id)                                 AS recid,
                u.id                                                      AS userid,
                CONCAT(u.firstname, ' ', u.lastname)                     AS fullname,
                u.email,
                c.id                                                      AS courseid,
                c.shortname                                               AS course_shortname,
                c.fullname                                                AS course_fullname,
                COALESCE(coh_dedup.cohort_name, '')                      AS cohort_name,
                COALESCE(g.name, '')                                     AS group_name,
                cm.id                                                     AS cm_id,
                a.name                                                    AS assessment_name,
                $tpDueSql,
                COALESCE(asub.status, 'new')                             AS submission_status,
                COALESCE(asub.timemodified, 0)                           AS submitted_date,
                COALESCE(agr.timemodified, 0)                            AS graded_date,
                agr.grade                                                 AS grade_value,
                COALESCE(trainer.id, 0)                                  AS trainer_id,
                COALESCE(CONCAT(trainer.firstname, ' ', trainer.lastname), '') AS trainer_name,
                COALESCE(trainer.email, '')                              AS trainer_email,
                $fmsSelectSql
            FROM {assign} a
            JOIN {course_modules} cm  ON cm.instance = a.id AND cm.module = :assign_module_id
            JOIN {course}          c  ON c.id = a.course AND c.id != 1 AND c.visible = 1
            JOIN {user_enrolments} ue ON ue.status = 0
                 AND (ue.timeend = 0 OR ue.timeend > :now)
            JOIN {enrol}           en ON en.id = ue.enrolid AND en.courseid = c.id
            JOIN {context}        ctx ON ctx.contextlevel = 50 AND ctx.instanceid = c.id
            JOIN {role_assignments} ra ON ra.userid = ue.userid AND ra.contextid = ctx.id
            JOIN {role}            ro ON ro.id = ra.roleid AND ro.shortname = 'student'
            JOIN {user}             u ON u.id = ue.userid
                                     AND u.deleted = 0 AND u.suspended = 0
                                     AND u.id != 1 AND u.username != 'guest'
            -- Submission record
            LEFT JOIN {assign_submission} asub
                ON asub.assignment = a.id AND asub.userid = u.id AND asub.latest = 1
            -- Grade record
            LEFT JOIN {assign_grades} agr
                ON agr.assignment = a.id AND agr.userid = u.id
                AND agr.grade IS NOT NULL AND agr.grade >= 0
            -- Cohort: one row per student, alphabetically first name
            LEFT JOIN (
                SELECT   cm2.userid,
                         MIN(coh2.name) AS cohort_name
                FROM     {cohort_members} cm2
                JOIN     {cohort} coh2 ON coh2.id = cm2.cohortid
                GROUP BY cm2.userid
            ) coh_dedup ON coh_dedup.userid = u.id
            -- Group: one row per (student, course), lowest group ID
            LEFT JOIN (
                SELECT   gm2.userid,
                         g2.courseid,
                         MIN(g2.id) AS group_id
                FROM     {groups_members} gm2
                JOIN     {groups} g2 ON g2.id = gm2.groupid
                GROUP BY gm2.userid, g2.courseid
            ) g_dedup ON g_dedup.userid = u.id AND g_dedup.courseid = c.id
            LEFT JOIN {groups} g ON g.id = g_dedup.group_id
            -- Trainer from wombat_trainer profile field (consistent with
            -- get_completed_units and get_trainer_allocations)
            LEFT JOIN {user_info_data} uid_tr
                ON uid_tr.userid  = u.id
               AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
                ON  trainer.deleted   = 0
                AND trainer.suspended = 0
                AND LOWER(TRIM(CONCAT(trainer.firstname, ' ', trainer.lastname)))
                  = LOWER(TRIM(uid_tr.data))
            -- Training plan due date JOINs (may be empty strings if tables absent)
            $tpDueJoinSql
            -- Final marking sheet
            $fmsJoinSql
            $cohortJoin
            WHERE cm.deletioninprogress = 0
              AND ($require_completion = 0 OR cm.completion > 0)
              $lookbackWhere
              $trainerWhere
            ORDER BY a.duedate ASC, u.lastname, u.firstname
        ";

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            // Map raw submission status to VET vocabulary.
            $status = 'NYS';
            if ($row->submission_status === 'draft') {
                $status = 'IP';
            } elseif ($row->submission_status === 'submitted' && ($row->grade_value === null || $row->grade_value < 0)) {
                $status = 'Submitted';
            } elseif ($row->submission_status === 'submitted' && $row->grade_value !== null && $row->grade_value >= 0) {
                $status = 'Graded';
            }

            // Calculate overdue figures.
            $dueDate     = (int) $row->due_date;
            $submittedAt = (int) $row->submitted_date;
            $gradedAt    = (int) $row->graded_date;

            $daysOverdue        = 0;
            $daysGradingOverdue = 0;

            if ($dueDate > 0 && $dueDate < $now && $status !== 'Graded') {
                $daysOverdue = max(0, (int) floor(($now - $dueDate) / DAYSECS));
            }
            if ($status === 'Submitted' && $submittedAt > 0) {
                $daysSinceSubmit = (int) floor(($now - $submittedAt) / DAYSECS);
                if ($daysSinceSubmit > 4) {
                    $daysGradingOverdue = $daysSinceSubmit;
                }
            }

            // Apply status filter.
            if ($status_filter !== '') {
                if ($status_filter === 'overdue_submission' && $daysOverdue <= 0) continue;
                if ($status_filter === 'overdue_grading' && $daysGradingOverdue <= 0) continue;
                if (in_array($status_filter, ['NYS', 'IP', 'Submitted', 'Graded']) && $status !== $status_filter) continue;
            }

            $result[] = [
                'userid'                => (int)    $row->userid,
                'fullname'              => (string) $row->fullname,
                'email'                 => (string) $row->email,
                'courseid'              => (int)    $row->courseid,
                'course_shortname'      => (string) $row->course_shortname,
                'course_fullname'       => (string) $row->course_fullname,
                'cohort_name'           => (string) $row->cohort_name,
                'group_name'            => (string) $row->group_name,
                'cm_id'                 => (int)    $row->cm_id,
                'assessment_name'       => (string) $row->assessment_name,
                'status'                => $status,
                'due_date'              => (int)    $dueDate,
                'submitted_date'        => (int)    $submittedAt,
                'graded_date'           => (int)    $gradedAt,
                'days_overdue'          => $daysOverdue,
                'days_grading_overdue'  => $daysGradingOverdue,
                'trainer_id'            => (int)    $row->trainer_id,
                'trainer_name'          => (string) $row->trainer_name,
                'trainer_email'         => (string) $row->trainer_email,
                'deemed_competent_date' => (int)    $row->deemed_competent_date,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'                => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'              => new \external_value(PARAM_TEXT, 'Student full name'),
                'email'                 => new \external_value(PARAM_TEXT, 'Student email'),
                'courseid'              => new \external_value(PARAM_INT,  'Course ID'),
                'course_shortname'      => new \external_value(PARAM_TEXT, 'Course short name (VET unit code)'),
                'course_fullname'       => new \external_value(PARAM_TEXT, 'Course full name (VET unit name)'),
                'cohort_name'           => new \external_value(PARAM_TEXT, 'Cohort name'),
                'group_name'            => new \external_value(PARAM_TEXT, 'Group name'),
                'cm_id'                 => new \external_value(PARAM_INT,  'Course module ID'),
                'assessment_name'       => new \external_value(PARAM_TEXT, 'Assignment name'),
                'status'                => new \external_value(PARAM_TEXT, 'VET status: NYS, IP, Submitted, or Graded'),
                'due_date'              => new \external_value(PARAM_INT,  'Training plan due date (unix timestamp; falls back to assignment duedate; 0 = none)'),
                'submitted_date'        => new \external_value(PARAM_INT,  'Date student submitted (unix timestamp, 0 = not submitted)'),
                'graded_date'           => new \external_value(PARAM_INT,  'Date graded (unix timestamp, 0 = not graded)'),
                'days_overdue'          => new \external_value(PARAM_INT,  'Days past due date (0 = not overdue)'),
                'days_grading_overdue'  => new \external_value(PARAM_INT,  'Days since submission without grading (0 = not applicable)'),
                'trainer_id'            => new \external_value(PARAM_INT,  'Allocated trainer user ID from wombat_trainer profile field (0 = unassigned)'),
                'trainer_name'          => new \external_value(PARAM_TEXT, 'Allocated trainer full name'),
                'trainer_email'         => new \external_value(PARAM_TEXT, 'Allocated trainer email address'),
                'deemed_competent_date' => new \external_value(PARAM_INT,  'Date assessor marked student competent (local_finalmarkingsheet.deemedcompetentdate; 0 = not marked or table absent)'),
            ])
        );
    }
}
