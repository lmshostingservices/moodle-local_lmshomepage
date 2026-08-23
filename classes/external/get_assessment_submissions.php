<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// You can redistribute it under the terms of the GNU General Public License as published by
// The Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// But WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// Along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

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
 * the 'trainername' custom profile field ("Your Trainer Is"), consistent
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
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_assessment_submissions extends \external_api {
    /**
     * Read newline-separated exclusion patterns from plugin settings.
     *
     * The wildcard contract intentionally only supports *; it is converted to
     * SQL's multi-character wildcard below. No wildcard is added implicitly,
     * so a pattern without * matches the complete username/course name.
     */
    private static function configured_patterns(string $settingname): array {
        $raw = (string) get_config('local_lmshomepage', $settingname);
        $lines = preg_split('/\R/', $raw);
        if ($lines === false) {
            return [];
        }

        $patterns = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $patterns[] = $line;
            }
        }
        return $patterns;
    }

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

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'cohort_id'          => $cohort_id,
            'trainer_userid'     => $trainer_userid,
            'status_filter'      => $status_filter,
            'require_completion' => $require_completion,
            'days_lookback'      => $days_lookback,
        ]);

        $now    = time();
        $params = ['now' => $now];
        $dbman  = $DB->get_manager();

        // These are deliberately SQL predicates, not post-processing filters.
        // Both the report and dashboard KPI call this same external function,
        // So rows and totals cannot disagree about the excluded population.
        $accountExclusions = [];
        foreach (self::configured_patterns('excluded_user_patterns') as $index => $pattern) {
            $param = 'excluded_username_' . $index;
            $params[$param] = mb_strtolower(str_replace('*', '%', $DB->sql_like_escape($pattern)));
            $accountExclusions[] = 'NOT (' . $DB->sql_like('LOWER(u.username)', ':' . $param, true) . ')';
        }
        $courseExclusions = [];
        foreach (self::configured_patterns('excluded_course_patterns') as $index => $pattern) {
            $param = 'excluded_course_name_' . $index;
            $params[$param] = mb_strtolower(str_replace('*', '%', $DB->sql_like_escape($pattern)));
            $courseExclusions[] = 'NOT (' . $DB->sql_like('LOWER(c.fullname)', ':' . $param, true) . ')';
        }
        $exclusionWhere = '';
        if ($accountExclusions) {
            $exclusionWhere .= "\n              AND " . implode("\n              AND ", $accountExclusions);
        }
        if ($courseExclusions) {
            $exclusionWhere .= "\n              AND " . implode("\n              AND ", $courseExclusions);
        }

        // ── Optional cohort filter ────────────────────────────────────────────
        $cohortJoin  = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortJoin  = "JOIN {cohort_members} cm_flt ON cm_flt.userid = u.id AND cm_flt.cohortid = :cohort_id";
        }

        // ── Resolve trainername profile field ID ───────────────────────────
        $trainerFieldId = (int) $DB->get_field('user_info_field', 'id', ['shortname' => 'trainername']);
        $params['tf_id'] = $trainerFieldId > 0 ? $trainerFieldId : -1;

        // DB-portable name concatenation (handles NULL coalescing correctly on all DB engines).
        $sqlFullname        = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $sqlTrainerFullname = $DB->sql_concat('trainer.firstname', "' '", 'trainer.lastname');

        // Resolve every trainername value once, outside the report query. The
        // Database deliberately performs the collation-aware LOWER/TRIM match;
        // PHP only maps the already-resolved profile-row IDs back to output rows.
        //
        // A profile value can match more than one active user with the same full
        // Name. Keep every match: the v2.11.57 JOIN emitted one row for each, and
        // The A/B CLI explicitly reports any resulting order ambiguity.
        $trainerMatchesByProfileId = [];
        if ($trainerFieldId > 0) {
            $trainerResolveSql = "
                SELECT uid_tr.id AS trainer_profile_id,
                       trainer.id AS trainer_id,
                       {$sqlTrainerFullname} AS trainer_name,
                       COALESCE(trainer.email, '') AS trainer_email
                FROM {user_info_data} uid_tr
                LEFT JOIN {user} trainer
                  ON trainer.deleted = 0
                 AND trainer.suspended = 0
                 AND LOWER(TRIM({$sqlTrainerFullname})) = LOWER(TRIM(uid_tr.data))
                WHERE uid_tr.fieldid = :tf_resolve_id
                ORDER BY uid_tr.id, trainer.id
            ";
            $trainerRecordset = $DB->get_recordset_sql($trainerResolveSql, [
                'tf_resolve_id' => $trainerFieldId,
            ]);
            foreach ($trainerRecordset as $trainerMatch) {
                $profileId = (int) $trainerMatch->trainer_profile_id;
                $trainerMatchesByProfileId[$profileId][] = [
                    'trainer_id' => $trainerMatch->trainer_id === null ? 0 : (int) $trainerMatch->trainer_id,
                    'trainer_name' => $trainerMatch->trainer_name,
                    'trainer_email' => (string) $trainerMatch->trainer_email,
                ];
            }
            $trainerRecordset->close();
        }

        // Resolve trainer-allocation pseudo-group labels once. The actual
        // Equality test stays in the main SQL (not PHP) so MariaDB's configured
        // Utf8mb4_unicode_ci semantics remain authoritative.
        $pseudoGroupPredicates = [];
        if ($trainerFieldId > 0) {
            $pseudoGroupLabels = $DB->get_fieldset_sql(
                "SELECT DISTINCT data
                   FROM {user_info_data}
                  WHERE fieldid = :tf_group_resolve_id
                    AND TRIM(data) != ''",
                ['tf_group_resolve_id' => $trainerFieldId]
            );
            foreach ($pseudoGroupLabels as $index => $label) {
                $param = 'pseudo_group_label_' . $index;
                $params[$param] = $label;
                $pseudoGroupPredicates[] = 'LOWER(TRIM(g2.name)) <> LOWER(TRIM(:' . $param . '))';
            }
        }
        $pseudoGroupWhere = '';
        if ($pseudoGroupPredicates) {
            // The NULL branch retains NOT EXISTS semantics if a non-standard
            // Moodle installation permits a group with a NULL name.
            $pseudoGroupWhere = "\n                  AND (g2.name IS NULL OR ("
                . implode("\n                       AND ", $pseudoGroupPredicates) . '))';
        }

        // ── Optional trainer filter ───────────────────────────────────────────
        $trainerWhere = '';
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            // Primary-key lookup on the requested trainer keeps this filter in
            // SQL without reintroducing the transformed full-table trainer JOIN.
            $trainerWhere = "AND EXISTS (
                SELECT 1
                  FROM {user} trainer_filter
                 WHERE trainer_filter.id = :trainer_userid
                   AND trainer_filter.deleted = 0
                   AND trainer_filter.suspended = 0
                   AND LOWER(TRIM(" . $DB->sql_concat('trainer_filter.firstname', "' '", 'trainer_filter.lastname') . "))
                     = LOWER(TRIM(uid_tr.data))
            )";
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
        // When a cohort filter is supplied, both plan sources are constrained to
        // That cohort. This prevents a second/historical cohort from supplying a
        // Due date and creating a false overdue result in the selected cohort.
        //
        $hasTpUserseq  = $dbman->table_exists('block_trainingplan_userseq');
        $hasTpSchedule = $dbman->table_exists('block_trainingplan_schedule');
        $tpUserseqWhere = '';
        $tpScheduleWhere = '';
        if ($cohort_id > 0) {
            $params['tp_userseq_cohort_id'] = $cohort_id;
            $params['tp_schedule_cohort_id'] = $cohort_id;
            $tpUserseqWhere = 'AND tpu.cohortid = :tp_userseq_cohort_id';
            $tpScheduleWhere = 'AND tps.cohortid = :tp_schedule_cohort_id';
        }

        // Calculate plan dates for only the student/course row now being
        // Reported. These predicates line up with the existing Training Plan
        // Composite keys instead of aggregating every plan row first.
        $tpUserseqSelect = 'NULL AS tp_user_enddate';
        if ($hasTpUserseq) {
            $tpUserseqSelect = "(
                SELECT MIN(CASE WHEN tpu.enddate > 0 THEN tpu.enddate END)
                  FROM {block_trainingplan_userseq} tpu
                 WHERE tpu.userid = u.id
                   AND tpu.courseid = c.id
                   $tpUserseqWhere
            ) AS tp_user_enddate";
        }
        $tpScheduleSelect = 'NULL AS tp_schedule_enddate';
        if ($hasTpSchedule) {
            $tpScheduleSelect = "(
                SELECT MIN(CASE WHEN tps.enddate > 0 THEN tps.enddate END)
                  FROM {cohort_members} cm3
                  JOIN {block_trainingplan_schedule} tps
                    ON tps.cohortid = cm3.cohortid
                   AND tps.courseid = c.id
                 WHERE cm3.userid = u.id
                   $tpScheduleWhere
            ) AS tp_schedule_enddate";
        }
        $tpDueValueSql = "COALESCE(source.tp_user_enddate, source.tp_schedule_enddate, NULLIF(source.native_due_date, 0), 0)";
        $tpDueSql = "$tpDueValueSql AS due_date,
                     CASE WHEN (source.tp_user_enddate IS NOT NULL OR source.tp_schedule_enddate IS NOT NULL)
                          THEN 1 ELSE 0 END AS has_training_plan";

        // Apply the report window to the same resolved due date displayed and
        // Used for overdue calculations, not the stale native assignment date.
        $lookbackWhere = '';
        if ($days_lookback > 0) {
            $params['cutoff_ts'] = $now - ($days_lookback * DAYSECS);
            $lookbackWhere = "WHERE ($tpDueValueSql = 0 OR $tpDueValueSql > :cutoff_ts)";
        }

        // When training plan tables are present, exclude accounts that have no
        // Cohort and no training plan.  These are staff/admin accounts that were
        // Enrolled as students for content-building or testing — they have
        // Student role assignments so the SQL student-role filter passes, but
        // They never appear in a real student cohort and have no TP due dates.
        // Guard on table existence: without TP tables has_training_plan is always
        // 0, so the condition would incorrectly exclude genuine uncohorted students
        // On sites that don't use the training plan block.
        $excludeNoCohortNoTP = ($hasTpUserseq || $hasTpSchedule);

        // ── Final marking sheet: deemed competent date ─────────────────────────
        //
        // Local_finalmarkingsheet stores the date the assessor marked a student
        // Competent (deemedcompetentdate). One row per student × course.
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
        //                    The alphabetically first cohort name (MIN).
        //
        //  • g_dedup       — pre-grouped subquery: one row per (student, course),
        //                    Picks the lowest group ID (MIN).
        //
        //  • trainer: resolved from trainername profile field (same as
        //    Get_completed_units and get_trainer_allocations).  One row per
        //    Student because the profile field holds exactly one trainer name.
        //
        $sql = "
            SELECT
                source.userid,
                source.fullname,
                source.email,
                source.courseid,
                source.course_shortname,
                source.course_fullname,
                source.cohort_name,
                source.group_name,
                source.cm_id,
                source.assessment_name,
                $tpDueSql,
                source.submission_status,
                source.submitted_date,
                source.graded_date,
                source.grade_value,
                source.trainer_profile_id,
                source.trainer_label,
                source.deemed_competent_date
            FROM (
                SELECT
                    u.id                                                      AS userid,
                    {$sqlFullname}                                            AS fullname,
                    u.email,
                    c.id                                                      AS courseid,
                    c.shortname                                               AS course_shortname,
                    c.fullname                                                AS course_fullname,
                    COALESCE(coh_dedup.cohort_name, '')                      AS cohort_name,
                    COALESCE(g.name, '')                                     AS group_name,
                    cm.id                                                     AS cm_id,
                    a.name                                                    AS assessment_name,
                    a.duedate                                                 AS native_due_date,
                    $tpUserseqSelect,
                    $tpScheduleSelect,
                    COALESCE(asub.status, 'new')                             AS submission_status,
                    COALESCE(asub.timemodified, 0)                           AS submitted_date,
                    COALESCE(agr.timemodified, 0)                            AS graded_date,
                    agr.grade                                                 AS grade_value,
                    uid_tr.id                                                 AS trainer_profile_id,
                    COALESCE(NULLIF(TRIM(uid_tr.data), ''), '')              AS trainer_label,
                    $fmsSelectSql,
                    u.lastname                                                AS sort_lastname,
                    u.firstname                                               AS sort_firstname
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
                -- Grade record. Moodle keeps one assign_grades row per grading
                -- ATTEMPT, not one per student — a student graded more than once
                -- on the same assignment (regrade, resubmission after marking,
                -- etc.) has multiple rows here. Without a dedup constraint this
                -- LEFT JOIN multiplies the whole result row for every extra grade
                -- record. Constrain to the single latest qualifying grade row per
                -- (assignment, student) so each assessment produces one row.
                LEFT JOIN {assign_grades} agr
                    ON agr.assignment = a.id AND agr.userid = u.id
                    AND agr.grade IS NOT NULL AND agr.grade >= 0
                    AND agr.id = (
                        SELECT MAX(agr2.id)
                        FROM   {assign_grades} agr2
                        WHERE  agr2.assignment = a.id
                           AND agr2.userid     = u.id
                           AND agr2.grade IS NOT NULL AND agr2.grade >= 0
                    )
                -- Cohort: one row per student, alphabetically first name
                LEFT JOIN (
                    SELECT   cm2.userid,
                             MIN(coh2.name) AS cohort_name
                    FROM     {cohort_members} cm2
                    JOIN     {cohort} coh2 ON coh2.id = cm2.cohortid
                    GROUP BY cm2.userid
                ) coh_dedup ON coh_dedup.userid = u.id
                -- Group: one row per (student, course), lowest group ID.
                -- Pseudo-groups whose names equal trainername values are excluded.
                LEFT JOIN (
                    SELECT   gm2.userid,
                             g2.courseid,
                             MIN(g2.id) AS group_id
                    FROM     {groups_members} gm2
                    JOIN     {groups} g2 ON g2.id = gm2.groupid
                    WHERE    1 = 1
                    $pseudoGroupWhere
                    GROUP BY gm2.userid, g2.courseid
                ) g_dedup ON g_dedup.userid = u.id AND g_dedup.courseid = c.id
                LEFT JOIN {groups} g ON g.id = g_dedup.group_id
                -- Retain the profile join; it is indexed by user and field and
                -- feeds the one-shot SQL trainer resolver above.
                LEFT JOIN {user_info_data} uid_tr
                    ON uid_tr.userid  = u.id
                   AND uid_tr.fieldid = :tf_id
                -- Final marking sheet
                $fmsJoinSql
                $cohortJoin
                WHERE cm.deletioninprogress = 0
                  AND ($require_completion = 0 OR cm.completion > 0)
                  $exclusionWhere
                  $trainerWhere
            ) source
            $lookbackWhere
            ORDER BY $tpDueValueSql ASC, source.sort_lastname, source.sort_firstname
        ";

        $rows = $DB->get_recordset_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            // Exclude staff/admin accounts enrolled as students for testing:
            // No cohort + no training plan = not a genuine student enrolment.
            // Only applied when TP tables exist (see $excludeNoCohortNoTP above).
            if ($excludeNoCohortNoTP
                && (string) $row->cohort_name === ''
                && (int) ($row->has_training_plan ?? 0) === 0) {
                continue;
            }

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

            // Marking report noise reduction: in the default (unfiltered) view, omit rows for
            // Students who simply have not started the unit — NYS with no submission and
            // Nothing past due. There is nothing to mark or chase, and these rows were the
            // Bulk of the reported 4,164-record CSV (e.g. Macy's HLTWHS001 with no submission).
            // Explicit status filters (incl. overdue_submission) are unaffected.
            if ($status_filter === '' && $status === 'NYS' && $submittedAt === 0 && $daysOverdue <= 0) {
                continue;
            }

            $profileId = (int) ($row->trainer_profile_id ?? 0);
            $trainerMatches = $trainerMatchesByProfileId[$profileId] ?? [[
                'trainer_id' => 0,
                'trainer_name' => null,
                'trainer_email' => '',
            ]];

            // Preserve v2.11.57 JOIN multiplicity if duplicate active Moodle
            // Users have the same collation-equal full name.
            foreach ($trainerMatches as $trainerMatch) {
                $trainerName = $trainerMatch['trainer_name'];
                if ($trainerName === null) {
                    $trainerName = (string) $row->trainer_label;
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
                    'has_training_plan'     => (int)    ($row->has_training_plan ?? 0),
                    'trainer_id'            => (int)    $trainerMatch['trainer_id'],
                    'trainer_name'          => (string) $trainerName,
                    'trainer_email'         => (string) $trainerMatch['trainer_email'],
                    'deemed_competent_date' => (int)    $row->deemed_competent_date,
                ];
            }
        }

        $rows->close();
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
                'due_date'              => new \external_value(PARAM_INT,  'Training plan due date (unix timestamp, falls back to assignment duedate, 0 = none)'),
                'submitted_date'        => new \external_value(PARAM_INT,  'Date student submitted (unix timestamp, 0 = not submitted)'),
                'graded_date'           => new \external_value(PARAM_INT,  'Date graded (unix timestamp, 0 = not graded)'),
                'days_overdue'          => new \external_value(PARAM_INT,  'Days past due date (0 = not overdue)'),
                'days_grading_overdue'  => new \external_value(PARAM_INT,  'Days since submission without grading (0 = not applicable)'),
                'has_training_plan'     => new \external_value(PARAM_INT,  '1 if the student has a training plan due date for this course, 0 if due date falls back to native Moodle assignment due date only'),
                'trainer_id'            => new \external_value(PARAM_INT,  'Allocated trainer user ID from trainername profile field (0 = unassigned)'),
                'trainer_name'          => new \external_value(PARAM_TEXT, 'Allocated trainer full name'),
                'trainer_email'         => new \external_value(PARAM_TEXT, 'Allocated trainer email address'),
                'deemed_competent_date' => new \external_value(PARAM_INT,  'Date assessor marked student competent (local_finalmarkingsheet.deemedcompetentdate; 0 = not marked or table absent)'),
            ])
        );
    }
}
