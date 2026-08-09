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
 * External function: local_lmshomepage_get_attendance_report
 *
 * Returns one row per student × attendance-instance, aggregating session
 * attendance marks from mod_attendance into:
 *   total_sessions   — all past sessions (sessdate ≤ now), regardless of whether marked
 *   sessions_marked  — sessions where any attendance mark has been recorded for this student
 *   attended_sessions — sessions marked P (Present), L (Late), or E (Excused)
 *   missed_sessions  — sessions marked A (Absent)
 *   attendance_pct   — attended / sessions_marked × 100 (0 when sessions_marked = 0)
 *                      Uses sessions_marked as denominator so unmarked past sessions
 *                      do not penalise students before the teacher records attendance.
 *                      A student who attended every marked session shows 100% even if
 *                      some past sessions are still awaiting the teacher's mark entry.
 *   at_risk          — 1 when attendance_pct < 80 AND sessions_marked >= 3
 *
 * Returns 0 rows (not an error) when mod_attendance is not installed.
 *
 * Filters (all optional, 0 / '' = no filter):
 *   course_id       — single course
 *   student_userid  — single student
 *   group_id        — Moodle group (GD-1 … GD-9)
 *   cohort_id       — Moodle cohort
 *   trainer_userid  — teacher/editingteacher in course context
 *   from_date       — Unix timestamp; sessions on or after this date
 *   to_date         — Unix timestamp; sessions on or before this date
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_attendance_report extends \external_api {
    public static function execute_parameters (): \external_function_parameters {
        return new \external_function_parameters([
            'course_id'      => new \external_value(PARAM_INT, 'Filter to a course. 0 = all.',       VALUE_DEFAULT, 0),
            'student_userid' => new \external_value(PARAM_INT, 'Filter to a student. 0 = all.',      VALUE_DEFAULT, 0),
            'group_id'       => new \external_value(PARAM_INT, 'Filter to a Moodle group. 0 = all.', VALUE_DEFAULT, 0),
            'cohort_id'      => new \external_value(PARAM_INT, 'Filter to a cohort. 0 = all.',       VALUE_DEFAULT, 0),
            'trainer_userid' => new \external_value(PARAM_INT, 'Filter to a trainer. 0 = all.',      VALUE_DEFAULT, 0),
            'from_date'      => new \external_value(PARAM_INT, 'Session date lower bound (unix ts). 0 = no bound.', VALUE_DEFAULT, 0),
            'to_date'        => new \external_value(PARAM_INT, 'Session date upper bound (unix ts). 0 = no bound.', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute (
        int $course_id      = 0,
        int $student_userid = 0,
        int $group_id       = 0,
        int $cohort_id      = 0,
        int $trainer_userid = 0,
        int $from_date      = 0,
        int $to_date        = 0
    ): array {
        global $DB;

        // Bail early if mod_attendance not installed.
        $attendModId = $DB->get_field('modules', 'id', ['name' => 'attendance']);
        if (!$attendModId) {
            return [];
        }

        $now    = time();
        $params = [
            'now1'        => $now,
            'now2'        => $now,   // used to exclude future sessions from totals
            'attend_mod'  => (int) $attendModId,
        ];

        // ── Optional filters ──────────────────────────────────────────────────
        $where_course  = '';
        $where_student = '';
        $where_trainer = '';
        $where_from    = '';
        $where_to      = '';
        $join_group    = '';
        $join_cohort   = '';
        $t_map_filter  = '';

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
            $join_group = 'JOIN {groups_members} gm_flt ON gm_flt.userid = u.id AND gm_flt.groupid = :group_id';
        }
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $join_cohort = 'JOIN {cohort_members} cm_flt ON cm_flt.userid = u.id AND cm_flt.cohortid = :cohort_id';
        }
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            // Filter inside t_map so only this trainer's contextids are returned.
            $t_map_filter  = 'AND u_t.id = :trainer_userid';
            $where_trainer = 'AND t_map.trainer_id IS NOT NULL';
        }
        if ($from_date > 0) {
            $params['from_date'] = $from_date;
            $where_from = 'AND sess.sessdate >= :from_date';
        }
        if ($to_date > 0) {
            $params['to_date'] = $to_date;
            $where_to = 'AND sess.sessdate <= :to_date';
        }

        // ── Trainer exclusion guard ────────────────────────────────────────────
        // Exclude system/test accounts from trainer lookup:
        //   - lastaccess = 0  → never logged in (test/placeholder accounts)
        //   - firstname LIKE 'test%' → named "Test Teacher" etc.
        //   - username IN ('admin','guest') → system accounts
        $trainer_exclusion = "AND u_t.lastaccess > 0
                              AND LOWER(u_t.firstname) NOT LIKE 'test%'
                              AND u_t.username NOT IN ('admin', 'guest')";

        // ── Step 1: fetch all sessions with per-student marks ─────────────────
        // Groups and cohorts are resolved via deterministic subqueries to avoid
        // Cartesian-product row duplication (a student in N groups × M cohorts
        // would otherwise produce N×M rows per session, inflating all counters).

        $sql = "
            SELECT
                CONCAT(u.id, '_', attn.id, '_', sess.id)           AS recid,
                u.id                                                 AS userid,
                CONCAT(u.firstname, ' ', u.lastname)                AS fullname,
                COALESCE(u.idnumber, '')                            AS student_idnumber,
                c.id                                                 AS courseid,
                c.fullname                                           AS course_name,
                c.shortname                                          AS course_code,
                COALESCE(c.startdate, 0)                            AS course_startdate,
                COALESCE(c.enddate, 0)                              AS course_enddate,
                attn.id                                              AS attendance_id,
                attn.name                                            AS attendance_name,
                cm.id                                                AS cm_id,
                sess.id                                              AS session_id,
                sess.sessdate                                        AS session_date,
                COALESCE(grp_sub.group_name, '')                    AS group_name,
                COALESCE(coh_sub.cohort_name, '')                   AS cohort_name,
                COALESCE(ats.acronym, '')                           AS mark_acronym,
                COALESCE(trainer.id, 0)                             AS trainer_id,
                COALESCE(CONCAT(trainer.firstname,' ',trainer.lastname), '') AS trainer_name,
                COALESCE(trainer.email, '')                         AS trainer_email
            FROM   {attendance}         attn
            JOIN   {course_modules}      cm   ON cm.instance = attn.id AND cm.module = :attend_mod
                                              AND cm.deletioninprogress = 0
            JOIN   {course}              c    ON c.id = attn.course AND c.id != 1 AND c.visible = 1
            JOIN   {attendance_sessions} sess ON sess.attendanceid = attn.id
                                              AND sess.sessdate <= :now2
            -- enrolled students
            JOIN   {user_enrolments}     ue   ON ue.status = 0 AND (ue.timeend = 0 OR ue.timeend > :now1)
            JOIN   {enrol}               en   ON en.id = ue.enrolid AND en.courseid = c.id
            JOIN   {context}             ctx  ON ctx.contextlevel = 50 AND ctx.instanceid = c.id
            JOIN   {role_assignments}    ra   ON ra.userid = ue.userid AND ra.contextid = ctx.id
            JOIN   {role}                ro   ON ro.id = ra.roleid AND ro.shortname = 'student'
            JOIN   {user}                u    ON u.id = ue.userid AND u.deleted = 0 AND u.suspended = 0
                                             AND u.id != 1 AND u.username != 'guest'
            -- per-student attendance mark for this session
            LEFT JOIN {attendance_log}      al  ON al.sessionid = sess.id AND al.studentid = u.id
            LEFT JOIN {attendance_statuses} ats ON ats.id = al.statusid AND ats.attendanceid = attn.id
            -- group: one deterministic group per student×course (avoids row multiplication)
            LEFT JOIN (
                SELECT   gm_sub.userid, g_sub.courseid,
                         MIN(g_sub.name) AS group_name
                FROM     {groups_members} gm_sub
                JOIN     {groups}         g_sub ON g_sub.id = gm_sub.groupid
                GROUP BY gm_sub.userid, g_sub.courseid
            ) grp_sub ON grp_sub.userid = u.id AND grp_sub.courseid = c.id
            -- cohort: one deterministic cohort per student (avoids Cartesian product)
            LEFT JOIN (
                SELECT   cm_sub.userid, MIN(coh_sub.name) AS cohort_name
                FROM     {cohort_members} cm_sub
                JOIN     {cohort} coh_sub ON coh_sub.id = cm_sub.cohortid
                GROUP BY cm_sub.userid
            ) coh_sub ON coh_sub.userid = u.id
            -- trainer lookup with system/test account exclusion
            LEFT JOIN (
                SELECT   ra_t.contextid, MIN(u_t.id) AS trainer_id
                FROM     {role_assignments} ra_t
                JOIN     {role}  ro_t ON ro_t.id = ra_t.roleid
                                     AND ro_t.shortname IN ('editingteacher','teacher','trainer')
                JOIN     {user}   u_t ON u_t.id = ra_t.userid
                                     AND u_t.deleted = 0 AND u_t.suspended = 0
                                     $trainer_exclusion
                $t_map_filter
                GROUP BY ra_t.contextid
            ) t_map ON t_map.contextid = ctx.id
            LEFT JOIN {user} trainer ON trainer.id = t_map.trainer_id
            $join_group
            $join_cohort
            WHERE 1=1
              $where_course
              $where_student
              $where_trainer
              $where_from
              $where_to
              -- Only include sessions that apply to this student's groups (or to all groups)
              AND (sess.groupid = 0 OR EXISTS (
                  SELECT 1 FROM {groups_members} gm_sess
                  WHERE gm_sess.userid = u.id AND gm_sess.groupid = sess.groupid
              ))
            ORDER BY u.lastname, u.firstname, c.fullname, sess.sessdate
        ";

        $rows = $DB->get_records_sql($sql, $params);

        // ── Step 2: aggregate per student × attendance instance ──────────────
        // Track which (userid, attendance_id) session IDs have already been
        // counted to prevent any remaining duplicate rows from inflating counters.
        $agg          = [];
        $seen_sessions = [];

        foreach ($rows as $row) {
            $key    = "{$row->userid}_{$row->attendance_id}";
            $sessId = (int) $row->session_id;

            if (!isset($agg[$key])) {
                $agg[$key] = [
                    'userid'            => (int)    $row->userid,
                    'fullname'          => (string) $row->fullname,
                    'student_idnumber'  => (string) $row->student_idnumber,
                    'courseid'          => (int)    $row->courseid,
                    'course_name'       => (string) $row->course_name,
                    'course_code'       => (string) $row->course_code,
                    'course_startdate'  => (int)    $row->course_startdate,
                    'course_enddate'    => (int)    $row->course_enddate,
                    'attendance_id'     => (int)    $row->attendance_id,
                    'attendance_name'   => (string) $row->attendance_name,
                    'group_name'        => (string) $row->group_name,
                    'cohort_name'       => (string) $row->cohort_name,
                    'trainer_id'        => (int)    $row->trainer_id,
                    'trainer_name'      => (string) $row->trainer_name,
                    'trainer_email'     => (string) $row->trainer_email,
                    'total_sessions'    => 0,
                    'sessions_marked'   => 0,
                    'attended_sessions' => 0,
                    'missed_sessions'   => 0,
                ];
                $seen_sessions[$key] = [];
            }

            // Skip duplicate session rows (safety net for any remaining JOIN fan-out).
            if (in_array($sessId, $seen_sessions[$key], true)) {
                continue;
            }
            $seen_sessions[$key][] = $sessId;

            $agg[$key]['total_sessions']++;

            $acronym = strtoupper(trim($row->mark_acronym));
            if ($acronym !== '') {
                // Only count sessions where a mark has been recorded in the
                // denominator.  Unmarked past sessions must not penalise students
                // before the teacher has entered attendance — a session where no
                // mark exists is not yet "held" from the reporting perspective.
                $agg[$key]['sessions_marked']++;
                if (in_array($acronym, ['P', 'L', 'E'])) {
                    // Present, Late, Excused → attended
                    $agg[$key]['attended_sessions']++;
                } elseif ($acronym === 'A') {
                    $agg[$key]['missed_sessions']++;
                }
                // Any other custom acronym (AU, AE, UA, MED, etc.) increments
                // sessions_marked only — counts against attendance % without
                // inflating missed_sessions, which matches Moodle export behaviour.
            }
        }

        // ── Step 3: compute percentages and at-risk flag ──────────────────────
        // Denominator: sessions_marked (sessions where any mark was recorded).
        // Past sessions with no mark are NOT counted — a student who attended
        // every marked session should show 100% even while unmarked sessions
        // are still outstanding.  total_sessions is kept in the output for
        // reference (shows how many sessions have occurred overall) but is not
        // used as the denominator.
        $result = [];
        foreach ($agg as $entry) {
            $total    = $entry['total_sessions'];
            $marked   = $entry['sessions_marked'];
            $attended = $entry['attended_sessions'];
            $pct      = $marked > 0 ? round(($attended / $marked) * 100, 1) : 0.0;
            $atRisk   = ($marked >= 3 && $pct < 80) ? 1 : 0;

            $result[] = array_merge($entry, [
                'attendance_pct' => $pct,
                'at_risk'        => $atRisk,
            ]);
        }

        return $result;
    }

    public static function execute_returns (): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'            => new \external_value(PARAM_INT,   'Student user ID'),
                'fullname'          => new \external_value(PARAM_TEXT,  'Student full name'),
                'student_idnumber'  => new \external_value(PARAM_TEXT,  'Student ID number'),
                'courseid'          => new \external_value(PARAM_INT,   'Course ID'),
                'course_name'       => new \external_value(PARAM_TEXT,  'Course full name'),
                'course_code'       => new \external_value(PARAM_TEXT,  'Course short name / code'),
                'course_startdate'  => new \external_value(PARAM_INT,   'Course start date (unix timestamp)'),
                'course_enddate'    => new \external_value(PARAM_INT,   'Course end date (unix timestamp; 0 = no end)'),
                'attendance_id'     => new \external_value(PARAM_INT,   'Attendance activity ID'),
                'attendance_name'   => new \external_value(PARAM_TEXT,  'Attendance activity name'),
                'group_name'        => new \external_value(PARAM_TEXT,  'Group name (e.g. GD-1)'),
                'cohort_name'       => new \external_value(PARAM_TEXT,  'Cohort name'),
                'total_sessions'    => new \external_value(PARAM_INT,   'All past sessions (sessdate ≤ now), whether marked or not'),
                'sessions_marked'   => new \external_value(PARAM_INT,   'Sessions where any attendance mark has been recorded (denominator for attendance_pct)'),
                'attended_sessions' => new \external_value(PARAM_INT,   'Sessions marked Present, Late, or Excused'),
                'missed_sessions'   => new \external_value(PARAM_INT,   'Sessions marked Absent'),
                'attendance_pct'    => new \external_value(PARAM_FLOAT, 'Attendance % (attended / sessions_marked × 100; 0 when no marked sessions)'),
                'at_risk'           => new \external_value(PARAM_INT,   '1 if attendance < 80% with 3+ marked sessions, else 0'),
                'trainer_id'        => new \external_value(PARAM_INT,   'Trainer user ID (0 = none)'),
                'trainer_name'      => new \external_value(PARAM_TEXT,  'Trainer full name'),
                'trainer_email'     => new \external_value(PARAM_TEXT,  'Trainer email address'),
            ])
        );
    }
}
