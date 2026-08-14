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
 * External function: local_lmshomepage_get_user_courses
 *
 * Returns all courses a user is currently enrolled in (or has completed),
 * with course-level completion status and activity-completion progress —
 * in a SINGLE call.
 *
 * Problem it solves
 * -----------------
 * The LMS portal currently makes three separate Moodle API calls per learner
 * page load to build the "My Courses" panel:
 *
 *   1. gradereport_overview_get_course_grades            — discovers which courses
 *   2. core_completion_get_course_completion_status × N  — one per course
 *   3. core_completion_get_activities_completion_status × N — one per course
 *
 * For a student enrolled in 10 courses that is 21 HTTP round-trips per page load.
 * This function runs 3 optimised SQL queries (no PHP loops, no extra HTTP calls)
 * and returns everything the portal needs in one response.
 *
 * Enrolment scope
 * ---------------
 * We include a course if the student has ANY enrolment record — active,
 * suspended, or expired.  This covers three real-world patterns at ITLC:
 *   a) Active enrolment  — currently studying.
 *   b) Suspended/expired — e.g. "Credit Deemed" (-CD) courses where the
 *      enrolment is suspended after credit is granted but no Moodle
 *      course_completions record is written.
 *   c) Post-completion   — enrolment suspended/expired after formal completion.
 *
 * Reason: the previous approach (status=0 active OR course_completions) caused
 * -CD and prior-semester units to vanish from the student dashboard because
 * ITLC's credit workflow suspends the enrolment without triggering a
 * course_completions record.  Including ALL enrolment records fixes this.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_user_courses
 *   userid=1234
 *
 * Returns:
 *   [
 *     {
 *       "courseid":     42,
 *       "fullname":     "Diploma of Customs Broking 2026",
 *       "visible":      1,
 *       "is_complete":  0,
 *       "activity_pct": 65
 *     },
 *     ...
 *   ]
 *
 * activity_pct = -1 means no completion tracking is enabled on the course
 *              (no activities have $cm->completion > COMPLETION_TRACKING_NONE).
 *              The portal treats -1 as 0 % for display.
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_user_courses extends \external_api {
    public static function execute_parameters (): \external_function_parameters {
        return new \external_function_parameters([
            'userid' => new \external_value(
                PARAM_INT,
                'Moodle user ID to fetch courses for.',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Return enrolled courses with completion status and activity progress.
     *
     * @param  int   $userid  Moodle user ID.
     * @return array
     */
    public static function execute (int $userid): array {
        global $DB;

        // ── 1. Enrolments — ALL records (active, suspended, expired) ────────
        // Include every course the student has ever been enrolled in.
        // This is intentional: ITLC's "Credit Deemed" (-CD) workflow suspends
        // the enrolment after credit is granted but never writes a
        // course_completions record, so a status=0 filter would silently drop
        // those units from the student's dashboard.
        $enrolledCourses = $DB->get_records_sql("
            SELECT DISTINCT e.courseid,
                            c.fullname,
                            c.visible
              FROM {user_enrolments} ue
              JOIN {enrol}  e ON e.id  = ue.enrolid
              JOIN {course} c ON c.id  = e.courseid AND c.id != 1
             WHERE ue.userid = :uid
        ", ['uid' => $userid]);

        if (empty($enrolledCourses)) {
            return [];
        }

        $courseids = array_keys($enrolledCourses);
        [$insql, $inparams] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');

        // ── 2. Course-level completions ───────────────────────────────────────
        // A row in mdl_course_completions with timecompleted != NULL means the
        // student has met all course-completion criteria.
        $completedSet = [];
        $completionRows = $DB->get_records_sql("
            SELECT course
              FROM {course_completions}
             WHERE userid = :uid
               AND timecompleted IS NOT NULL
               AND course $insql
        ", array_merge(['uid' => $userid], $inparams));
        foreach ($completionRows as $row) {
            $completedSet[(int)$row->course] = true;
        }

        // ── 3. Activity completion progress per course ────────────────────────
        // Counts tracked activities (cm.completion > 0 = manual or automatic)
        // and done ones (completionstate >= 1 = complete / complete+pass / complete+fail).
        // The LEFT JOIN means unstarted activities return NULL → coalesced to 0.
        $activityPctMap = [];
        $actRows = $DB->get_records_sql("
            SELECT   cm.course                                                           AS courseid,
                     COUNT(*)                                                            AS total_tracked,
                     SUM(CASE WHEN COALESCE(cmc.completionstate, 0) >= 1 THEN 1 ELSE 0 END) AS done
              FROM {course_modules} cm
              LEFT JOIN {course_modules_completion} cmc
                     ON  cmc.coursemoduleid = cm.id
                    AND  cmc.userid         = :uid
             WHERE cm.completion > 0
               AND cm.course $insql
             GROUP BY cm.course
        ", array_merge(['uid' => $userid], $inparams));
        foreach ($actRows as $row) {
            $total = (int)$row->total_tracked;
            if ($total > 0) {
                $activityPctMap[(int)$row->courseid] =
                    (int)round(((int)$row->done / $total) * 100);
            }
        }

        // ── Merge and return ──────────────────────────────────────────────────
        $result = [];
        foreach ($enrolledCourses as $courseid => $course) {
            $cid = (int)$courseid;
            $result[] = [
                'courseid'     => $cid,
                'fullname'     => (string)$course->fullname,
                'visible'      => (int)$course->visible,
                'is_complete'  => isset($completedSet[$cid]) ? 1 : 0,
                // -1 = no completion tracking on this course; portal treats as 0 %
                'activity_pct' => isset($activityPctMap[$cid]) ? $activityPctMap[$cid] : -1,
            ];
        }

        return $result;
    }

    public static function execute_returns (): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'courseid'     => new \external_value(PARAM_INT,  'Course ID'),
                'fullname'     => new \external_value(PARAM_TEXT, 'Course full name'),
                'visible'      => new \external_value(PARAM_INT,  '1 = visible to students, 0 = hidden/archived'),
                'is_complete'  => new \external_value(PARAM_INT,  '1 = course-level completion recorded, 0 = not yet complete'),
                'activity_pct' => new \external_value(PARAM_INT,  'Activity completion % (0–100), or -1 if no completion tracking'),
            ])
        );
    }
}
