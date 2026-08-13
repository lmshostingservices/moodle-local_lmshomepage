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
 * External function: local_lmshomepage_get_vet_grade_report
 *
 * Returns one row per student × grade-item (assignment, quiz, etc.) for all
 * visible courses, with VET competency status derived from the Moodle grade
 * scale (C / NYC / RPL / CT) where a scale is used.
 *
 * When no scale is used (percentage grading), competency_status is derived
 * from the finalgrade: ≥ 50 → C, < 50 → NYC, -1 (no grade yet) → blank.
 *
 * Filters (all optional, 0 / '' = no filter):
 *   course_id       — single course
 *   student_userid  — single student
 *   group_id        — Moodle group (GD-1, GD-2 … GD-9)
 *   cohort_id       — Moodle cohort
 *   trainer_userid  — editingteacher/teacher user in the course context
 *   from_date       — Unix timestamp; include only records graded after this
 *   to_date         — Unix timestamp; include only records graded before this
 *
 * Returns:
 *   userid, fullname, student_idnumber,
 *   courseid, course_name, course_code, course_startdate, course_enddate,
 *   group_name, cohort_name,
 *   grade_item_id, unit_name, unit_code,
 *   assessment_name, due_date, submitted_date, graded_date,
 *   grade_percent, competency_status,   ← C / NYC / RPL / CT / ''
 *   completion_status,                  ← Complete / Incomplete / Not attempted
 *   trainer_id, trainer_name, trainer_email
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_vet_grade_report extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'course_id'      => new \external_value(PARAM_INT,  'Filter to a specific course ID. 0 = all.', VALUE_DEFAULT, 0),
            'student_userid' => new \external_value(PARAM_INT,  'Filter to a specific student user ID. 0 = all.', VALUE_DEFAULT, 0),
            'group_id'       => new \external_value(PARAM_INT,  'Filter to a Moodle group (e.g. GD-1). 0 = all.', VALUE_DEFAULT, 0),
            'cohort_id'      => new \external_value(PARAM_INT,  'Filter to a Moodle cohort. 0 = all.', VALUE_DEFAULT, 0),
            'trainer_userid' => new \external_value(PARAM_INT,  'Filter to a trainer user ID. 0 = all.', VALUE_DEFAULT, 0),
            'from_date'      => new \external_value(PARAM_INT,  'Unix timestamp lower bound for graded_date. 0 = no lower bound.', VALUE_DEFAULT, 0),
            'to_date'        => new \external_value(PARAM_INT,  'Unix timestamp upper bound for graded_date. 0 = no upper bound.', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(
        int $course_id      = 0,
        int $student_userid = 0,
        int $group_id       = 0,
        int $cohort_id      = 0,
        int $trainer_userid = 0,
        int $from_date      = 0,
        int $to_date        = 0
    ): array {
        global $DB;

        $now    = time();
        $params = ['now1' => $now, 'now2' => $now, 'itemtype_mod' => 'mod'];

        // ── Optional filters ──────────────────────────────────────────────────
        $where_course  = '';
        $where_student = '';
        $where_trainer = '';
        $where_from    = '';
        $where_to      = '';
        $join_group    = '';
        $join_cohort   = '';

        if ($course_id > 0) {
            $params['course_id'] = $course_id;
            $where_course = 'AND c.id = :course_id';
        }
        if ($student_userid > 0) {
            $params['student_userid'] = $student_userid;
            $where_student = 'AND u.id = :student_userid';
        }
        if ($group_id > 0) {
            $params['group_id'] = $group_id;
            $join_group  = 'JOIN {groups_members} gm_flt ON gm_flt.userid = u.id AND gm_flt.groupid = :group_id';
        }
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $join_cohort = 'JOIN {cohort_members} cm_flt ON cm_flt.userid = u.id AND cm_flt.cohortid = :cohort_id';
        }
        $t_map_filter     = '';
        $t_map_filter_crs = '';
        if ($trainer_userid > 0) {
            // Moodle's DBAL does not allow the same named parameter to appear twice
            // in one query (see :now1/:now2 pattern above).  We need two bindings
            // for the same value — one per subquery.
            $params['trainer_userid']  = $trainer_userid;
            $params['trainer_userid2'] = $trainer_userid;
            $t_map_filter     = 'AND u_t.id = :trainer_userid';   // used in t_map_grp
            $t_map_filter_crs = 'AND u_t.id = :trainer_userid2';  // used in t_map_crs
            $where_trainer = 'AND (t_map_grp.trainer_id IS NOT NULL OR t_map_crs.trainer_id IS NOT NULL)';
        }
        if ($from_date > 0) {
            $params['from_date'] = $from_date;
            $where_from = 'AND COALESCE(gg.timemodified, 0) >= :from_date';
        }
        if ($to_date > 0) {
            $params['to_date'] = $to_date;
            $where_to = 'AND COALESCE(gg.timemodified, 0) <= :to_date';
        }

        // ── Assignment module ID (for due/submitted dates) ─────────────────────
        $assignModId = (int) $DB->get_field('modules', 'id', ['name' => 'assign']);

        // ── Main query ────────────────────────────────────────────────────────
        // Trainer exclusion guard: exclude system/test accounts from trainer lookup.
        // - lastaccess = 0 → account has never logged in (test/placeholder accounts)
        // - firstname LIKE 'test%' (case-insensitive) → named "Test Teacher" etc.
        // - username IN ('admin','guest') → system accounts
        $trainer_exclusion = "AND u_t.lastaccess > 0
                              AND LOWER(u_t.firstname) NOT LIKE 'test%'
                              AND u_t.username NOT IN ('admin', 'guest')";

        $sql = "
            SELECT
                CONCAT(u.id, '_', gi.id)                                AS recid,
                u.id                                                     AS userid,
                CONCAT(u.firstname, ' ', u.lastname)                    AS fullname,
                COALESCE(u.idnumber, '')                                AS student_idnumber,
                c.id                                                     AS courseid,
                c.fullname                                               AS course_name,
                c.shortname                                              AS course_code,
                COALESCE(c.startdate, 0)                                AS course_startdate,
                COALESCE(c.enddate, 0)                                  AS course_enddate,
                COALESCE(grp_sub.group_name, '')                        AS group_name,
                COALESCE(coh_sub.cohort_name, '')                       AS cohort_name,
                gi.id                                                    AS grade_item_id,
                COALESCE(gi.itemname, c.fullname)                       AS unit_name,
                COALESCE(gi.idnumber, '')                               AS unit_code,
                COALESCE(cm.id, 0)                                      AS cm_id,
                COALESCE(gg.finalgrade, -1)                             AS finalgrade,
                COALESCE(gg.rawscaleid, 0)                              AS scaleid,
                COALESCE(sc.scale, '')                                  AS scale_values,
                gi.grademax                                              AS grademax,
                COALESCE(gg.timemodified, 0)                            AS graded_date,
                COALESCE(cmc.completionstate, 0)                        AS completionstate,
                COALESCE(trainer.id, 0)                                 AS trainer_id,
                COALESCE(CONCAT(trainer.firstname, ' ', trainer.lastname), '') AS trainer_name,
                COALESCE(trainer.email, '')                             AS trainer_email
            FROM   {grade_items} gi
            JOIN   {course} c  ON c.id = gi.courseid AND c.id != 1 AND c.visible = 1
            -- enrolled students
            JOIN   {user_enrolments} ue  ON ue.status = 0 AND (ue.timeend = 0 OR ue.timeend > :now1)
            JOIN   {enrol}            en  ON en.id = ue.enrolid AND en.courseid = c.id
            JOIN   {context}         ctx  ON ctx.contextlevel = 50 AND ctx.instanceid = c.id
            JOIN   {role_assignments}  ra  ON ra.userid = ue.userid AND ra.contextid = ctx.id
            JOIN   {role}              ro  ON ro.id = ra.roleid AND ro.shortname = 'student'
            JOIN   {user}               u  ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                                          AND u.id != 1 AND u.username != 'guest'
            -- grade record for this student
            LEFT JOIN {grade_grades}  gg  ON gg.itemid = gi.id AND gg.userid = u.id
            -- scale (if used)
            LEFT JOIN {scale}         sc  ON sc.id = gg.rawscaleid
            -- course module (needed for completion + activity dates)
            LEFT JOIN {course_modules} cm ON cm.course = c.id AND cm.instance = gi.iteminstance
                                          AND cm.module = (SELECT id FROM {modules} WHERE name = gi.itemmodule LIMIT 1)
                                          AND cm.deletioninprogress = 0
            -- activity completion
            LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = u.id
            -- group: one deterministic group per student×course (alphabetically first name)
            LEFT JOIN (
                SELECT   gm_sub.userid, g_sub.courseid,
                         MIN(g_sub.id)   AS groupid,
                         MIN(g_sub.name) AS group_name
                FROM     {groups_members} gm_sub
                JOIN     {groups}         g_sub ON g_sub.id = gm_sub.groupid
                GROUP BY gm_sub.userid, g_sub.courseid
            ) grp_sub ON grp_sub.userid = u.id AND grp_sub.courseid = c.id
            -- cohort: one deterministic cohort name per student (avoids Cartesian product)
            LEFT JOIN (
                SELECT   cm_sub.userid, MIN(coh_sub.name) AS cohort_name
                FROM     {cohort_members} cm_sub
                JOIN     {cohort} coh_sub ON coh_sub.id = cm_sub.cohortid
                GROUP BY cm_sub.userid
            ) coh_sub ON coh_sub.userid = u.id
            -- trainer: prefer trainer who is a member of the student's group in this course;
            -- fallback to lowest-ID editingteacher/teacher at course level.
            -- Excludes system/test accounts that have never logged in.
            LEFT JOIN (
                SELECT   ra_t.contextid,
                         gm_t.groupid,
                         MIN(u_t.id) AS trainer_id
                FROM     {role_assignments} ra_t
                JOIN     {role}  ro_t ON ro_t.id = ra_t.roleid
                                     AND ro_t.shortname IN ('editingteacher','teacher','trainer')
                JOIN     {user}   u_t ON u_t.id = ra_t.userid
                                     AND u_t.deleted = 0 AND u_t.suspended = 0
                                     $trainer_exclusion
                JOIN     {groups_members} gm_t ON gm_t.userid = u_t.id
                $t_map_filter
                GROUP BY ra_t.contextid, gm_t.groupid
            ) t_map_grp ON t_map_grp.contextid = ctx.id
                       AND t_map_grp.groupid = grp_sub.groupid
            LEFT JOIN (
                SELECT   ra_t.contextid, MIN(u_t.id) AS trainer_id
                FROM     {role_assignments} ra_t
                JOIN     {role}  ro_t ON ro_t.id = ra_t.roleid
                                     AND ro_t.shortname IN ('editingteacher','teacher','trainer')
                JOIN     {user}   u_t ON u_t.id = ra_t.userid
                                     AND u_t.deleted = 0 AND u_t.suspended = 0
                                     $trainer_exclusion
                $t_map_filter_crs
                GROUP BY ra_t.contextid
            ) t_map_crs ON t_map_crs.contextid = ctx.id
            LEFT JOIN {user} trainer ON trainer.id = COALESCE(t_map_grp.trainer_id, t_map_crs.trainer_id)
            $join_group
            $join_cohort
            WHERE  gi.itemtype = :itemtype_mod
              AND  gi.hidden = 0
              AND  gi.itemmodule != 'attendance'
              AND  cm.id IS NOT NULL
              $where_course
              $where_student
              $where_trainer
              $where_from
              $where_to
            ORDER BY c.fullname, u.lastname, u.firstname, gi.sortorder
        ";

        $rows = $DB->get_records_sql($sql, $params);

        // ── Build assignment due/submitted lookup (cheap per-row is fine) ──────
        // We only need this if assign module exists.
        $assignLookup = [];
        if ($assignModId) {
            $assignSql = "
                SELECT
                    CONCAT(asub.userid, '_', cm2.id) AS key2,
                    COALESCE(a.duedate, 0)           AS due_date,
                    COALESCE(asub.timemodified, 0)   AS submitted_date
                FROM {assign} a
                JOIN {course_modules} cm2 ON cm2.instance = a.id AND cm2.module = :amod
                LEFT JOIN {assign_submission} asub ON asub.assignment = a.id AND asub.latest = 1
            ";
            foreach ($DB->get_records_sql($assignSql, ['amod' => $assignModId]) as $r) {
                $assignLookup[$r->key2] = $r;
            }
        }

        $result = [];
        foreach ($rows as $row) {
            // ── Competency status from grade scale ──────────────────────────────
            $competencyStatus = '';
            $gradePercent     = -1.0;
            $finalgrade       = (float) $row->finalgrade;
            $grademax         = (float) $row->grademax;

            if ($finalgrade >= 0) {
                if (!empty($row->scale_values) && (int)$row->scaleid > 0) {
                    // Scale-based grade: finalgrade is 1-based index into scale
                    $scaleItems = array_map('trim', explode(',', $row->scale_values));
                    $idx        = (int) round($finalgrade) - 1;
                    $rawLabel   = isset($scaleItems[$idx]) ? strtoupper(trim($scaleItems[$idx])) : '';

                    // Normalise common VET labels
                    if ($rawLabel === 'C'   || strpos($rawLabel, 'COMP') === 0) { $competencyStatus = 'C'; }
                    elseif ($rawLabel === 'NYC' || strpos($rawLabel, 'NOT YET') === 0) { $competencyStatus = 'NYC'; }
                    elseif ($rawLabel === 'RPL' || strpos($rawLabel, 'RECOGNIT') === 0) { $competencyStatus = 'RPL'; }
                    elseif ($rawLabel === 'CT'  || strpos($rawLabel, 'CREDIT') === 0)   { $competencyStatus = 'CT'; }
                    else { $competencyStatus = $rawLabel ?: ''; }

                    // Percentage equivalent for display
                    $gradePercent = count($scaleItems) > 1
                        ? round(($idx / (count($scaleItems) - 1)) * 100)
                        : ($finalgrade > 0 ? 100.0 : 0.0);

                } else {
                    // Percentage-based grade: 100% = Competent (C), anything less = NYC
                    $gradePercent = $grademax > 0 ? round(($finalgrade / $grademax) * 100) : 0;
                    $competencyStatus = $gradePercent >= 100 ? 'C' : 'NYC';
                }
            }

            // ── Completion status ───────────────────────────────────────────────
            $completionMap  = [0 => 'Not attempted', 1 => 'Complete', 2 => 'Complete', 3 => 'Incomplete'];
            $completionStatus = $completionMap[(int)$row->completionstate] ?? 'Not attempted';

            // ── Assignment-specific dates ───────────────────────────────────────
            $cmId         = (int) $row->cm_id;
            $userId       = (int) $row->userid;
            $lookupKey    = "{$userId}_{$cmId}";
            $dueDate      = (int) ($assignLookup[$lookupKey]->due_date ?? 0);
            $submittedDate = (int) ($assignLookup[$lookupKey]->submitted_date ?? 0);

            $result[] = [
                'userid'            => $userId,
                'fullname'          => (string) $row->fullname,
                'student_idnumber'  => (string) $row->student_idnumber,
                'courseid'          => (int)    $row->courseid,
                'course_name'       => (string) $row->course_name,
                'course_code'       => (string) $row->course_code,
                'course_startdate'  => (int)    $row->course_startdate,
                'course_enddate'    => (int)    $row->course_enddate,
                'group_name'        => (string) $row->group_name,
                'cohort_name'       => (string) $row->cohort_name,
                'grade_item_id'     => (int)    $row->grade_item_id,
                'unit_name'         => (string) $row->unit_name,
                'unit_code'         => (string) $row->unit_code,
                'due_date'          => $dueDate,
                'submitted_date'    => $submittedDate,
                'graded_date'       => (int)    $row->graded_date,
                'grade_percent'     => (float)  $gradePercent,
                'competency_status' => $competencyStatus,
                'completion_status' => $completionStatus,
                'trainer_id'        => (int)    $row->trainer_id,
                'trainer_name'      => (string) $row->trainer_name,
                'trainer_email'     => (string) $row->trainer_email,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'            => new \external_value(PARAM_INT,   'Student user ID'),
                'fullname'          => new \external_value(PARAM_TEXT,  'Student full name'),
                'student_idnumber'  => new \external_value(PARAM_TEXT,  'Student ID number (e.g. SID-12345)'),
                'courseid'          => new \external_value(PARAM_INT,   'Course ID'),
                'course_name'       => new \external_value(PARAM_TEXT,  'Course full name'),
                'course_code'       => new \external_value(PARAM_TEXT,  'Course short name / code'),
                'course_startdate'  => new \external_value(PARAM_INT,   'Course start date (unix timestamp)'),
                'course_enddate'    => new \external_value(PARAM_INT,   'Course end date (unix timestamp; 0 = no end)'),
                'group_name'        => new \external_value(PARAM_TEXT,  'Group name (e.g. GD-1)'),
                'cohort_name'       => new \external_value(PARAM_TEXT,  'Cohort name'),
                'grade_item_id'     => new \external_value(PARAM_INT,   'Grade item ID'),
                'unit_name'         => new \external_value(PARAM_TEXT,  'Unit / activity name'),
                'unit_code'         => new \external_value(PARAM_TEXT,  'Unit code (from grade item idnumber or course shortname)'),
                'due_date'          => new \external_value(PARAM_INT,   'Assessment due date (unix timestamp; 0 = none)'),
                'submitted_date'    => new \external_value(PARAM_INT,   'Date student submitted (unix timestamp; 0 = not submitted)'),
                'graded_date'       => new \external_value(PARAM_INT,   'Date graded (unix timestamp; 0 = not graded)'),
                'grade_percent'     => new \external_value(PARAM_FLOAT, 'Grade as percentage (-1 = not yet graded)'),
                'competency_status' => new \external_value(PARAM_TEXT,  'VET competency status: C, NYC, RPL, CT, or empty string'),
                'completion_status' => new \external_value(PARAM_TEXT,  'Completion status: Complete, Incomplete, or Not attempted'),
                'trainer_id'        => new \external_value(PARAM_INT,   'Trainer user ID (0 = none assigned)'),
                'trainer_name'      => new \external_value(PARAM_TEXT,  'Trainer full name'),
                'trainer_email'     => new \external_value(PARAM_TEXT,  'Trainer email address'),
            ])
        );
    }
}
