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
 * External function: local_lmshomepage_get_cohort_matrix
 *
 * Returns a flat grid of course × student completion outcomes for a cohort.
 * Each row represents one student's progress on one VET unit (Moodle course).
 * The calling application assembles this into the matrix view.
 *
 * Granularity: one row per student per COURSE (not per activity).
 * This gives the overall unit-level view the client needs — each column
 * in the rendered matrix = one VET unit, each cell = outcome for that student.
 *
 * VET outcome codes returned:
 *   C   — Competent (course_completions.timecompleted IS NOT NULL)
 *   IP  — In Progress (enrolled, at least one tracked activity completed)
 *   NYS — Not Yet Started (enrolled, no activity progress recorded)
 *   RPL — Recognition of Prior Learning (from training plan)
 *   CT  — Credit Transfer (from training plan)
 *   NA  — Not Applicable (from training plan; stored as 'NA' or 'N/A')
 *
 * Outcome priority (highest wins): RPL → CT → NA → C → IP → NYS
 *
 * Training plan outcomes (RPL / CT / NA) require block_trainingplan_userseq
 * to exist on the Moodle site. Absent = all outcomes C / IP / NYS only.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_cohort_matrix
 *   cohort_id=5     (int, required)
 *   course_id=0     (int, optional — 0 = all courses for this cohort)
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_cohort_matrix extends \external_api {
    public static function execute_parameters (): \external_function_parameters {
        return new \external_function_parameters([
            'cohort_id' => new \external_value(
                PARAM_INT,
                'Cohort ID to build matrix for (required — pass 0 to get all cohorts).',
                VALUE_REQUIRED
            ),
            'course_id' => new \external_value(
                PARAM_INT,
                'Filter to a single course ID. 0 = all courses this cohort is enrolled in.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute (int $cohort_id, int $course_id = 0): array {
        global $DB;

        $now    = time();
        $params = ['now_ts' => $now];

        $cohortWhere = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortWhere = 'AND cm_mem.cohortid = :cohort_id';
        }

        $courseWhere = '';
        if ($course_id > 0) {
            $params['course_id'] = $course_id;
            $courseWhere = 'AND c.id = :course_id';
        }

        // ── Training plan table guard ─────────────────────────────────────────
        // block_trainingplan_userseq is a custom block not present on all sites.
        // If the table does not exist, substitute literal 0s so the query runs
        // safely on Signature, TDT, AIOP etc. without SQL errors.
        //
        // The join key is (userid, cohortid, courseid) — cohortid IS included to scope
        // outcomes to the specific cohort being rendered. Without it, a student enrolled
        // under two cohorts could show cohort B's RPL/CT/NA outcome in cohort A's matrix
        // (a compliance problem). The subquery joins back to coh.id from the outer query.
        //
        // Outcome values in the CASE WHEN accept both 'NA' and 'N/A' so sites
        // that store either variant are handled correctly.
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('block_trainingplan_userseq')) {
            $tpSelectSql = "
                COALESCE(tp.tp_total, 0)                                 AS tp_total,
                COALESCE(tp.tp_rpl,   0)                                 AS tp_rpl,
                COALESCE(tp.tp_ct,    0)                                 AS tp_ct,
                COALESCE(tp.tp_na,    0)                                 AS tp_na,
                COALESCE(tp.tp_done,  0)                                 AS tp_done";
            // Include cohortid in GROUP BY so a student enrolled under two cohorts
            // never bleeds cohort A's outcome into cohort B's matrix.
            // Always join on tp.cohortid = coh.id (coh is always present in the outer query).
            $tpJoinSql = "
                LEFT JOIN (
                    SELECT   userid, cohortid, courseid,
                             COUNT(*)                                                               AS tp_total,
                             SUM(CASE WHEN outcome = 'RPL'                        THEN 1 ELSE 0 END) AS tp_rpl,
                             SUM(CASE WHEN outcome = 'CT'                         THEN 1 ELSE 0 END) AS tp_ct,
                             SUM(CASE WHEN outcome IN ('NA','N/A')                THEN 1 ELSE 0 END) AS tp_na,
                             SUM(CASE WHEN outcome IN ('C','RPL','CT','NA','N/A') THEN 1 ELSE 0 END) AS tp_done
                    FROM     {block_trainingplan_userseq}
                    GROUP BY userid, cohortid, courseid
                ) tp ON tp.userid = u.id AND tp.courseid = c.id AND tp.cohortid = coh.id";
        } else {
            $tpSelectSql = "0 AS tp_total, 0 AS tp_rpl, 0 AS tp_ct, 0 AS tp_na, 0 AS tp_done";
            $tpJoinSql   = '';
        }

        // ── Main query: one row per student per enrolled course ───────────────
        // Uses an enrolment deduplication subquery so students with multiple
        // active enrolments in the same course produce exactly one row.
        //
        // Outcome priority: RPL → CT → NA → C → IP → NYS
        // For training plan outcomes, ANY matching row triggers the outcome
        // (tp_rpl > 0, tp_ct > 0, tp_na > 0) rather than requiring ALL rows
        // to match — this is more correct for typical single-row plans and
        // prevents outcomes disappearing when a plan has mixed entries.
        $sql = "
            SELECT
                CONCAT(u.id, '_', c.id)                                  AS recid,
                u.id                                                      AS userid,
                CONCAT(u.firstname, ' ', u.lastname)                     AS fullname,
                COALESCE(coh.name, '')                                   AS cohort_name,
                c.id                                                      AS courseid,
                c.shortname                                               AS course_shortname,
                c.id                                                      AS cm_id,
                c.shortname                                               AS unit_code,
                COALESCE(NULLIF(c.fullname, ''), c.shortname)            AS unit_name,
                COALESCE(cc.timecompleted, 0)                            AS timecompleted,
                (
                    SELECT COUNT(*)
                    FROM   {course_modules_completion} cmc2
                    WHERE  cmc2.userid = u.id
                      AND  cmc2.completionstate >= 1
                      AND  cmc2.coursemoduleid IN (
                               SELECT id FROM {course_modules}
                               WHERE course = c.id AND completion > 0
                           )
                )                                                         AS items_complete,
                (
                    SELECT COUNT(*)
                    FROM   {course_modules}
                    WHERE  course = c.id AND completion > 0
                )                                                         AS items_total,
                $tpSelectSql
            FROM {cohort_members} cm_mem
            JOIN {cohort} coh ON coh.id = cm_mem.cohortid $cohortWhere
            JOIN {user}   u   ON u.id = cm_mem.userid
                             AND u.deleted = 0 AND u.suspended = 0
                             AND u.id != 1 AND u.username != 'guest'
            JOIN {enrol}  en_cs ON en_cs.enrol = 'cohort'
                              AND en_cs.customint1 = coh.id
            JOIN {course} c     ON c.id = en_cs.courseid
                              AND c.id != 1 AND c.visible = 1
                              $courseWhere
            JOIN (
                SELECT DISTINCT ue2.userid, en2.courseid
                FROM   {user_enrolments} ue2
                JOIN   {enrol} en2 ON en2.id = ue2.enrolid
                WHERE  ue2.status = 0
                  AND  (ue2.timeend = 0 OR ue2.timeend > :now_ts)
            ) enrol_check ON enrol_check.userid = u.id
                         AND enrol_check.courseid = c.id
            LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = c.id
            $tpJoinSql
            ORDER BY coh.name, u.lastname, u.firstname, c.shortname
        ";

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            // Determine VET outcome.
            // Priority: RPL → CT → NA → C → IP → NYS
            // ANY matching training-plan row triggers the outcome
            // (tp_rpl > 0 means at least one RPL entry for this student-course).
            $tpTotal = (int)$row->tp_total;
            $tpRpl   = (int)$row->tp_rpl;
            $tpCt    = (int)$row->tp_ct;
            $tpNa    = (int)$row->tp_na;
            $tpDone  = (int)$row->tp_done;

            if ($tpRpl > 0) {
                $outcomeCode = 'RPL';
            } elseif ($tpCt > 0) {
                $outcomeCode = 'CT';
            } elseif ($tpNa > 0) {
                $outcomeCode = 'NA';
            } elseif ((int)$row->timecompleted > 0 || ($tpTotal > 0 && $tpDone === $tpTotal)) {
                $outcomeCode = 'C';
            } elseif ((int)$row->items_complete > 0 || $tpDone > 0) {
                $outcomeCode = 'IP';
            } else {
                $outcomeCode = 'NYS';
            }

            $result[] = [
                'userid'           => (int)    $row->userid,
                'fullname'         => (string) $row->fullname,
                'cohort_name'      => (string) $row->cohort_name,
                'courseid'         => (int)    $row->courseid,
                'course_shortname' => (string) $row->course_shortname,
                'cm_id'            => (int)    $row->cm_id,
                'unit_code'        => (string) $row->unit_code,
                'unit_name'        => (string) $row->unit_name,
                'outcome_code'     => $outcomeCode,
                'items_complete'   => (int)    $row->items_complete,
                'items_total'      => (int)    $row->items_total,
            ];
        }

        return $result;
    }

    public static function execute_returns (): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'           => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'         => new \external_value(PARAM_TEXT, 'Student full name'),
                'cohort_name'      => new \external_value(PARAM_TEXT, 'Cohort name'),
                'courseid'         => new \external_value(PARAM_INT,  'Course ID (= VET unit)'),
                'course_shortname' => new \external_value(PARAM_TEXT, 'Course short name (VET unit code)'),
                'cm_id'            => new \external_value(PARAM_INT,  'Course ID (used as unique cell key in matrix)'),
                'unit_code'        => new \external_value(PARAM_TEXT, 'VET unit code (= course shortname)'),
                'unit_name'        => new \external_value(PARAM_TEXT, 'Full VET unit name (course fullname, falls back to shortname)'),
                'outcome_code'     => new \external_value(PARAM_TEXT, 'VET outcome: C, IP, NYS, RPL, CT, or NA'),
                'items_complete'   => new \external_value(PARAM_INT,  'Number of completion-tracked activities completed in this course'),
                'items_total'      => new \external_value(PARAM_INT,  'Total completion-tracked activities in this course'),
            ])
        );
    }
}
