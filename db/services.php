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
 * Web service declarations for local_lmshomepage.
 *
 * Moodle reads this file on install/upgrade and automatically creates the
 * "LMS Homepage Dashboard" service pre-loaded with every function the plugin
 * needs.  Admins only need to:
 *   1. Site Admin → Server → Web Services → Manage tokens → Add token
 *   2. Select service "LMS Homepage Dashboard"
 *   3. Paste the token into the plugin settings (API token field)
 *
 * No manual function-by-function setup required.
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Custom web service functions exposed by this plugin.
 *
 * These functions are implemented in classes/external/ and are added to
 * the "LMS Homepage Dashboard" service automatically on plugin upgrade.
 */
$functions = [

    // ── 1. Active (non-suspended) user count ────────────────────────────────
    // Fixes the gap where core_enrol_get_enrolled_users never returns the
    // `suspended` field under limited-permission tokens.
    'local_lmshomepage_get_active_user_count' => [
        'classname'     => \local_lmshomepage\external\get_active_user_count::class,
        'methodname'    => 'execute',
        'description'   => 'Return count of active (not suspended, not deleted) Moodle user accounts.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 2. Course completion counts ──────────────────────────────────────────
    // Returns per-course completion counts from mdl_course_completions, which
    // is the authoritative source of truth for course-level completion tracking.
    // Replaces the grade-ratio approximation used on all 6 dashboards.
    'local_lmshomepage_get_course_completion_counts' => [
        'classname'     => \local_lmshomepage\external\get_course_completion_counts::class,
        'methodname'    => 'execute',
        'description'   => 'Return per-course completion counts from mdl_course_completions.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 3. Monthly active user count ─────────────────────────────────────────
    // Returns the number of distinct users who accessed any course in the last
    // N days (default 30). Uses mdl_user_lastaccess — more accurate than
    // filtering enrolled users by lastcourseaccess in our app layer.
    'local_lmshomepage_get_monthly_active_user_count' => [
        'classname'     => \local_lmshomepage\external\get_monthly_active_user_count::class,
        'methodname'    => 'execute',
        'description'   => 'Return count of distinct users who accessed a course within the last N days.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 4. Bulk enrolled students ────────────────────────────────────────────
    // Replaces N individual core_enrol_get_enrolled_users calls (one per course)
    // with a single database query returning all enrolled students across all
    // courses. Critically, includes the `suspended` field that the standard
    // API omits for limited-permission tokens.
    'local_lmshomepage_get_enrolled_students' => [
        'classname'     => \local_lmshomepage\external\get_enrolled_students::class,
        'methodname'    => 'execute',
        'description'   => 'Return all enrolled students across all courses with suspended status in one query.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── VET Reporting functions (Wombat Reporter & future clients) ───────────

    // ── 5. Student inactivity ─────────────────────────────────────────────────
    // Returns students who have not accessed any course for N or more days.
    // Joins the wombat_trainer custom profile field for trainer attribution.
    'local_lmshomepage_get_student_inactivity' => [
        'classname'     => \local_lmshomepage\external\get_student_inactivity::class,
        'methodname'    => 'execute',
        'description'   => 'Return students inactive for threshold_days or more, with trainer allocation.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 6. Completed units ────────────────────────────────────────────────────
    // Returns one row per completed activity per student within a date range.
    // Includes unit code (from cm.idnumber), cohort, group, and trainer.
    'local_lmshomepage_get_completed_units' => [
        'classname'     => \local_lmshomepage\external\get_completed_units::class,
        'methodname'    => 'execute',
        'description'   => 'Return completed activity records within a date range with trainer and cohort details.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 6a. All categories (including hidden) ────────────────────────────────
    // Reads mdl_course_categories directly, bypassing core_course_get_categories
    // which filters out hidden categories unless the token user has
    // moodle/category:viewhiddencategories.
    'local_lmshomepage_get_all_categories' => [
        'classname'     => \local_lmshomepage\external\get_all_categories::class,
        'methodname'    => 'execute',
        'description'   => 'Return all course categories including hidden ones, read directly from mdl_course_categories.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 6b. Per-course completion report ─────────────────────────────────────
    // Returns one row per enrolled student with per-activity AND course-level
    // completion data for a single course.  Reads directly from
    // mdl_course_modules_completion.timecompleted (INITIAL date) and
    // mdl_course_completions.timecompleted (INITIAL date), bypassing the
    // capability restriction that blocks core_completion WS functions for
    // non-admin webservice accounts (e.g. ITLC token).
    'local_lmshomepage_get_completion_report' => [
        'classname'     => \local_lmshomepage\external\get_completion_report::class,
        'methodname'    => 'execute',
        'description'   => 'Return per-student activity and course completion data with INITIAL completion dates for one course.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 7. Assessment submissions ─────────────────────────────────────────────
    // Returns one row per assignment per student with VET status (NYS/IP/
    // Submitted/Graded), due date, overdue days, and trainer email.
    // Powers Trainer Marking report and all three notification automations.
    'local_lmshomepage_get_assessment_submissions' => [
        'classname'     => \local_lmshomepage\external\get_assessment_submissions::class,
        'methodname'    => 'execute',
        'description'   => 'Return assignment submissions per student with VET status, overdue days, and trainer details.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 8. Cohort progress matrix ─────────────────────────────────────────────
    // Returns a flat unit × student completion grid for a cohort.
    // The calling app assembles this into the matrix report view.
    // VET outcome codes: NYS, IP, C, CT, RPL.
    'local_lmshomepage_get_cohort_matrix' => [
        'classname'     => \local_lmshomepage\external\get_cohort_matrix::class,
        'methodname'    => 'execute',
        'description'   => 'Return flat unit × student VET outcome grid for a cohort.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 9. Trainer allocations ────────────────────────────────────────────────
    // Returns all enrolled students with their wombat_trainer profile field
    // resolved to a trainer name. Unallocated students are included with
    // trainer_id = 0.
    'local_lmshomepage_get_trainer_allocations' => [
        'classname'     => \local_lmshomepage\external\get_trainer_allocations::class,
        'methodname'    => 'execute',
        'description'   => 'Return student-to-trainer allocation from wombat_trainer custom profile field.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 10. CommHub logger ────────────────────────────────────────────────────
    // Writes a communication record to local_lmshomepage_commhub.
    // Called by the LMS Hosting Services server after every automated
    // notification (M7 reminder, M8 escalation, M9 marking alert).
    'local_lmshomepage_log_commhub' => [
        'classname'     => \local_lmshomepage\external\log_commhub::class,
        'methodname'    => 'execute',
        'description'   => 'Write a communication record to the CommHub log table.',
        'type'          => 'write',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 11. Get trainers ──────────────────────────────────────────────────────
    // Returns all editingteacher/teacher users with their allocated student
    // count from the wombat_trainer profile field.
    // Used by M8 Day 15 and M9 automation to send per-trainer reports.
    'local_lmshomepage_get_trainers' => [
        'classname'     => \local_lmshomepage\external\get_trainers::class,
        'methodname'    => 'execute',
        'description'   => 'Return all trainers with email and student count from wombat_trainer profile field.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 12. Get admin emails ──────────────────────────────────────────────────
    // Returns site administrator user IDs, names, and emails.
    // Used by M8 Day 30 to send the full escalation report to all admins.
    'local_lmshomepage_get_admin_emails' => [
        'classname'     => \local_lmshomepage\external\get_admin_emails::class,
        'methodname'    => 'execute',
        'description'   => 'Return all Moodle site administrator email addresses.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 13. VET Grade Report ──────────────────────────────────────────────
    // Returns one row per student × grade-item with VET competency status
    // (C / NYC / RPL / CT) derived from the Moodle grade scale.
    // Supports all 5 grade report types (1A–1E) via server-side filtering.
    'local_lmshomepage_get_vet_grade_report' => [
        'classname'     => \local_lmshomepage\external\get_vet_grade_report::class,
        'methodname'    => 'execute',
        'description'   => 'Return per-student grade data with VET competency status (C/NYC/RPL/CT) from grade scale.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 14. Attendance Report ─────────────────────────────────────────────
    // Returns per-student attendance summary (sessions attended/missed,
    // attendance %, at-risk flag) from mod_attendance.
    // Supports all 3 attendance report types (2A–2C) via server-side filtering.
    'local_lmshomepage_get_attendance_report' => [
        'classname'     => \local_lmshomepage\external\get_attendance_report::class,
        'methodname'    => 'execute',
        'description'   => 'Return per-student attendance stats (attended, missed, %, at-risk) from mod_attendance.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 15a. Slim course list ─────────────────────────────────────────────
    // Single-query replacement for core_course_get_courses.
    // Returns only {id, fullname, shortname, categoryid, visible, numsections}
    // — ~50× smaller payload than the full course objects.
    'local_lmshomepage_get_slim_courses' => [
        'classname'     => \local_lmshomepage\external\get_slim_courses::class,
        'methodname'    => 'execute',
        'description'   => 'Return lightweight course records (id/fullname/shortname/categoryid/visible/numsections only).',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

    // ── 15. User Courses (bulk) ───────────────────────────────────────────
    // Single-call replacement for the 3-request-per-learner pattern:
    //   gradereport_overview_get_course_grades (course discovery)
    //   + N × core_completion_get_course_completion_status
    //   + N × core_completion_get_activities_completion_status
    // For a student with 10 courses this reduces 21 Moodle REST calls to 1.
    'local_lmshomepage_get_user_courses' => [
        'classname'     => \local_lmshomepage\external\get_user_courses::class,
        'methodname'    => 'execute',
        'description'   => 'Return enrolled courses with completion status and activity progress % in one call.',
        'type'          => 'read',
        'loginrequired' => false,
        'ajax'          => false,
        'services'      => ['lmshomepage_dashboard'],
    ],

];

/**
 * Service definition — created automatically on plugin install/upgrade.
 *
 * shortname must be unique across the Moodle site.
 * restrictedusers = 0  → any authorised user may hold a token for this service.
 * enabled = 1          → active immediately after install.
 */
$services = [
    'LMS Homepage Dashboard' => [
        'shortname'        => 'lmshomepage_dashboard',
        'functions'        => [
            // ── Core ────────────────────────────────────────────────────────
            'core_webservice_get_site_info',                        // site name, Moodle version, current user
            'core_course_get_courses',                              // full course list
            'core_course_get_contents',                             // course sections + modules (finds attendance instance IDs)
            'core_enrol_get_enrolled_users',                        // students & teachers per course
            'core_user_get_users',                                  // user lookup by field
            'core_completion_get_activities_completion_status',     // activity-level completion progress (My Courses progress bar)
            'core_completion_get_course_completion_status',         // course-level complete/not-complete status
            'core_message_send_instant_messages',                   // KPI breach in-Moodle messages

            // ── Grade / completion reports ───────────────────────────────
            'gradereport_overview_get_course_grades',               // enrolled course discovery via grades
            'report_completion_get_activity_completion_status',     // course-level completion

            // ── Attendance plugin (mod_attendance) ───────────────────────
            'mod_attendance_get_sessions',                          // all sessions for an attendance instance
            'mod_attendance_get_session',                           // single session detail + per-student marks
            'mod_attendance_get_courses_with_today_sessions',       // today's attendance sessions

            // ── Level Up XP plugin (block_xp) ────────────────────────────
            // Called on all 4 instances for the leaderboard. Without this
            // entry in the service, every call fails silently and falls back
            // to a grade-approximated leaderboard instead of real XP data.
            'block_xp_get_leaderboard',                             // site-wide XP leaderboard (courseid=0)

            // ── Custom functions (defined above in $functions) ────────────
            'local_lmshomepage_get_active_user_count',              // non-suspended user count (replaces inaccurate proxy)
            'local_lmshomepage_get_course_completion_counts',       // real completions from mdl_course_completions
            'local_lmshomepage_get_monthly_active_user_count',      // distinct users active in last N days
            'local_lmshomepage_get_slim_courses',                   // lightweight course list (replaces heavy core_course_get_courses)
            'local_lmshomepage_get_enrolled_students',              // bulk enrolment query (replaces N individual API calls)
            'local_lmshomepage_get_user_courses',                   // bulk user courses with completion (replaces 1+2N per-learner calls)

            // ── VET Reporting functions ───────────────────────────────────
            'local_lmshomepage_get_student_inactivity',             // students inactive N+ days with trainer name
            'local_lmshomepage_get_completed_units',                // completed activities in date range
            'local_lmshomepage_get_assessment_submissions',         // per-student assignment status (NYS/IP/Submitted/Graded)
            'local_lmshomepage_get_cohort_matrix',                  // unit × student VET outcome grid
            'local_lmshomepage_get_trainer_allocations',            // student-to-trainer mapping
            'local_lmshomepage_log_commhub',                        // write CommHub communication record
            'local_lmshomepage_get_trainers',                       // trainers with email + student count
            'local_lmshomepage_get_admin_emails',                   // site admin email addresses

            // ── Cohort helpers ────────────────────────────────────────────
            'core_cohort_get_cohorts',                              // cohort selector dropdowns
            'core_cohort_get_cohort_members',                       // cohort member lists

            // ── Group helpers (for GD-1 to GD-9 cohort filtering) ────────────
            'core_group_get_course_groups',                         // list all groups in a course
            'core_group_get_group_members',                         // members of a specific group

            // ── VET Report functions (grade + attendance) ─────────────────────
            'local_lmshomepage_get_vet_grade_report',               // per-student grade with C/NYC/RPL/CT status
            'local_lmshomepage_get_attendance_report',              // per-student attendance (attended/missed/%, at-risk)

            // ── Assignment functions (Signature at-risk + submissions) ─────────
            'mod_assign_get_assignments',                           // assignment list per course (Signature)
            'mod_assign_get_submissions',                           // per-student submission status (Signature)

            // ── Course categories (ITLC catalogue grouping) ───────────────────
            'core_course_get_categories',                           // course category tree (visible only)
            'local_lmshomepage_get_all_categories',                 // all categories including hidden (ITLC)
            'local_lmshomepage_get_completion_report',              // per-student activity + course completion with initial dates (ITLC)
        ],
        'requiredcapability' => '',
        'restrictedusers'    => 0,
        'enabled'            => 1,
        'downloadfiles'      => 0,
        'uploadfiles'        => 0,
    ],
];
