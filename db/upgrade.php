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
 * Upgrade script for local_lmshomepage.
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_lmshomepage_upgrade (int $oldversion): bool {
    global $CFG, $DB;
    $dbman = $DB->get_manager();

    // ── v2.0.0 → v2.1.0 ─────────────────────────────────────────────────────
    // Ensure local_lmshomepage_reminders exists with the risk_level column.
    // Guard with table_exists() first — if the plugin was deployed without
    // running install.xml (e.g. manual upload), the table may never have been
    // created and field_exists() would throw ddl_table_missing_exception.

    if ($oldversion < 2026031801) {
        $table = new xmldb_table('local_lmshomepage_reminders');

        if (!$dbman->table_exists($table)) {
            // Table never created — build it from scratch with all columns.
            $table->add_field('id',         XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('userid',     XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('risk_level', XMLDB_TYPE_CHAR,    '10', null, XMLDB_NOTNULL, null, 'low');
            $table->add_field('timesent',   XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('userid_courseid_risk', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'risk_level']);
            $dbman->create_table($table);
        } else {
            // Table exists — apply incremental changes only.
            if (!$dbman->field_exists($table, 'risk_level')) {
                $field = new xmldb_field('risk_level', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'low', 'courseid');
                $dbman->add_field($table, $field);
            }

            $old_index = new xmldb_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            if ($dbman->index_exists($table, $old_index)) {
                $dbman->drop_index($table, $old_index);
            }

            $new_index = new xmldb_index('userid_courseid_risk', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'risk_level']);
            if (!$dbman->index_exists($table, $new_index)) {
                $dbman->add_index($table, $new_index);
            }

            $DB->execute("UPDATE {local_lmshomepage_reminders} SET risk_level = 'low' WHERE risk_level IS NULL OR risk_level = ''");
        }

        upgrade_plugin_savepoint(true, 2026031801, 'local', 'lmshomepage');
    }

    // ── v2.1.0 → v2.2.0 ─────────────────────────────────────────────────────
    // Create the local_lmshomepage_log table for the admin notification report.

    if ($oldversion < 2026031802) {
        $table = new xmldb_table('local_lmshomepage_log');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id',              XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('timesent',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('student_name',    XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('student_email',   XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',        XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('course_name',     XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('activity_name',   XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('risk_level',      XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, 'low');
            $table->add_field('percentage',      XMLDB_TYPE_INTEGER, '3',   null, XMLDB_NOTNULL, null, '0');
            $table->add_field('kpi_threshold',   XMLDB_TYPE_INTEGER, '3',   null, XMLDB_NOTNULL, null, '80');
            $table->add_field('recipient_type',  XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, 'student');
            $table->add_field('recipient_userid',XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('recipient_name',  XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('subject',         XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('timesent',        XMLDB_INDEX_NOTUNIQUE, ['timesent']);
            $table->add_index('userid_courseid', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid']);
            $table->add_index('risk_level',      XMLDB_INDEX_NOTUNIQUE, ['risk_level']);
            $table->add_index('recipient_type',  XMLDB_INDEX_NOTUNIQUE, ['recipient_type']);

            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026031802, 'local', 'lmshomepage');
    }

    // ── v2.2.0 → v2.3.0 ─────────────────────────────────────────────────────
    // Migrate Moodle hook registration from deprecated before_standard_html_head
    // to before_standard_head_html_generation (Moodle 4.3+ Hooks API).
    // Remove lib.php legacy callback that caused process_legacy_callbacks() to
    // emit a deprecation notice on every admin page load.
    // No DB schema changes — savepoint required for Moodle to record the upgrade.

    if ($oldversion < 2026032401) {
        upgrade_plugin_savepoint(true, 2026032401, 'local', 'lmshomepage');
    }

    // ── v2.3.0 → v2.3.1 ─────────────────────────────────────────────────────
    // Fix fatal error on upgrade: removed MESSAGE_OUTPUT_EMAIL constant from
    // db/messages.php — that constant does not exist in Moodle 4.x and caused
    // "Undefined constant MESSAGE_OUTPUT_EMAIL" crashing upgrade_noncore().

    if ($oldversion < 2026032402) {
        upgrade_plugin_savepoint(true, 2026032402, 'local', 'lmshomepage');
    }

    // ── v2.3.2 → v2.3.3 ─────────────────────────────────────────────────────
    // Fix: wrap each attendance record check in a try-catch so orphaned records
    // (attendance rows whose course was deleted) no longer kill the entire task.
    // Also wrap message_send() to survive messaging subsystem misconfiguration.
    // No DB schema changes.

    if ($oldversion < 2026040101) {
        upgrade_plugin_savepoint(true, 2026040101, 'local', 'lmshomepage');
    }

    // ── v2.4.0 → v2.4.1 ─────────────────────────────────────────────────────
    // Automatically grant all capabilities required by the LMS Homepage Dashboard
    // webservice token to the Manager role and any webservice-named roles.
    // This removes the need for manual capability assignment after install.
    // The helper is defined in db/install.php and included below so it is
    // available to both install and upgrade paths.

    if ($oldversion < 2026042501) {
        require_once($CFG->dirroot . '/local/lmshomepage/db/install.php');
        local_lmshomepage_grant_required_capabilities();
        upgrade_plugin_savepoint(true, 2026042501, 'local', 'lmshomepage');
    }

    // ── v2.4.1 → v2.4.2 ─────────────────────────────────────────────────────
    // Add core_course_get_contents to the LMS Homepage Dashboard service.
    // This function is required to discover attendance module instance IDs
    // within each course (replaces the deprecated mod_attendance_get_attendances).

    if ($oldversion < 2026042502) {
        $service = $DB->get_record('external_services', ['shortname' => 'lmshomepage_dashboard'], 'id');
        if ($service) {
            $exists = $DB->record_exists('external_services_functions', [
                'externalserviceid' => $service->id,
                'functionname'      => 'core_course_get_contents',
            ]);
            if (!$exists) {
                $DB->insert_record('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname'      => 'core_course_get_contents',
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026042502, 'local', 'lmshomepage');
    }

    // ── v2.4.2 → v2.5.1 ─────────────────────────────────────────────────────
    // Add four new custom external functions and block_xp_get_leaderboard to
    // the LMS Homepage Dashboard service:
    //   local_lmshomepage_get_active_user_count
    //   local_lmshomepage_get_course_completion_counts
    //   local_lmshomepage_get_monthly_active_user_count
    //   local_lmshomepage_get_enrolled_students
    //   block_xp_get_leaderboard
    // Moodle automatically picks up new entries in db/services.php $functions
    // during upgrade, but the service function rows must be explicitly inserted
    // for existing installations that already have the service created.

    if ($oldversion < 2026042601) {
        $service = $DB->get_record('external_services', ['shortname' => 'lmshomepage_dashboard'], 'id');
        if ($service) {
            $new_functions = [
                'local_lmshomepage_get_active_user_count',
                'local_lmshomepage_get_course_completion_counts',
                'local_lmshomepage_get_monthly_active_user_count',
                'local_lmshomepage_get_enrolled_students',
                'block_xp_get_leaderboard',
            ];
            foreach ($new_functions as $fname) {
                $exists = $DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname'      => $fname,
                ]);
                if (!$exists) {
                    $DB->insert_record('external_services_functions', [
                        'externalserviceid' => $service->id,
                        'functionname'      => $fname,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026042601, 'local', 'lmshomepage');
    }

    // ── v2.5.1 → v2.5.2 ─────────────────────────────────────────────────────
    // (Intermediate version — no DB schema changes.)
    if ($oldversion < 2026042602) {
        upgrade_plugin_savepoint(true, 2026042602, 'local', 'lmshomepage');
    }

    // ── v2.5.2 → v2.5.3 ─────────────────────────────────────────────────────
    // Revert get_active_user_count SQL back to the correct simple query:
    //   mdl_user WHERE suspended=0 AND deleted=0 AND id!=1 AND username!='guest'
    // This returns the true non-suspended user count (e.g. 1,439 at ITLC) which
    // is the intended "Active / not suspended" metric. The v2.5.2 change to
    // join user_enrolments was incorrect — the plain user count is right.
    // No DB schema changes.

    if ($oldversion < 2026042603) {
        upgrade_plugin_savepoint(true, 2026042603, 'local', 'lmshomepage');
    }

    // ── v2.5.3 → v2.6.0 ─────────────────────────────────────────────────────
    // Add VET Reporting capability to the plugin.
    //
    // DB changes:
    //   • Create local_lmshomepage_commhub — CommHub communication log table
    //     used by all three notification automations (M7, M8, M9).
    //
    // Service changes:
    //   • Add 8 new custom VET reporting functions to the lmshomepage_dashboard
    //     service (for sites already running an older version of the plugin).
    //   • Add core_cohort_get_cohorts and core_cohort_get_cohort_members.
    //
    // Functions added:
    //   local_lmshomepage_get_student_inactivity
    //   local_lmshomepage_get_completed_units
    //   local_lmshomepage_get_assessment_submissions
    //   local_lmshomepage_get_cohort_matrix
    //   local_lmshomepage_get_trainer_allocations
    //   local_lmshomepage_log_commhub
    //   local_lmshomepage_get_trainers
    //   local_lmshomepage_get_admin_emails
    //   core_cohort_get_cohorts
    //   core_cohort_get_cohort_members

    if ($oldversion < 2026042604) {

        // ── 1. Create local_lmshomepage_commhub table ─────────────────────────
        $table = new xmldb_table('local_lmshomepage_commhub');

        if (!$dbman->table_exists($table)) {
            $table->add_field('id',               XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('timesent',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('studentid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('student_name',     XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('student_email',    XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('trainerid',        XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('trainer_name',     XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid',         XMLDB_TYPE_INTEGER, '10',  null, XMLDB_NOTNULL, null, '0');
            $table->add_field('course_name',      XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('assessment_name',  XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('channel',          XMLDB_TYPE_CHAR,    '10',  null, XMLDB_NOTNULL, null, 'email');
            $table->add_field('message_subject',  XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('message_body',     XMLDB_TYPE_TEXT,    null,  null, XMLDB_NOTNULL, null, null);
            $table->add_field('status',           XMLDB_TYPE_CHAR,    '20',  null, XMLDB_NOTNULL, null, 'sent');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('timesent',          XMLDB_INDEX_NOTUNIQUE, ['timesent']);
            $table->add_index('studentid',         XMLDB_INDEX_NOTUNIQUE, ['studentid']);
            $table->add_index('trainerid',         XMLDB_INDEX_NOTUNIQUE, ['trainerid']);
            $table->add_index('channel',           XMLDB_INDEX_NOTUNIQUE, ['channel']);
            $table->add_index('status',            XMLDB_INDEX_NOTUNIQUE, ['status']);

            $dbman->create_table($table);
        }

        // ── 2. Add new functions to lmshomepage_dashboard service ─────────────
        // Moodle automatically picks up $functions entries on fresh install, but
        // existing installations that already have the service created need the
        // rows inserted explicitly into external_services_functions.
        $service = $DB->get_record('external_services', ['shortname' => 'lmshomepage_dashboard'], 'id');
        if ($service) {
            $new_functions = [
                'local_lmshomepage_get_student_inactivity',
                'local_lmshomepage_get_completed_units',
                'local_lmshomepage_get_assessment_submissions',
                'local_lmshomepage_get_cohort_matrix',
                'local_lmshomepage_get_trainer_allocations',
                'local_lmshomepage_log_commhub',
                'local_lmshomepage_get_trainers',
                'local_lmshomepage_get_admin_emails',
                'core_cohort_get_cohorts',
                'core_cohort_get_cohort_members',
            ];
            foreach ($new_functions as $fname) {
                $exists = $DB->record_exists('external_services_functions', [
                    'externalserviceid' => $service->id,
                    'functionname'      => $fname,
                ]);
                if (!$exists) {
                    $DB->insert_record('external_services_functions', [
                        'externalserviceid' => $service->id,
                        'functionname'      => $fname,
                    ]);
                }
            }
        }

        upgrade_plugin_savepoint(true, 2026042604, 'local', 'lmshomepage');
    }

    // ── v2.6.0 → v2.6.1 ─────────────────────────────────────────────────────
    // Removed hard mod_attendance dependency from version.php so the plugin
    // installs cleanly on sites without the attendance plugin.
    // No schema changes in this release.
    if ($oldversion < 2026042605) {
        upgrade_plugin_savepoint(true, 2026042605, 'local', 'lmshomepage');
    }

    // ── v2.6.1 → v2.6.2 ─────────────────────────────────────────────────────
    // Fix dml_read_exception on get_student_inactivity, get_assessment_submissions,
    // get_trainer_allocations, get_completed_units: trainer LEFT JOIN is now always
    // included (using fieldid=-1 fallback) so trainer.* aliases in SELECT/GROUP BY
    // are always valid even when the wombat_trainer profile field doesn't exist.
    // No schema changes in this release.
    if ($oldversion < 2026042606) {
        upgrade_plugin_savepoint(true, 2026042606, 'local', 'lmshomepage');
    }

    // ── v2.6.2 → v2.6.3 ─────────────────────────────────────────────────────
    // Fix remaining dml_read_exceptions:
    // - get_student_inactivity: replaced UNIX_TIMESTAMP() with PHP :now_ts param;
    //   added u.firstaccess to GROUP BY to satisfy ONLY_FULL_GROUP_BY strict mode.
    // - get_cohort_matrix: removed broken stub `gs` subquery (gs.name referenced a
    //   non-existent column); always set :assign_module_id param (use -1 fallback).
    // No schema changes in this release.
    if ($oldversion < 2026042607) {
        upgrade_plugin_savepoint(true, 2026042607, 'local', 'lmshomepage');
    }

    // ── v2.6.3 → v2.6.4 ─────────────────────────────────────────────────────
    // Fix get_trainers studentcount: when the wombat_trainer custom profile field
    // does not exist (fieldId = 0), fall back to counting unique students enrolled
    // in courses where the trainer has an editingteacher / teacher role. This
    // provides real student counts without requiring Moodle admin profile field
    // setup. When the profile field IS configured, the explicit allocation count
    // is used instead (existing behaviour). No schema changes.
    if ($oldversion < 2026042608) {
        upgrade_plugin_savepoint(true, 2026042608, 'local', 'lmshomepage');
    }

    // ── v2.6.4 → v2.6.5 ─────────────────────────────────────────────────────
    // Replace wombat_trainer custom profile field dependency across all WS
    // functions with role-assignment-based teacher lookup:
    //   get_student_inactivity, get_trainer_allocations, get_assessment_submissions,
    //   get_completed_units — all now derive trainer_name / trainer_id from the
    //   editingteacher / teacher role in the student's enrolled courses.
    // Also: get_completed_units unit_code now falls back to c.shortname when
    //   cm.idnumber is empty, so unit codes appear without any Moodle admin config.
    // No schema changes.
    if ($oldversion < 2026042609) {
        upgrade_plugin_savepoint(true, 2026042609, 'local', 'lmshomepage');
    }

    // ── v2.6.5 → v2.6.6 ─────────────────────────────────────────────────────
    // Add two completion WS functions to the existing LMS Homepage Dashboard
    // service so that tokens issued before this release gain permission to call
    // them.  Previously these functions were missing from the service definition,
    // causing every completion-status call to fail with an access exception and
    // silently return 0% progress for every student course.
    //
    // Functions added:
    //   core_completion_get_activities_completion_status — activity-level
    //     completion progress (matches Moodle My Courses / Dashboard progress bar)
    //   core_completion_get_course_completion_status — overall course
    //     complete/not-complete flag (drives the 100% override)
    if ($oldversion < 2026042610) {
        $servicerecord = $DB->get_record('external_services', ['shortname' => 'lmshomepage_dashboard']);
        if ($servicerecord) {
            $functionsToAdd = [
                'core_completion_get_activities_completion_status',
                'core_completion_get_course_completion_status',
            ];
            foreach ($functionsToAdd as $fname) {
                $already = $DB->record_exists('external_services_functions', [
                    'externalserviceid' => $servicerecord->id,
                    'functionname'      => $fname,
                ]);
                if (!$already) {
                    $DB->insert_record('external_services_functions', (object)[
                        'externalserviceid' => $servicerecord->id,
                        'functionname'      => $fname,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026042610, 'local', 'lmshomepage');
    }

    // ── v2.6.7 ───────────────────────────────────────────────────────────────
    // Ensure core_completion_get_activities_completion_status is authorised in
    // the LMS Homepage Dashboard service. The v2.6.6 upgrade block added both
    // completion functions, but if core_completion_get_course_completion_status
    // was already present the INSERT for the activities function may have been
    // skipped on some installs. This block is idempotent — safe to run even if
    // the function is already there.
    if ($oldversion < 2026042611) {
        $servicerecord = $DB->get_record('external_services', ['shortname' => 'lmshomepage_dashboard']);
        if ($servicerecord) {
            $fn = 'core_completion_get_activities_completion_status';
            if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $servicerecord->id,
                    'functionname'      => $fn,
            ])) {
                $DB->insert_record('external_services_functions', (object)[
                    'externalserviceid' => $servicerecord->id,
                    'functionname'      => $fn,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026042611, 'local', 'lmshomepage');
    }

    // ── v2.6.8 ───────────────────────────────────────────────────────────────
    // CSS-only fix: hides the Moodle page/course header and secondary navigation
    // tabs that appeared twice above the widget. No DB changes needed.
    if ($oldversion < 2026042612) {
        upgrade_plugin_savepoint(true, 2026042612, 'local', 'lmshomepage');
    }

    // ── v2.6.9 ───────────────────────────────────────────────────────────────
    // Three fixes (no schema changes):
    //   1. install.xml — added local_lmshomepage_commhub table definition so
    //      fresh installs create the table correctly (was only created via the
    //      upgrade block, which does not run on clean install).
    //   2. get_cohort_matrix.php — replaced UNIX_TIMESTAMP() with a PHP :now_ts
    //      parameter for cross-database (PostgreSQL) compatibility.
    //   3. get_cohort_matrix.php — unit_code now falls back to c.shortname when
    //      cm.idnumber is empty, consistent with get_completed_units.
    if ($oldversion < 2026042613) {
        upgrade_plugin_savepoint(true, 2026042613, 'local', 'lmshomepage');
    }

    // ── v2.7.0 ───────────────────────────────────────────────────────────────
    // Critical fix: the before_footer_html_generation hook (and its Moodle 4.x
    // equivalent) was firing multiple times per page request in Moodle 5.x
    // (triggered by modal/AJAX dialog renders). Each invocation appended another
    // #lms-homepage-widget div + script tag to the DOM, causing the widget to
    // render a duplicate "Welcome to Wombat!" card for every modal dismissal.
    //
    // Fix: added `static $done = false` guards to before_footer() and
    // before_standard_html_head() in hook_callbacks.php, and to
    // local_lmshomepage_before_footer() in lib.php. The hook now injects the
    // widget exactly once per PHP request regardless of how many times the hook
    // is dispatched. No schema changes.
    if ($oldversion < 2026042614) {
        upgrade_plugin_savepoint(true, 2026042614, 'local', 'lmshomepage');
    }

    // ── v2.7.1 ───────────────────────────────────────────────────────────────
    // Moodle 4/5 compatibility fix for the duplicate widget bug.
    //
    // On Moodle 4.3–4.5, BOTH the legacy lib.php before_footer callback AND
    // the Hooks API before_footer_html_generation callback fire within the same
    // HTTP request.  The v2.7.0 static guards in each method used separate PHP
    // static variables, so they did NOT prevent the cross-path duplication.
    //
    // Fix: moved the authoritative `static $done` guard into widget_html()
    // itself.  Since both lib.php AND hook_callbacks.php call this single
    // function, the flag is shared across all call paths and all Moodle
    // versions:
    //   Moodle 4.0–4.2  → only lib.php before_footer fires   → 1 injection ✓
    //   Moodle 4.3–4.5  → lib.php + Hooks API both fire       → 1 injection ✓
    //   Moodle 5.0+     → only Hooks API fires (possibly N×)  → 1 injection ✓
    // No schema changes.
    if ($oldversion < 2026042615) {
        upgrade_plugin_savepoint(true, 2026042615, 'local', 'lmshomepage');
    }

    // ── v2.7.2 ───────────────────────────────────────────────────────────────
    // Simplest correct fix for the duplicate widget issue: do not activate the
    // plugin at all for logged-out or guest users.  is_active() in both
    // hook_callbacks.php and lib.php now returns false when !isloggedin() or
    // isguestuser().  Logged-out visitors see Moodle's normal front page /
    // login redirect unchanged.  The widget (and the CSS overrides) only fire
    // after a real user has authenticated — at which point the "already logged
    // in" confirm dialog cannot appear, eliminating the duplication trigger.
    // No schema changes.
    if ($oldversion < 2026042616) {
        upgrade_plugin_savepoint(true, 2026042616, 'local', 'lmshomepage');
    }

    // ── v2.7.3 ───────────────────────────────────────────────────────────────
    // Definitive fix for the duplicate widget bug on all themes (Wombat custom
    // and Academi) and all Moodle versions (4.0 – 5.x).
    //
    // Root cause: Moodle delivers the widget code block to the browser MORE
    // THAN ONCE per page session via AJAX fragment responses — specifically
    // when the "already logged in" confirm dialog is shown by fetching
    // /login/index.php as an AJAX fragment. PHP-level static guards cannot
    // stop this because each fragment is a fresh HTTP response the browser
    // appends directly to the live DOM.
    //
    // Fix: widget_html() now outputs a self-contained JavaScript IIFE instead
    // of a static <div> + <script> tag. The IIFE checks
    // document.getElementById('lms-homepage-widget') before doing anything —
    // if the widget already exists it returns immediately with no side effects.
    // This guard runs in the browser and is therefore immune to how many times
    // the server-side code fires or how many AJAX fragments include it.
    //
    // All data attributes are now JSON-encoded (not HTML-encoded) so the values
    // are safe for JavaScript embedding on all themes. No schema changes.
    if ($oldversion < 2026042617) {
        upgrade_plugin_savepoint(true, 2026042617, 'local', 'lmshomepage');
    }

    // ── v2.7.4 ───────────────────────────────────────────────────────────────
    // Fix widget not displaying at all (regression introduced in v2.7.3).
    //
    // v2.7.3 used an inline JS IIFE as the idempotency guard, but Moodle 4.3+
    // enforces a Content Security Policy that blocks inline <script> blocks
    // without a nonce. The IIFE was silently blocked by CSP, so no widget div
    // was ever created and nothing rendered.
    //
    // v2.7.4 reverts to the original <div data-...> + <script src defer>
    // approach (which is CSP-safe because both are external/attribute-based)
    // and adds a NEW external guard file: widget_guard.js served directly from
    // the plugin directory (same Moodle origin — always allowed by CSP).
    //
    // widget_guard.js uses a window.__lmshp_guard flag (prevents re-execution
    // if the script tag is injected a second time via DOM manipulation) and a
    // MutationObserver (watches for and immediately removes any duplicate
    // #lms-homepage-widget divs added by AJAX fragment responses). This works
    // on Moodle 4.0–5.x, Academi theme, and the Wombat custom theme.
    // No schema changes.
    if ($oldversion < 2026042618) {
        upgrade_plugin_savepoint(true, 2026042618, 'local', 'lmshomepage');
    }

    // ── v2.7.5 ───────────────────────────────────────────────────────────────
    // Restore the `static $rendered` guard inside widget_html() that was
    // documented as added in v2.7.1 but was silently lost during the v2.7.3
    // IIFE rewrite and not restored when v2.7.4 reverted the IIFE.
    //
    // Without this guard, on Moodle 4.3–4.5 both the legacy lib.php callback
    // (local_lmshomepage_before_footer) and the Hooks API callback
    // (hook_callbacks::before_footer) call widget_html() in the same PHP
    // request. Each caller has its own separate `static $done` variable, so
    // they cannot block each other's call — widget_html() executes twice,
    // producing TWO copies of the widget block in the HTML response. The
    // browser then has two #lms-homepage-widget divs and loads two copies of
    // the widget script (which may run once or twice depending on browser
    // script caching behaviour).
    //
    // The correct guard belongs inside widget_html() itself so it is shared
    // across every call path regardless of which Moodle version or caller
    // is in use:
    //   Moodle 4.0–4.2 → only lib.php fires          → 1 call  ✓
    //   Moodle 4.3–4.5 → lib.php + Hooks API fire     → 1 call  ✓
    //   Moodle 5.0+    → only Hooks API fires          → 1 call  ✓
    //
    // Also fixed in this version:
    // • Stale comment in widget_html() that said "no HTML encoding — values
    //   go into JSON" (left over from the v2.7.3 IIFE approach). The values
    //   DO go into HTML attributes; fullname() is now passed through s() at
    //   the point of assignment, not at the point of use, so the comment and
    //   code are consistent.
    // No schema changes.
    if ($oldversion < 2026042619) {
        upgrade_plugin_savepoint(true, 2026042619, 'local', 'lmshomepage');
    }

    // ── v2.7.6 ───────────────────────────────────────────────────────────────
    // Two fixes — no schema changes:
    //
    // 1. is_active() blank-page fix for custom themes (e.g. Wombat).
    //    Root cause: some Moodle themes (including Wombat's custom theme)
    //    render the site home as a COURSE VIEW of the site course (course id=1,
    //    SITEID constant) using the 'course' pagelayout rather than the standard
    //    'frontpage' layout.  In that case $PAGE->pagetype is 'course-view-*'
    //    and $PAGE->pagelayout is 'course', so the previous is_active() check:
    //      ($PAGE->pagetype === 'site-index' || $PAGE->pagelayout === 'frontpage')
    //    returned false for every page visit on those themes — the plugin never
    //    injected CSS or the widget, leaving the front page completely blank.
    //    Fix: added a third condition:
    //      ($PAGE->pagelayout === 'course' && (int)$COURSE->id === SITEID)
    //    This activates the plugin on the site course view while leaving all
    //    regular course pages (SITEID != courseid) unaffected.
    //    Applied to both hook_callbacks::is_active() and lib.php _lmshp_is_active().
    //
    // 2. lib.php local_lmshomepage_before_footer() return-not-echo fix.
    //    Root cause: Moodle's get_plugins_with_function('before_footer') system
    //    collects the RETURN VALUE of each plugin's before_footer function via
    //    $output .= $function () and appends it to the footer string.  The
    //    function was typed void and used echo instead of return — the echo
    //    went to PHP's raw output buffer outside Moodle's footer assembly,
    //    meaning the widget appeared in an unpredictable position or not at
    //    all on some Moodle versions.
    //    Fix: changed return type to string and echo to return.
    if ($oldversion < 2026042620) {
        upgrade_plugin_savepoint(true, 2026042620, 'local', 'lmshomepage');
    }

    // ── v2.7.7 ───────────────────────────────────────────────────────────────
    // CSS fix for custom themes (e.g. Wombat) where the site greeting
    // "Welcome to [Site]!" was not being hidden by the plugin's fullwidth_css().
    //
    // Root cause: the previous selector only hid .card elements INSIDE #page-header:
    //   #page-header .card{display:none!important;}
    // The Wombat custom theme renders the page-header greeting directly in
    // #page-header (no .card wrapper), so that selector had no effect.
    //
    // Fix: added #page-header itself to the hide list, plus .activity-header
    // and .course-header for broader custom-theme coverage.
    // No schema changes.
    if ($oldversion < 2026042621) {
        upgrade_plugin_savepoint(true, 2026042621, 'local', 'lmshomepage');
    }

    // ── v2.7.8 ───────────────────────────────────────────────────────────────
    // Wombat theme: widget was injected into <footer id="page-footer"> instead
    // of the content area, making it invisible below the page.
    //
    // Root cause: the Wombat custom theme renders {{{output.beforefooter}}}
    // inside the <footer> element. Our #lms-homepage-widget div therefore
    // landed inside #page-footer (below the sidebar/content-area layout),
    // not in the main content area where it should appear.
    //
    // Fix 1 (JS): moodle-widget.js now detects when the container is inside
    // #page-footer and relocates it into div[role="main"] (the empty Moodle
    // content area) before creating the iframe.
    //
    // Fix 2 (CSS): fullwidth_css() now explicitly hides all Wombat-specific
    // chrome — .wombat-custom-navbar (the greeting bar), .wombat-sidebar,
    // #wombat-menu-toggle — and removes padding from .wombat-layout-row and
    // .wombat-content-area so the relocated iframe fills the page cleanly.
    // No schema changes.
    if ($oldversion < 2026042622) {
        upgrade_plugin_savepoint(true, 2026042622, 'local', 'lmshomepage');
    }

    // v2.7.9: Hide Moodle top navbar (nav.navbar) and zero out the
    // padding-top it leaves on #page/body — removes blank space above widget.
    if ($oldversion < 2026042623) {
        upgrade_plugin_savepoint(true, 2026042623, 'local', 'lmshomepage');
    }

    // v2.7.10: More aggressive top-gap removal — zero html/body/#page/#page-wrapper
    // padding-top/margin-top; also hide #nav-drawer and Wombat header variants.
    if ($oldversion < 2026042624) {
        upgrade_plugin_savepoint(true, 2026042624, 'local', 'lmshomepage');
    }

    // v2.7.11: Hide stub <br> in div[role="main"] via CSS; JS scrubStubBr()
    // removes it at runtime for themes where relocate() doesn't fire (Wombat).
    if ($oldversion < 2026042625) {
        upgrade_plugin_savepoint(true, 2026042625, 'local', 'lmshomepage');
    }

    // v2.7.12: Show Moodle top navbar again — removed nav.navbar/.navbar/#nav-drawer
    // from display:none and removed the padding-top:0 override on html/body so the
    // standard Moodle navigation bar is visible above the portal widget.
    if ($oldversion < 2026042626) {
        upgrade_plugin_savepoint(true, 2026042626, 'local', 'lmshomepage');
    }

    // v2.7.13: Detect Moodle "Log in as" session and force role=learner so admins
    // impersonating a student always see the learner portal view, not admin view.
    if ($oldversion < 2026042627) {
        upgrade_plugin_savepoint(true, 2026042627, 'local', 'lmshomepage');
    }

    // v2.7.14: Zero padding-top/margin-top on body/#page-wrapper/#page for
    // body.wombatlms-com-au to remove the gap left by the hidden fixed navbar.
    if ($oldversion < 2026042628) {
        upgrade_plugin_savepoint(true, 2026042628, 'local', 'lmshomepage');
    }

    // v2.7.15: Add two new VET reporting web service functions:
    //   local_lmshomepage_get_vet_grade_report   — grade data with C/NYC/RPL/CT
    //   local_lmshomepage_get_attendance_report  — attendance summary with at-risk
    // Also adds core_group_get_course_groups and core_group_get_group_members to
    // the LMS Homepage Dashboard service for group-based report filtering.
    if ($oldversion < 2026042629) {
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'lmshomepage_dashboard']);
        if ($serviceid) {
            $newFunctions = [
                'local_lmshomepage_get_vet_grade_report',
                'local_lmshomepage_get_attendance_report',
                'core_group_get_course_groups',
                'core_group_get_group_members',
            ];
            foreach ($newFunctions as $fn) {
                if (!$DB->record_exists('external_services_functions', ['externalserviceid' => $serviceid, 'functionname' => $fn])) {
                    $DB->insert_record('external_services_functions', [
                        'externalserviceid' => $serviceid,
                        'functionname'      => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026042629, 'local', 'lmshomepage');
    }

    // v2.7.16: Add three missing core/mod functions that were called by the
    // LMS Hosting Services backend but absent from the service definition,
    // causing "access denied" errors for Signature and ITLC clients:
    //   mod_assign_get_assignments  — Signature at-risk + submissions checks
    //   mod_assign_get_submissions  — Signature per-student submission status
    //   core_course_get_categories  — ITLC course catalogue category grouping
    if ($oldversion < 2026042630) {
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'lmshomepage_dashboard']);
        if ($serviceid) {
            $newFunctions = [
                'mod_assign_get_assignments',
                'mod_assign_get_submissions',
                'core_course_get_categories',
            ];
            foreach ($newFunctions as $fn) {
                if (!$DB->record_exists('external_services_functions', ['externalserviceid' => $serviceid, 'functionname' => $fn])) {
                    $DB->insert_record('external_services_functions', [
                        'externalserviceid' => $serviceid,
                        'functionname'      => $fn,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026042630, 'local', 'lmshomepage');
    }

    // v2.7.17: Percentage-based grade competency rule changed from ≥50% → C
    // to 100% → C (anything less = NYC). VET qualifications require a perfect
    // score to be deemed Competent — partial marks are Not Yet Competent.
    // No schema changes.
    if ($oldversion < 2026042631) {
        upgrade_plugin_savepoint(true, 2026042631, 'local', 'lmshomepage');
    }

    // v2.7.18: Cohort matrix improvements:
    //   1. Enrolment method broadened — no longer restricted to cohort-enrol only;
    //      now includes manually enrolled students, fixing missing units.
    //   2. Unit name now resolves assign AND quiz activity names (quiz fallback).
    //   3. CT/RPL detection fixed — scale CSV read from {scale}.scale and parsed
    //      by grade position (was always blank before, forcing all to "C").
    // No schema changes; all logic in get_cohort_matrix.php.
    if ($oldversion < 2026042632) {
        upgrade_plugin_savepoint(true, 2026042632, 'local', 'lmshomepage');
    }

    // v2.7.19: Critical data accuracy fix — Completed Units report and dashboard KPI:
    //   BEFORE: get_completed_units queried mdl_course_modules_completion (activity-level),
    //           producing 20,000+ rows and inflated "Units Completed (30d)" KPI counts.
    //   AFTER:  get_completed_units now queries mdl_course_completions (course-level).
    //           In Wombat's Moodle, each VET unit = 1 Moodle course.  One row per
    //           student per fully-completed course.  Unique index prevents duplicates.
    //   Also:   get_course_completion_counts gains a since_timestamp parameter so the
    //           dashboard KPI correctly counts VET unit completions in the last 30 days
    //           from mdl_course_completions — not raw activity completion events.
    // No schema changes; all logic in get_completed_units.php and
    // get_course_completion_counts.php.
    if ($oldversion < 2026042633) {
        upgrade_plugin_savepoint(true, 2026042633, 'local', 'lmshomepage');
    }

    // v2.7.20: Pass data-userid and data-firstname from Moodle to widget iframe.
    // No schema changes — hook_callbacks.php now emits two extra data attributes
    // (data-userid, data-firstname) so the portal can load student courses by
    // numeric user ID (core_enrol_get_users_courses) instead of by username.
    if ($oldversion < 2026042634) {
        upgrade_plugin_savepoint(true, 2026042634, 'local', 'lmshomepage');
    }

    // ── v2.8.0 ───────────────────────────────────────────────────────────────
    // Bug fixes following Wombat dashboard review (3 July 2026):
    //
    // 1. Dashboard KPI — Units Completed: removed since_timestamp filter from
    //    get_course_completion_counts so the KPI shows all-time total (not last
    //    30 days).  Prevents misleading zero when no completions in last month.
    //
    // 2. Completed Units report — unit_name "—": get_completed_units now uses
    //    COALESCE(NULLIF(c.fullname,''), c.shortname) so course full names that
    //    are blank in Moodle fall back to the shortname instead of rendering "—".
    //
    // 3. Completed Units report — duplicate rows: replaced the main-query
    //    JOIN {user_enrolments} with a deduplication subquery (MIN timestart
    //    per userid+courseid) so students with multiple active enrolments in
    //    the same course produce exactly one row.
    //
    // 4. Cohort Progress Matrix — activity names instead of course names:
    //    get_cohort_matrix restructured from student×activity to student×course
    //    granularity.  unit_name now = c.fullname (VET unit name).  outcome_code
    //    derived from course_completions (C) or activity progress (IP/NYS).
    //    Removes the JOIN {course_modules} requirement, so all enrolled courses
    //    appear regardless of whether individual activities have completion
    //    tracking enabled.
    //
    // 5. HLT47321 cohort 10-unit limit: resolved by fix #4 above.
    //
    // 6. Trainer allocation — use "Your Trainer Is" profile field: both
    //    get_completed_units and get_trainer_allocations now resolve trainer_name
    //    from the 'wombat_trainer' custom profile field instead of role
    //    assignments.  Falls back to fieldid=-1 when the field is absent.
    //
    // No DB schema changes in this release.
    if ($oldversion < 2026042635) {
        upgrade_plugin_savepoint(true, 2026042635, 'local', 'lmshomepage');
    }

    // ── v2.8.1 ───────────────────────────────────────────────────────────────
    // get_assessment_submissions: removed cm.completion > 0 filter so that
    // assignments are returned even when site-level completion tracking is
    // disabled (e.g. CompAP).  No DB schema changes.
    if ($oldversion < 2026042636) {
        upgrade_plugin_savepoint(true, 2026042636, 'local', 'lmshomepage');
    }

    // ── v2.8.2 ───────────────────────────────────────────────────────────────
    // get_assessment_submissions: added require_completion parameter (default 1).
    // When 1 (default): keeps cm.completion > 0 — safe/unchanged for all existing
    // sites (Wombat etc).  When 0: returns all assignments regardless of completion
    // tracking (used by CompAP where site-level completion is disabled).
    // No DB schema changes.
    if ($oldversion < 2026042637) {
        upgrade_plugin_savepoint(true, 2026042637, 'local', 'lmshomepage');
    }

    // ── v2.8.3 ───────────────────────────────────────────────────────────────
    // Role detection: added user_has_manager_role() archetype check to the admin
    // detection block in hook_callbacks.php.  Custom roles based on the manager
    // archetype (e.g. "LMS Hosting Admin") now reliably receive role='admin' even
    // when capability inheritance is incomplete or the role is assigned below
    // system context.  No DB schema changes.
    if ($oldversion < 2026042638) {
        upgrade_plugin_savepoint(true, 2026042638, 'local', 'lmshomepage');
    }

    // ── v2.8.4 ───────────────────────────────────────────────────────────────
    // Fix Trainer Grade Report trainer filter: when filtering by trainer_userid,
    // the t_map subquery now restricts to that specific trainer so MIN(userid)
    // resolves to them. Previously MIN() always picked the lowest-ID trainer in
    // multi-trainer courses, making the filter return 0 rows for any trainer
    // who was not the lowest-ID teacher in their courses.  No DB schema changes.
    if ($oldversion < 2026042639) {
        upgrade_plugin_savepoint(true, 2026042639, 'local', 'lmshomepage');
    }

    // ── v2.8.5 ───────────────────────────────────────────────────────────────
    // Hardened user_has_manager_role() with three independent checks:
    //   1. get_archetype_roles('manager') API (existing)
    //   2. Direct DB JOIN on role.archetype='manager' — bypasses stale cache
    //   3. has_capability('moodle/site:viewreports', system context) fallback
    // This ensures custom "LMS Hosting Admin" roles (manager archetype) always
    // receive role='admin' in the dashboard even if check 1 returns stale data.
    // No DB schema changes.
    if ($oldversion < 2026042640) {
        upgrade_plugin_savepoint(true, 2026042640, 'local', 'lmshomepage');
    }

    // ── v2.8.6 ───────────────────────────────────────────────────────────────
    // Added timecreated (ue.timecreated) to get_enrolled_students response.
    // Allows server to compute "Enrolled This Month" KPI by filtering on the
    // enrolment creation timestamp client-side without an extra Moodle call.
    // No DB schema changes.
    if ($oldversion < 2026042641) {
        upgrade_plugin_savepoint(true, 2026042641, 'local', 'lmshomepage');
    }

    // ── v2.8.7 ───────────────────────────────────────────────────────────────
    // get_assessment_submissions: switched trainer source from editingteacher/
    // teacher role assignment (course-level) to wombat_trainer custom profile
    // field — matching get_trainer_allocations.  Both reports now use the same
    // source of truth.  Falls back to trainer_id=0 / empty name when the field
    // does not exist or is not populated.  No DB schema changes.
    if ($oldversion < 2026042642) {
        upgrade_plugin_savepoint(true, 2026042642, 'local', 'lmshomepage');
    }

    // ── v2.8.8 ───────────────────────────────────────────────────────────────
    // get_attendance_report: two bug fixes:
    // 1. Row-multiplication fix — students in multiple groups or cohorts
    //    produced N duplicate rows per session (one per group/cohort membership),
    //    inflating total_sessions, missed_sessions, and attended_sessions by N.
    //    Fix: PHP aggregation now tracks seen session_ids per student and skips
    //    any duplicate row (same userid + attendance_id + session_id).
    // 2. Future-session exclusion — sessions with sessdate > NOW were included
    //    in total_sessions, deflating attendance_pct.
    //    Fix: added AND sess.sessdate <= :now2 to the JOIN condition.
    // No DB schema changes.
    if ($oldversion < 2026042643) {
        upgrade_plugin_savepoint(true, 2026042643, 'local', 'lmshomepage');
    }

    // ── v2.8.9 ───────────────────────────────────────────────────────────────
    // Add local_lmshomepage_get_user_courses: single-call bulk endpoint that
    // replaces 3 requests per learner page load:
    //   gradereport_overview_get_course_grades (course discovery)
    //   + N × core_completion_get_course_completion_status
    //   + N × core_completion_get_activities_completion_status
    // Returns enrolled courses with completion status + activity % in one query.
    // No DB schema changes.
    if ($oldversion < 2026042644) {
        $svc = $DB->get_record('external_services', ['shortname' => 'lmshomepage_dashboard']);
        if ($svc) {
            $exists = $DB->record_exists('external_services_functions', [
                'externalserviceid' => $svc->id,
                'functionname'      => 'local_lmshomepage_get_user_courses',
            ]);
            if (!$exists) {
                $DB->insert_record('external_services_functions', [
                    'externalserviceid' => $svc->id,
                    'functionname'      => 'local_lmshomepage_get_user_courses',
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026042644, 'local', 'lmshomepage');
    }

    // ── v2.9.0 ───────────────────────────────────────────────────────────────
    // get_cohort_matrix: now filters courses via cohort-sync enrolment only,
    // so each cohort shows only its own courses instead of every course its
    // members happen to be enrolled in via any method.
    if ($oldversion < 2026042645) {
        upgrade_plugin_savepoint(true, 2026042645, 'local', 'lmshomepage');
    }

    // ── v2.9.1 ───────────────────────────────────────────────────────────────
    // get_trainer_allocations: rewritten to derive trainer from course groups
    // instead of the wombat_trainer custom profile field, which was never
    // created in Moodle.  No schema changes required.
    if ($oldversion < 2026042646) {
        upgrade_plugin_savepoint(true, 2026042646, 'local', 'lmshomepage');
    }

    // ── v2.9.2 ───────────────────────────────────────────────────────────────
    // get_cohort_matrix: outcome_code now returns RPL / CT / NA when all units
    // in block_trainingplan_userseq carry that outcome for the student-cohort-course.
    // No schema changes required.
    if ($oldversion < 2026042647) {
        upgrade_plugin_savepoint(true, 2026042647, 'local', 'lmshomepage');
    }

    // ── v2.9.3 ───────────────────────────────────────────────────────────────
    // get_cohort_matrix: added table-exists guard around block_trainingplan_userseq
    // so the function runs safely on sites without the training plan block
    // (Signature, TDT, AIOP etc.) — falls back to literal 0s for all tp fields.
    if ($oldversion < 2026042648) {
        upgrade_plugin_savepoint(true, 2026042648, 'local', 'lmshomepage');
    }

    // ── v2.9.4 ───────────────────────────────────────────────────────────────
    // get_assessment_submissions:
    //   1. Trainer join rewritten to use course groups (same as get_trainer_allocations).
    //   2. New days_lookback parameter (default 0 = no limit) adds a WHERE clause on
    //      a.duedate to cap the query size and prevent PHP timeouts on large sites.
    //      KPI call uses 180 days; full marking report uses 365 days.
    if ($oldversion < 2026042649) {
        upgrade_plugin_savepoint(true, 2026042649, 'local', 'lmshomepage');
    }

    // ── v2.9.5 ───────────────────────────────────────────────────────────────
    // New function: local_lmshomepage_get_completion_report
    // Returns one row per enrolled student with per-activity and course-level
    // INITIAL completion dates for a single course.  Reads directly from:
    //   mdl_course_modules_completion.timecompleted  — initial activity date
    //   mdl_course_completions.timecompleted         — initial course date
    // This bypasses the capability restriction that blocks the standard
    // core_completion_get_activities_completion_status WS function for
    // non-administrator service accounts (e.g. the ITLC webservice token).
    if ($oldversion < 2026042650) {
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'lmshomepage_dashboard']);
        if ($serviceid && !$DB->record_exists('external_services_functions', [
            'externalserviceid' => $serviceid,
            'functionname'      => 'local_lmshomepage_get_completion_report',
        ])) {
            $DB->insert_record('external_services_functions', (object) [
                'externalserviceid' => $serviceid,
                'functionname'      => 'local_lmshomepage_get_completion_report',
                'rpcencoded'        => 0,
            ]);
        }
        upgrade_plugin_savepoint(true, 2026042650, 'local', 'lmshomepage');
    }

    // v2.9.6: Ensure core_course_get_categories is registered in the service.
    // The v2.7.16 block (2026042630) added this but may not have run on sites
    // that were already on a newer version, or where the service lookup failed.
    // This block is idempotent — safe to run on any site at any time.
    if ($oldversion < 2026042651) {
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'lmshomepage_dashboard']);
        if ($serviceid) {
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $serviceid,
                'functionname'      => 'core_course_get_categories',
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $serviceid,
                    'functionname'      => 'core_course_get_categories',
                    'rpcencoded'        => 0,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026042651, 'local', 'lmshomepage');
    }

    // v2.9.7: Re-register core_course_get_categories with a new version stamp.
    if ($oldversion < 2026072001) {
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'lmshomepage_dashboard']);
        if ($serviceid) {
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $serviceid,
                'functionname'      => 'core_course_get_categories',
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $serviceid,
                    'functionname'      => 'core_course_get_categories',
                    'rpcencoded'        => 0,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026072001, 'local', 'lmshomepage');
    }

    // v2.9.8: Bulletproof core_course_get_categories registration.
    // Previous blocks looked up the service ONLY by shortname — if that field
    // differs on the target site the insert silently never ran.
    // Strategy: find the service by ANY of three methods:
    //   1. shortname 'lmshomepage_dashboard'
    //   2. name 'LMS Homepage Dashboard'
    //   3. any service that already has our plugin functions (most robust)
    if ($oldversion < 2026072002) {
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'lmshomepage_dashboard']);
        if (!$serviceid) {
            $serviceid = $DB->get_field('external_services', 'id', ['name' => 'LMS Homepage Dashboard']);
        }
        if (!$serviceid) {
            // Find service that already contains our own plugin functions.
            $serviceid = $DB->get_field_sql(
                "SELECT DISTINCT externalserviceid FROM {external_services_functions}
                  WHERE functionname LIKE 'local_lmshomepage_%' LIMIT 1"
            );
        }
        if ($serviceid) {
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $serviceid,
                'functionname'      => 'core_course_get_categories',
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $serviceid,
                    'functionname'      => 'core_course_get_categories',
                    'rpcencoded'        => 0,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026072002, 'local', 'lmshomepage');
    }

    // v2.9.9: Same as v2.9.8 with fresh version stamp.
    if ($oldversion < 2026072003) {
        $serviceid = $DB->get_field('external_services', 'id', ['shortname' => 'lmshomepage_dashboard']);
        if (!$serviceid) {
            $serviceid = $DB->get_field('external_services', 'id', ['name' => 'LMS Homepage Dashboard']);
        }
        if (!$serviceid) {
            $serviceid = $DB->get_field_sql(
                "SELECT DISTINCT externalserviceid FROM {external_services_functions}
                  WHERE functionname LIKE 'local_lmshomepage_%' LIMIT 1"
            );
        }
        if ($serviceid) {
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $serviceid,
                'functionname'      => 'core_course_get_categories',
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $serviceid,
                    'functionname'      => 'core_course_get_categories',
                    'rpcencoded'        => 0,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026072003, 'local', 'lmshomepage');
    }

    // v2.10.0: Add core_course_get_categories to EVERY service that contains
    // our plugin functions — covers the case where the token uses a manually-
    // created service with a different name/shortname than 'lmshomepage_dashboard'.
    if ($oldversion < 2026072004) {
        $serviceids = $DB->get_fieldset_sql(
            "SELECT DISTINCT externalserviceid FROM {external_services_functions}
              WHERE functionname LIKE 'local_lmshomepage_%'"
        );
        foreach ($serviceids as $sid) {
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $sid,
                'functionname'      => 'core_course_get_categories',
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $sid,
                    'functionname'      => 'core_course_get_categories',
                    'rpcencoded'        => 0,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026072004, 'local', 'lmshomepage');
    }

    // v2.10.1: Add local_lmshomepage_get_all_categories to every service that
    // contains our plugin functions, so hidden categories are accessible without
    // requiring moodle/category:viewhiddencategories on the token user.
    if ($oldversion < 2026072005) {
        $serviceids = $DB->get_fieldset_sql(
            "SELECT DISTINCT externalserviceid FROM {external_services_functions}
              WHERE functionname LIKE 'local_lmshomepage_%'"
        );
        foreach ($serviceids as $sid) {
            if (!$DB->record_exists('external_services_functions', [
                'externalserviceid' => $sid,
                'functionname'      => 'local_lmshomepage_get_all_categories',
            ])) {
                $DB->insert_record('external_services_functions', (object) [
                    'externalserviceid' => $sid,
                    'functionname'      => 'local_lmshomepage_get_all_categories',
                    'rpcencoded'        => 0,
                ]);
            }
        }
        upgrade_plugin_savepoint(true, 2026072005, 'local', 'lmshomepage');
    }

    // v2.11.0: Version bump — no DB schema changes.
    if ($oldversion < 2026072101) {
        upgrade_plugin_savepoint(true, 2026072101, 'local', 'lmshomepage');
    }

    // v2.11.1: Bulletproof re-registration of group and completion functions.
    // Earlier blocks (v2.7.15 and v2.9.5) added these ONLY via shortname lookup
    // ('lmshomepage_dashboard'). If the service was created with a different name
    // or shortname on the target site, those inserts silently never ran — causing
    // "Access control exception" for core_group_get_course_groups and
    // local_lmshomepage_get_completion_report on every call.
    // This block finds ALL services that already contain any local_lmshomepage_*
    // function and ensures the three affected functions are present in each one.
    if ($oldversion < 2026072102) {
        $serviceids = $DB->get_fieldset_sql(
            "SELECT DISTINCT externalserviceid FROM {external_services_functions}
              WHERE functionname LIKE 'local_lmshomepage_%'"
        );
        $ensureFunctions = [
            'core_group_get_course_groups',
            'core_group_get_group_members',
            'local_lmshomepage_get_completion_report',
        ];
        foreach ($serviceids as $sid) {
            foreach ($ensureFunctions as $fn) {
                if (!$DB->record_exists('external_services_functions', [
                    'externalserviceid' => $sid,
                    'functionname'      => $fn,
                ])) {
                    $DB->insert_record('external_services_functions', (object) [
                        'externalserviceid' => $sid,
                        'functionname'      => $fn,
                        'rpcencoded'        => 0,
                    ]);
                }
            }
        }
        upgrade_plugin_savepoint(true, 2026072102, 'local', 'lmshomepage');
    }

    // v2.11.2: Fix UNIX_TIMESTAMP() in get_completion_report enrolled-student
    // query — UNIX_TIMESTAMP() is MySQL-only and throws dmlreadexception on
    // PostgreSQL Moodle installations.  Replaced with a :now named parameter
    // (PHP time()) which works across all Moodle-supported database engines.
    if ($oldversion < 2026072103) {
        upgrade_plugin_savepoint(true, 2026072103, 'local', 'lmshomepage');
    }

    // v2.11.3: Fix UNIX_TIMESTAMP() in get_completed_units and get_enrolled_students.
    // Same PostgreSQL incompatibility as v2.11.2 — replaced with :uenow/:now params.
    if ($oldversion < 2026072104) {
        upgrade_plugin_savepoint(true, 2026072104, 'local', 'lmshomepage');
    }

    // v2.11.4: Version sequence correction — no code changes.
    if ($oldversion < 2026072105) {
        upgrade_plugin_savepoint(true, 2026072105, 'local', 'lmshomepage');
    }

    // v2.11.5: Fix student query in get_completion_report to work when roles are
    // assigned at category context (not course context) or use a non-standard
    // student role shortname.  Replaced INNER JOIN on role_assignments with a
    // NOT EXISTS subquery that excludes known teacher/admin roles instead.
    // NOT EXISTS is standard SQL — compatible with MySQL and PostgreSQL.
    if ($oldversion < 2026072106) {
        upgrade_plugin_savepoint(true, 2026072106, 'local', 'lmshomepage');
    }

    // v2.11.6: View-hidden audit fixes.
    // • get_completion_report trainer query: removed u.suspended = 0 so trainers
    //   whose accounts were later suspended still appear for historical data.
    // • get_completed_units: removed c.visible = 1 so completions inside
    //   hidden/archived courses are included in the report.
    if ($oldversion < 2026072107) {
        upgrade_plugin_savepoint(true, 2026072107, 'local', 'lmshomepage');
    }

    // v2.11.7: get_completion_report — PostgreSQL fix + activity names.
    // • CRITICAL FIX: trainer query used SELECT DISTINCT with ORDER BY columns
    //   (u.lastname, u.firstname) that were not in the SELECT list.  PostgreSQL
    //   throws dml_read_exception for this; MySQL silently ignores it.  Fixed by
    //   adding u.lastname and u.firstname to the SELECT list so ORDER BY is valid
    //   on both database engines.
    // • Uses get_fast_modinfo() to get activity names + module types for all
    //   completion-tracked CMs.  This bypasses the capability restriction that
    //   blocks core_course_get_contents on non-admin WS tokens.
    // • Each student row now includes ALL completion-tracked activities (not just
    //   those with a record in mdl_course_modules_completion), with state=0 and
    //   initial_completed_date=0 for activities the student has not yet started.
    //   Guarantees every row has the same complete activity column list.
    // • activity_completions items now carry 'name' and 'modname' fields so the
    //   calling server can derive column headers without needing
    //   core_course_get_contents.
    // • enrol_date now uses MIN(COALESCE(ue.timestart, ue.timecreated, 0)) to
    //   surface a non-zero date for cohort-sync enrolments where timestart = 0.
    if ($oldversion < 2026072108) {
        upgrade_plugin_savepoint(true, 2026072108, 'local', 'lmshomepage');
    }

    // v2.11.8: CRITICAL PostgreSQL fix for get_completion_report trainer query.
    // SELECT DISTINCT with ORDER BY u.lastname, u.firstname failed on PostgreSQL
    // because those columns were not in the SELECT list (MySQL silently ignores
    // this; PostgreSQL throws dml_read_exception at parse time regardless of
    // whether any rows match).  Fixed by adding u.lastname and u.firstname to
    // the SELECT list.  This fix unblocks ALL completion data on PostgreSQL sites.
    if ($oldversion < 2026072109) {
        upgrade_plugin_savepoint(true, 2026072109, 'local', 'lmshomepage');
    }

    // v2.11.9: Replace get_fast_modinfo() with direct SQL for activity names.
    // get_fast_modinfo() was triggering dml_read_exception on some PostgreSQL
    // configurations when rebuilding the modinfo cache from scratch.  The new
    // approach queries {course_modules} + per-module-type tables directly, which
    // avoids any Moodle-internal caching SQL that may be PostgreSQL-incompatible.
    // Also converts CONCAT() to || concatenation and wraps every DB call in a
    // labelled try-catch so future errors surface the failing step name.
    // Also changed student NOT EXISTS subquery to NOT IN for broader compatibility.
    if ($oldversion < 2026072110) {
        upgrade_plugin_savepoint(true, 2026072110, 'local', 'lmshomepage');
    }

    // v2.11.10: Sentinel error returns — each DB step wrapped in try-catch,
    // failures return a row with fullname="FAIL:<step>:<msg>" instead of
    // throwing a moodle_exception (whose debuginfo is hidden without dev mode).
    // Also split student query into two simple queries (no GROUP BY + subquery),
    // deduplication done in PHP instead of SQL.
    if ($oldversion < 2026072111) {
        upgrade_plugin_savepoint(true, 2026072111, 'local', 'lmshomepage');
    }

    // v2.11.11: Fix STEP8 dml_read_exception — remove JOIN from activity
    // completions query (query cmc table alone, filter tracked CMs in PHP).
    // Switch timecompleted → timemodified in cmc (timemodified present in all
    // Moodle versions; timecompleted added in 3.11 and absent on some servers).
    // STEP9 course_completions uses COALESCE(timecompleted, timemodified, 0).
    if ($oldversion < 2026072112) {
        upgrade_plugin_savepoint(true, 2026072112, 'local', 'lmshomepage');
    }

    // v2.11.12: Fix STEP9 — course_completions has no timemodified column
    // (columns: userid, course, timeenrolled, timestarted, timecompleted,
    // reaggregate). Remove timemodified from COALESCE; use timecompleted only.
    if ($oldversion < 2026072113) {
        upgrade_plugin_savepoint(true, 2026072113, 'local', 'lmshomepage');
    }

    // v2.11.13: Add local_lmshomepage_get_slim_courses web service function.
    // Single SQL query returning only {id, fullname, shortname, categoryid,
    // visible, numsections} — ~50× smaller payload than core_course_get_courses.
    // Replaces core_course_get_courses in all site cache builders (IWC, TDT,
    // Yilabara, Signature, EMS) to eliminate the 4-6 MB per-refresh bandwidth
    // spike. No schema changes; function registered in services.php only.
    if ($oldversion < 2026072114) {
        upgrade_plugin_savepoint(true, 2026072114, 'local', 'lmshomepage');
    }

    // v2.11.14: Six AIOP report fixes:
    // 1. Grade report: group-aware trainer lookup (trainer who is a group member
    //    takes priority over lowest-ID course teacher), fixes wrong trainer shown.
    // 2. Grade report: deterministic single-group subquery eliminates multi-row
    //    dedup randomness that caused group_name to show blank.
    // 3. Attendance report: trainer filter moved inside t_map subquery (was
    //    applied in WHERE against MIN(id) result, so non-minimum trainers got 0 rows).
    // 4. Attendance report: session groupid filter — only count sessions whose
    //    groupid = 0 (all students) or matches the student's own group, fixing
    //    inflated total_sessions from cross-group session pollution.
    // 5. Attendance report: denominator changed from total_sessions to marked
    //    sessions (attended + missed), matching the Moodle attendance export %.
    // No schema changes.
    if ($oldversion < 2026072115) {
        upgrade_plugin_savepoint(true, 2026072115, 'local', 'lmshomepage');
    }

    // v2.11.15: get_user_courses — include completed courses with suspended/expired enrolments.
    // Root cause of "missing completed courses" on student dashboard:
    // When Moodle suspends or expires a student's enrolment after completion
    // (a common post-qualification workflow), the previous SQL (ue.status = 0 only)
    // excluded those courses entirely.  Fix: add OR EXISTS check against
    // course_completions so any course with timecompleted IS NOT NULL always appears,
    // regardless of current enrolment status.
    // No schema changes.
    if ($oldversion < 2026072116) {
        upgrade_plugin_savepoint(true, 2026072116, 'local', 'lmshomepage');
    }

    // v2.11.16: CSS injection guard fixes.
    // Fix 1 — is_active() hard exclusions: add blocked layout list (admin,
    //   maintenance, login, popup, embedded, secure, redirect) and pagetype
    //   prefixes (admin-*, my-*) checked BEFORE plugin-enabled/api-url checks.
    //   Prevents CSS injection on admin pages when the hook fires before
    //   $PAGE->pagetype / $PAGE->pagelayout are fully populated.
    // Fix 2 — fullwidth_css(): removed #page-header from the hide list (too
    //   broad — hides the primary navigation bar on some themes).  Also
    //   removed .wombat-header/.wombat-top-bar (primary nav must stay visible)
    //   and the body padding-top:0 override (Wombat theme handles this itself).
    // No schema changes.
    if ($oldversion < 2026072117) {
        upgrade_plugin_savepoint(true, 2026072117, 'local', 'lmshomepage');
    }

    // v2.11.17: AIOP report fixes.
    // Fix 1 — get_vet_grade_report: exclude system/test accounts (lastaccess=0,
    //   firstname LIKE 'test%', username IN admin/guest) from trainer lookup
    //   subqueries so MIN(u_t.id) no longer resolves to a "Test Teacher" account.
    //   Also includes 'trainer' role shortname alongside editingteacher/teacher.
    // Fix 2 — get_vet_grade_report: replaced unbounded cohort LEFT JOIN with a
    //   deterministic dedup subquery (MIN cohort name per student) to eliminate
    //   Cartesian-product row duplication that was causing group_name to appear
    //   blank for most students in the Cohort/Group Grade Report.
    // Fix 3 — get_attendance_report: same trainer exclusion + dedup subqueries
    //   for group AND cohort joins; also includes 'trainer' role shortname.
    // Fix 4 — get_attendance_report: attendance_pct denominator changed from
    //   'marked sessions only (P/L/E/A)' to 'total_sessions' to match the
    //   Moodle attendance export (e.g. Jesus Santiago 2% → 13.3%).
    // No schema changes.
    if ($oldversion < 2026072301) {
        upgrade_plugin_savepoint(true, 2026072301, 'local', 'lmshomepage');
    }

    // v2.11.18 — 2026-07-23
    // Fix 1 (Issues 1+2): get_assessment_submissions + get_trainer_allocations — replaced
    //   unbounded cohort/group/trainer JOINs with deterministic dedup subqueries
    //   (MIN group ID, MIN trainer ID, MIN cohort name). Prevents Cartesian product
    //   row explosion that caused the marking report to time out.
    //   Trainer role check now covers ANY context level (course/category/system).
    // Fix 2 (Issue 3): get_cohort_matrix — removed cohortid from training plan JOIN
    //   (was causing mismatches); added 'N/A' alongside 'NA' in CASE WHEN; changed
    //   outcome logic from "all rows = X" to "any row = X" (RPL/CT/NA priority).
    // Fix 3 (Issue 4): get_trainers — added test-account exclusions (lastaccess > 0,
    //   firstname NOT LIKE 'test%', username NOT IN admin/guest); added 'trainer' role.
    // No schema changes.
    if ($oldversion < 2026072302) {
        upgrade_plugin_savepoint(true, 2026072302, 'local', 'lmshomepage');
    }

    // v2.11.19 — 2026-07-23
    // Fix 1 (§3.1): get_cohort_matrix — restored cohortid in training plan subquery
    //   GROUP BY (now userid, cohortid, courseid) and JOIN condition (tp.cohortid = coh.id).
    //   Prevents multi-cohort students bleeding outcome from cohort B into cohort A matrix.
    // Fix 2 (§3.2): get_trainer_allocations + get_assessment_submissions — scoped trainer
    //   role EXISTS check to course context OR ancestor category (contextlevel 40 path LIKE).
    //   Excludes teachers assigned only to unrelated courses or at system level.
    // No schema changes.
    if ($oldversion < 2026072303) {
        upgrade_plugin_savepoint(true, 2026072303, 'local', 'lmshomepage');
    }

    // v2.11.20 — 2026-07-26
    // Fix XMLDB warnings on all sites: CHAR NOT NULL fields in db/install.xml
    // had DEFAULT="" which violates the Moodle XMLDB spec (text columns that are
    // NOT NULL must not use an empty-string default — they should have no default
    // at all, as they are always populated by code at insert time).
    // Affected fields across local_lmshomepage_log and local_lmshomepage_commhub:
    //   student_name, student_email, course_name, activity_name, recipient_name,
    //   subject, trainer_name, assessment_name, message_subject.
    // Corresponding add_field() calls in this file also corrected ('' → null).
    // No DB schema changes — actual columns are already correct on all sites.
    if ($oldversion < 2026072601) {
        upgrade_plugin_savepoint(true, 2026072601, 'local', 'lmshomepage');
    }

    // v2.11.21 — 2026-07-27
    // Fix 1: get_assessment_submissions — trainer lookup was scoped to the same
    //   course as the assignment (GROUP BY userid, courseid). Trainers are in groups
    //   on the cohort/main course, not on individual unit-assessment courses, so
    //   trainer_id was 0 for 99% of rows. Fixed: per-student lookup (GROUP BY userid
    //   only) — aligns with get_trainer_allocations and populates trainer_id correctly.
    //   Downstream fixes: trainer backlog counts, Trainer View filtering, trainer
    //   performance marking data all become accurate automatically.
    // Fix 2: get_trainers — added username NOT IN ('admin') to exclude the site
    //   admin account (already excluded by id != 1, this adds defence-in-depth for
    //   sites where admin uses a different user id).
    // No schema changes.
    if ($oldversion < 2026072701) {
        upgrade_plugin_savepoint(true, 2026072701, 'local', 'lmshomepage');
    }

    // v2.11.22 — 2026-07-27
    // Fix: get_trainer_allocations — Trainer Allocation Report returned zero rows
    //   on sites (including Wombat) where trainer groups live in a cohort/hub course
    //   rather than in the individual unit-assessment courses students are enrolled in.
    //   Root cause: group JOIN used `g.courseid = c.id`, tying group lookup to the
    //   enrollment course — which never matched because the groups are in a different
    //   course.
    //   Fix: student activity is confirmed via a subquery (active enrolment in any
    //   visible course with student role), then groups are looked up across ANY course
    //   independently of the enrollment course. Trainer role check is unchanged —
    //   still scoped to the group's own course context or ancestor category.
    //   Last-access is now the MAX across all enrolled courses (previously was
    //   per-enrollment-course, which caused last_access = 0 for the same reason).
    // No schema changes.
    if ($oldversion < 2026072702) {
        upgrade_plugin_savepoint(true, 2026072702, 'local', 'lmshomepage');
    }

    // v2.11.23 — 2026-07-27
    // Fix: get_trainers — Trainer View showed too many names and wrong student counts.
    //   Root cause 1 (too many trainers): query used role-in-any-course as the trainer
    //     filter, which included former staff still holding a role in archived courses.
    //   Root cause 2 (wrong counts): student count fell back to course-overlap counting
    //     (students enrolled in any course the trainer also teaches), which overcounts
    //     because a student in N shared courses was counted N times and unrelated
    //     students sharing a course were included.
    //   Fix: both trainer list and student count are now derived from group co-membership
    //     — the same source as the Trainer Allocation Report.  A trainer appears only if
    //     they share a Moodle group with at least one actively-enrolled student (HAVING
    //     COUNT > 0).  Student count = distinct active students in their groups.
    //     This makes Trainer View KPI tiles and the allocation report fully consistent.
    // No schema changes.
    if ($oldversion < 2026072703) {
        upgrade_plugin_savepoint(true, 2026072703, 'local', 'lmshomepage');
    }

    // v2.11.24 — 2026-07-27
    // Fix: get_attendance_report — attendance percentage used total past sessions as
    //   the denominator, which penalised students for sessions the teacher hadn't
    //   marked yet.  Example: a unit with 7 sessions pre-created (all sessdate in the
    //   past) but only 1 session marked — a student who attended that session would
    //   show 1/7 = 14% instead of 1/1 = 100%.
    //   Root cause: denominator was total_sessions (every session with sessdate ≤ now),
    //     regardless of whether an attendance mark existed.
    //   Fix: introduce sessions_marked = count of sessions where ANY attendance mark
    //     (P/L/E/A or custom acronym) has been recorded for this student.  Use
    //     sessions_marked as the denominator for attendance_pct and the at_risk
    //     threshold check.  total_sessions is retained in the output for reference.
    //     New field sessions_marked added to the returned row structure.
    // No schema changes.
    if ($oldversion < 2026072704) {
        upgrade_plugin_savepoint(true, 2026072704, 'local', 'lmshomepage');
    }

    // v2.11.25 — 2026-07-28
    // Unified trainer allocation source across all plugin functions:
    //   All four trainer-related functions (get_trainer_allocations,
    //   get_assessment_submissions, get_trainers, get_completed_units) now
    //   derive trainer allocation exclusively from the 'wombat_trainer' custom
    //   profile field ("Your Trainer Is").  Previously only get_completed_units
    //   used this field; the other three used Moodle group co-membership, which
    //   is unreliable (duplicates per group, wrong trainer when student is in
    //   multiple groups, includes cohort/admin groups).
    //
    // get_trainer_allocations: rewritten — one row per student (not per
    //   student+group), trainer from wombat_trainer profile field.  Eliminates
    //   duplicate student rows for students in multiple groups.
    //
    // get_assessment_submissions: trainer subquery switched from group
    //   co-membership to wombat_trainer profile field.
    //
    // get_trainers: rewritten — trainer list and student counts now derived
    //   from unique non-empty wombat_trainer values across active students.
    //   Replaces group co-membership approach that included former staff.
    //
    // get_course_completion_counts: added student role JOIN so only student
    //   completions are counted.  Previously counted all users (admins,
    //   teachers) causing the dashboard KPI to show ~752 while the report
    //   showed ~28.
    //
    // get_monthly_active_user_count: added since_timestamp param (use calendar
    //   month start instead of rolling N-day window) and student_only param
    //   (count only active students, not admins/trainers).
    //
    // No schema changes.
    if ($oldversion < 2026072705) {
        upgrade_plugin_savepoint(true, 2026072705, 'local', 'lmshomepage');
    }

    // ── v2.11.26 (2026072706) ────────────────────────────────────────────────
    //
    // get_assessment_submissions: Training Plan due dates now replace the
    //   Moodle-native assign.duedate where a training plan entry exists.
    //
    //   Resolution priority (highest wins):
    //     1. block_trainingplan_userseq.enddate where manualoverride = 1
    //        — the admin/trainer has set a per-student override date.
    //     2. block_trainingplan_schedule.enddate (cohort-level schedule date).
    //     3. assign.duedate — Moodle native assignment due date (fallback).
    //
    //   Both training plan tables are guarded with $dbman->table_exists() so
    //   the function continues to work on sites without the training plan block
    //   (Signature, TDT, AIOP, etc.).
    //
    // get_assessment_submissions: deemed_competent_date added.
    //   Reads local_finalmarkingsheet.deemedcompetentdate — the date the
    //   assessor marked the student competent on the final marking sheet.
    //   Joined on (userid, courseid); returns 0 when the table is absent or
    //   the assessor has not yet marked the student.
    //
    // No schema changes (both tables are owned by third-party plugins).
    if ($oldversion < 2026072706) {
        upgrade_plugin_savepoint(true, 2026072706, 'local', 'lmshomepage');
    }

    // ── v2.11.27 (2026073101) ────────────────────────────────────────────────
    //
    // get_user_courses: include ALL enrolment records (active, suspended,
    //   expired) instead of only active + formally-completed ones.
    //
    //   Root cause: ITLC's "Credit Deemed" (-CD) workflow suspends a student's
    //   enrolment after credit is granted but does NOT write a record to
    //   course_completions.  The previous query (status=0 active OR
    //   course_completions.timecompleted IS NOT NULL) silently excluded these
    //   courses, so they vanished from the student dashboard.
    //
    //   Fix: remove the status/timeend predicate entirely — any row in
    //   mdl_user_enrolments is sufficient to include the course.  Hidden courses
    //   (visible=0) are returned with visible=0 so the portal can show an
    //   "archived" label or toggle.
    //
    // No schema changes.
    if ($oldversion < 2026073101) {
        upgrade_plugin_savepoint(true, 2026073101, 'local', 'lmshomepage');
    }

    // ── v2.11.28 (2026073102) ────────────────────────────────────────────────
    //
    // get_vet_grade_report: fix trainer filter returning empty results.
    //
    //   Root cause: Moodle's DBAL does not allow the same named parameter to
    //   appear more than once in a query.  :trainer_userid was used in BOTH
    //   t_map_grp and t_map_crs subqueries but only bound once; the second
    //   occurrence received NULL, so t_map_crs never matched any trainer and
    //   $where_trainer eliminated every row.
    //
    //   Fix: split into :trainer_userid (t_map_grp) and :trainer_userid2
    //   (t_map_crs), both bound to the same value.  Pattern matches the
    //   existing :now1/:now2 split already in the query.
    //
    // No schema changes.
    if ($oldversion < 2026073102) {
        upgrade_plugin_savepoint(true, 2026073102, 'local', 'lmshomepage');
    }

    // ── v2.11.29 (2026073103) ────────────────────────────────────────────────
    //
    // get_vet_grade_report: exclude attendance module grade items from results.
    //
    //   When attendance is removed from the Course Total in Moodle's gradebook
    //   the grade_items row remains with hidden=0, so the previous query still
    //   returned attendance rows in the Reports Hub even though the gradebook
    //   correctly excluded them from the total.
    //
    //   Fix: add WHERE gi.itemmodule != 'attendance' so attendance grade items
    //   are never included in any grade report.
    //
    // No schema changes.
    if ($oldversion < 2026073103) {
        upgrade_plugin_savepoint(true, 2026073103, 'local', 'lmshomepage');
    }

    // ── v2.11.30 (2026073104) ────────────────────────────────────────────────
    //
    // get_trainers: restore trainer dropdown on sites without wombat_trainer.
    //
    //   The v2.11.25 rewrite made get_trainers return [] immediately when the
    //   wombat_trainer custom profile field is absent.  Sites like AIOP that
    //   never had this field lost their trainer dropdown entirely.
    //
    //   Fix: when wombat_trainer field is absent, fall back to a role-based
    //   query — find all users with editingteacher/teacher/trainer role in a
    //   visible course that has at least one active student enrolled.  The
    //   profile-field path (Wombat) is unchanged.
    //
    // No schema changes.
    if ($oldversion < 2026073104) {
        upgrade_plugin_savepoint(true, 2026073104, 'local', 'lmshomepage');
    }

    // ── v2.11.31 (2026073105) ────────────────────────────────────────────────
    //
    // get_completion_report: two fixes.
    //
    // Fix 1 — filter activity columns to course completion criteria only.
    //   Previously returned ALL completion-tracked activities (cm.completion > 0).
    //   Now queries course_completion_criteria (criteriatype=4) and filters
    //   actDefs to only those cmids.  Falls back to all actDefs if none configured.
    //
    // Fix 2 — quiz grade fallback (Step 8b).
    //   Students who completed quizzes before activity-completion tracking was
    //   enabled have no course_modules_completion record (state=0) even though
    //   their grade exists in grade_grades.  Step 8b queries grade_items +
    //   grade_grades for all quiz activities in actDefs and fills in state=2
    //   (Pass), state=3 (Fail), or state=1 (Complete) for students with no
    //   existing completion record.  Never overwrites an existing record.
    //
    // No schema changes.
    if ($oldversion < 2026073105) {
        upgrade_plugin_savepoint(true, 2026073105, 'local', 'lmshomepage');
    }

    // ── v2.11.32 (2026073106) ────────────────────────────────────────────────
    //
    // get_completion_report: fix get_records_sql → get_recordset_sql in both
    // Step 8a and Step 8b.
    //
    // get_records_sql() keys result rows by the FIRST column value.  Step 8a
    // used userid as first column — only ONE completion record per student
    // survived when a student has completions across multiple activities.
    // Step 8b used quiz_instance as first column — only ONE student's grade
    // survived per quiz, making the grade fallback effectively return nothing
    // for all but one student per quiz.
    //
    // Both steps now use get_recordset_sql() which iterates every row without
    // any key collision or overwriting.
    //
    // No schema changes.
    if ($oldversion < 2026073106) {
        upgrade_plugin_savepoint(true, 2026073106, 'local', 'lmshomepage');
    }

    // ── v2.11.33 (2026073107) ────────────────────────────────────────────────
    //
    // get_completion_report: generalise the gradebook fallback (Step 8b) from
    // quiz-only to ALL graded completion-criteria activities.
    //
    //   v2.11.31 added a grade fallback so students who completed a QUIZ before
    //   activity-completion tracking was enabled (no course_modules_completion
    //   row) still show Pass/Not Passed derived from grade_grades.finalgrade.
    //   That path filtered grade_items on component='mod_quiz', so assignments,
    //   SCORM, workshops, lessons and H5P criteria activities still rendered
    //   blank for those students.
    //
    //   Step 8b now matches every activity grade item (itemtype='mod') back to
    //   its cmid via (itemmodule, iteminstance) and applies the same pass/fail
    //   derivation for any graded criteria activity.  Existing
    //   course_modules_completion records are never overwritten.
    //
    // No schema changes.
    if ($oldversion < 2026073107) {
        upgrade_plugin_savepoint(true, 2026073107, 'local', 'lmshomepage');
    }

    // ── v2.11.34 (2026073108) ────────────────────────────────────────────────
    //
    // get_completion_report: make the course-completion-criteria filter STRICT.
    //
    //   Step 3b previously fell back to showing ALL completion-tracked
    //   activities whenever the course_completion_criteria (criteriatype=4)
    //   query returned no rows.  On courses where that query came back empty,
    //   non-criteria activities (forums, practice quizzes) leaked into the
    //   report as extra columns.
    //
    //   Now: a successful-but-empty criteria query yields NO activity columns
    //   (an un-ticked activity is never listed).  The "show all" fallback is
    //   retained ONLY when the query throws (table missing/inaccessible).
    //   Criteria matching is also hardened: moduleinstance is treated as a cmid
    //   (standard) and, if unmatched, resolved via (module, instance)→cmid.
    //
    // No schema changes.
    if ($oldversion < 2026073108) {
        upgrade_plugin_savepoint(true, 2026073108, 'local', 'lmshomepage');
    }

    // ── v2.11.35 (2026080301) ────────────────────────────────────────────────
    //
    // Version bump only — no code or schema changes.  Forces Moodle to re-run
    // the plugin upgrade on sites that already recorded 2026073108 (v2.11.34),
    // so the strict course-completion-criteria filter (Step 3b) is picked up
    // cleanly.  Identical code to v2.11.34.
    //
    if ($oldversion < 2026080301) {
        upgrade_plugin_savepoint(true, 2026080301, 'local', 'lmshomepage');
    }

    // ── v2.11.36 (2026080302) ────────────────────────────────────────────────
    //
    // get_completion_report: quizzes now read Passed/Not Passed like assignments.
    //
    //   A quiz whose activity-completion rule is "mark complete on submit"
    //   stores completionstate=1 (Complete), whereas an assignment with
    //   "require passing grade" stores 2/3 (Pass/Fail).  Step 8a took those
    //   values verbatim, so quizzes showed "Complete" while assignments showed
    //   Passed/Not Passed for the same cohort.
    //
    //   Step 8b now treats the gradebook "grade to pass" threshold as
    //   authoritative: when gradepass > 0 and a finalgrade exists, a plain
    //   Complete (1) or missing (0) record is upgraded to Passed (2) / Not
    //   Passed (3).  Native Pass/Fail records are left unchanged; activities
    //   with no pass threshold keep their existing state.  The real
    //   activity-completion date is preserved when upgrading.
    //
    //   NOTE: a quiz still needs a "Grade to pass" value set (quiz settings →
    //   Grade, or Gradebook setup) for pass/fail to be derivable.
    //
    // No schema changes.
    if ($oldversion < 2026080302) {
        upgrade_plugin_savepoint(true, 2026080302, 'local', 'lmshomepage');
    }

    // ── v2.11.37 (2026080303) ────────────────────────────────────────────────
    //
    // Version bump only — no code or schema changes.  Forces Moodle to re-run
    // the plugin upgrade on sites that already recorded 2026080302 (v2.11.36),
    // so the quiz pass/fail + strict-criteria completion-report changes are
    // picked up cleanly.  Identical code to v2.11.36.
    //
    if ($oldversion < 2026080303) {
        upgrade_plugin_savepoint(true, 2026080303, 'local', 'lmshomepage');
    }

    // ── v2.11.38 (2026080304) ────────────────────────────────────────────────
    //
    // Version bump only — no code or schema changes. Forces Moodle to re-run the
    // plugin upgrade so the completion-report changes are picked up. Identical
    // code to v2.11.36/37.
    //
    if ($oldversion < 2026080304) {
        upgrade_plugin_savepoint(true, 2026080304, 'local', 'lmshomepage');
    }

    return true;
}
