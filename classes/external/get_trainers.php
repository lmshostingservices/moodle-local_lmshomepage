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
 * External function: local_lmshomepage_get_trainers
 *
 * Returns trainers with their allocated student count, sourced from the
 * 'wombat_trainer' custom profile field ("Your Trainer Is").
 *
 * REWRITE (v2.11.25) — previous approach and why it was wrong:
 *   The previous version used group co-membership to determine who is a
 *   trainer and how many students they have.  This was unreliable because:
 *     • It included anyone who held a teacher/editingteacher role and happened
 *       to share a group with a student — former staff, admins, etc.
 *     • It could show different student counts from the Trainer Allocation
 *       report when students were in overlapping groups.
 *
 * New approach (same source as get_trainer_allocations and get_completed_units):
 *   1. Query every non-empty value in the wombat_trainer profile field across
 *      all active students.
 *   2. Match each stored string against mdl_user to resolve the trainer record.
 *   3. Count distinct active students per trainer.
 *   4. Only return trainers with at least one currently-enrolled active student.
 *
 * This guarantees the trainer list and student counts exactly match the
 * Trainer Allocation report and the Completed Units report.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_trainers
 *   course_id=0   (kept for API compatibility — not used)
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_trainers extends \external_api {
    public static function execute_parameters (): \external_function_parameters {
        return new \external_function_parameters([
            'course_id' => new \external_value(
                PARAM_INT,
                'Kept for API compatibility. Not used — trainers are derived from the wombat_trainer profile field.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute (int $course_id = 0): array {
        global $DB;

        $now = time();

        // Resolve the wombat_trainer profile field ID.
        // If the field exists use the profile-field approach (Wombat).
        // If it does not exist fall back to the role-based approach so that
        // sites without the wombat_trainer field (e.g. AIOP) still get a
        // populated trainer list.
        $fieldId = (int) $DB->get_field('user_info_field', 'id', ['shortname' => 'wombat_trainer']);

        if ($fieldId) {
            // ── Profile-field path (Wombat) ───────────────────────────────────
            // Trainer identity comes from the wombat_trainer custom field set on
            // each student's profile.  This guarantees the list matches the
            // Trainer Allocation and Completed Units reports exactly.
            $sql = "
                SELECT
                    trainer.id                                                  AS trainerid,
                    CONCAT(trainer.firstname, ' ', trainer.lastname)           AS fullname,
                    trainer.email,
                    COUNT(DISTINCT u.id)                                        AS studentcount

                FROM {user_info_data} uid_tr

                -- ── Resolve trainer name → trainer user record ────────────────
                JOIN {user} trainer
                    ON  trainer.deleted   = 0
                    AND trainer.suspended = 0
                    AND trainer.id       != 1
                    AND trainer.username NOT IN ('admin', 'guest')
                    AND LOWER(TRIM(CONCAT(trainer.firstname, ' ', trainer.lastname)))
                      = LOWER(TRIM(uid_tr.data))

                -- ── The student who has this trainer set ──────────────────────
                JOIN {user} u
                    ON  u.id       = uid_tr.userid
                    AND u.deleted  = 0
                    AND u.suspended = 0
                    AND u.id      != 1
                    AND u.username != 'guest'

                -- ── Confirm the student is actively enrolled ──────────────────
                JOIN (
                    SELECT DISTINCT ue2.userid
                      FROM {user_enrolments} ue2
                      JOIN {enrol}   e2   ON e2.id   = ue2.enrolid
                      JOIN {course}  c2   ON c2.id   = e2.courseid
                                        AND c2.id   != 1 AND c2.visible = 1
                      JOIN {context} ctx2 ON ctx2.contextlevel = 50
                                        AND ctx2.instanceid   = c2.id
                      JOIN {role_assignments} ra2 ON ra2.userid   = ue2.userid
                                                 AND ra2.contextid = ctx2.id
                      JOIN {role} ro2 ON ro2.id = ra2.roleid AND ro2.shortname = 'student'
                     WHERE ue2.status = 0
                       AND (ue2.timeend = 0 OR ue2.timeend > :now_ts)
                ) active_stu ON active_stu.userid = u.id

                WHERE uid_tr.fieldid  = :field_id
                  AND uid_tr.data    IS NOT NULL
                  AND TRIM(uid_tr.data) != ''

                GROUP BY trainer.id, trainer.firstname, trainer.lastname, trainer.email

                HAVING COUNT(DISTINCT u.id) > 0

                ORDER BY trainer.lastname, trainer.firstname
            ";

            $rows = $DB->get_records_sql($sql, [
                'field_id' => $fieldId,
                'now_ts'   => $now,
            ]);

        } else {
            // ── Role-based path (AIOP and sites without wombat_trainer) ───────
            // Find all users who hold editingteacher / teacher / trainer role in
            // a visible course and have at least one active student enrolled in
            // that same course.  Excludes system/test accounts.
            $sql = "
                SELECT
                    trainer.id                                                  AS trainerid,
                    CONCAT(trainer.firstname, ' ', trainer.lastname)           AS fullname,
                    trainer.email,
                    COUNT(DISTINCT stu.userid)                                  AS studentcount

                FROM {role_assignments} ra_t
                JOIN {role}    ro_t    ON ro_t.id = ra_t.roleid
                                      AND ro_t.shortname IN ('editingteacher','teacher','trainer')
                JOIN {context} ctx     ON ctx.id = ra_t.contextid
                                      AND ctx.contextlevel = 50
                JOIN {course}  c       ON c.id = ctx.instanceid
                                      AND c.id != 1 AND c.visible = 1
                JOIN {user}    trainer ON trainer.id = ra_t.userid
                                      AND trainer.deleted   = 0
                                      AND trainer.suspended = 0
                                      AND trainer.id       != 1
                                      AND trainer.username NOT IN ('admin', 'guest')
                                      AND trainer.lastaccess > 0
                                      AND LOWER(trainer.firstname) NOT LIKE 'test%'

                -- ── Active students in the same course ────────────────────────
                JOIN (
                    SELECT ue2.userid, e2.courseid
                      FROM {user_enrolments} ue2
                      JOIN {enrol}  e2  ON e2.id  = ue2.enrolid
                      JOIN {context} ctx2 ON ctx2.contextlevel = 50
                                        AND ctx2.instanceid   = e2.courseid
                      JOIN {role_assignments} ra2 ON ra2.userid    = ue2.userid
                                                 AND ra2.contextid = ctx2.id
                      JOIN {role} ro2 ON ro2.id = ra2.roleid AND ro2.shortname = 'student'
                     WHERE ue2.status = 0
                       AND (ue2.timeend = 0 OR ue2.timeend > :now_ts)
                ) stu ON stu.courseid = c.id

                GROUP BY trainer.id, trainer.firstname, trainer.lastname, trainer.email

                HAVING COUNT(DISTINCT stu.userid) > 0

                ORDER BY trainer.lastname, trainer.firstname
            ";

            $rows = $DB->get_records_sql($sql, ['now_ts' => $now]);
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'trainerid'    => (int)    $row->trainerid,
                'fullname'     => (string) $row->fullname,
                'email'        => (string) $row->email,
                'studentcount' => (int)    $row->studentcount,
            ];
        }

        return $result;
    }

    public static function execute_returns (): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'trainerid'    => new \external_value(PARAM_INT,  'Trainer user ID'),
                'fullname'     => new \external_value(PARAM_TEXT, 'Trainer full name'),
                'email'        => new \external_value(PARAM_TEXT, 'Trainer email address'),
                'studentcount' => new \external_value(PARAM_INT,  'Number of active students with this trainer set in their wombat_trainer profile field'),
            ])
        );
    }
}
