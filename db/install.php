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
 * Post-install script for local_lmshomepage.
 *
 * Runs once, immediately after install.xml tables are created.
 * Grants every capability the LMS Dashboard webservice token user needs
 * to the Manager role and any webservice-named roles so admins do not
 * have to add them manually.
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_lmshomepage_install (): bool {
    local_lmshomepage_grant_required_capabilities();
    return true;
}

/**
 * Grant all capabilities required by the LMS Homepage Dashboard webservice.
 *
 * Targets:
 *   • The built-in 'manager' role (archetype = manager).
 *   • Any role whose shortname contains 'webservice' (common for custom token roles).
 *
 * Each capability is skipped silently if the underlying plugin that defines it
 * (e.g. mod_attendance) is not yet installed on this Moodle — so install order
 * does not matter.
 *
 * Called from both install.php (first install) and upgrade.php (upgrades).
 */
function local_lmshomepage_grant_required_capabilities (): void {
    global $DB;

    $systemcontext = context_system::instance();

    // ── Capabilities the webservice token user needs ───────────────────────────
    $required_caps = [
        // core_course_get_contents — find attendance/resource modules inside courses
        'moodle/course:view',
        'moodle/course:viewhiddencourses',

        // core_enrol_get_enrolled_users — list students & teachers per course
        'moodle/course:enrolreview',
        'moodle/user:viewdetails',
        'moodle/user:viewhiddendetails',

        // mod_attendance_get_sessions / mod_attendance_get_session
        'mod/attendance:view',
        'mod/attendance:viewreports',
        'mod/attendance:takeanother',    // needed by some plugin versions to read session logs

        // gradereport_overview_get_course_grades
        'moodle/grade:viewall',
        'gradereport/overview:view',

        // core_message_send_instant_messages
        'moodle/site:sendmessage',

        // report_completion_get_activity_completion_status
        'report/completion:view',
        'moodle/course:viewcoursereports',
    ];

    // ── Roles to receive the capabilities ─────────────────────────────────────
    // manager + any role whose shortname contains 'webservice'
    $role_records = $DB->get_records_sql(
        "SELECT * FROM {role} WHERE shortname = 'manager' OR " .
        $DB->sql_like('shortname', ':ws', false, false),
        ['ws' => '%webservice%']
    );

    if (empty($role_records)) {
        // Fallback: grant to all roles with manager archetype
        $role_records = $DB->get_records('role', ['archetype' => 'manager']);
    }

    foreach ($role_records as $role) {
        foreach ($required_caps as $cap) {
            // get_capability_info() returns null if the capability is not yet
            // registered (e.g. mod_attendance not installed).
            if (!get_capability_info($cap)) {
                continue;
            }
            // assign_capability is idempotent — safe to call repeatedly.
            assign_capability($cap, CAP_ALLOW, $role->id, $systemcontext->id, false);
        }
    }

    // Flush the capability cache so changes take effect immediately.
    $systemcontext->mark_dirty();
}
