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
 * External function: local_lmshomepage_get_trainer_allocations
 *
 * Returns ONE ROW PER STUDENT showing their allocated trainer, sourced
 * from the 'trainername' custom profile field ("Your Trainer Is").
 *
 * REWRITE (v2.11.25) — previous approach and why it was wrong:
 *   The previous version used Moodle group co-membership to infer trainer
 *   allocation.  This had two problems:
 *     1. DUPLICATES: a student in N groups appeared N times in the report.
 *     2. WRONG SOURCE: the client's confirmed source of truth for trainer
 *        allocation is the 'trainername' profile field.  Group membership
 *        does not reliably identify the responsible trainer (students may be
 *        in admin/cohort groups, multiple trainers may be in the same group,
 *        etc.).
 *
 * New approach:
 *   1. One row per active student (deduplicated by userid).
 *   2. Trainer is read from user_info_data where fieldid = trainername field.
 *   3. The stored string (trainer full name) is matched against mdl_user to
 *      resolve the trainer user ID — identical to get_completed_units.php.
 *   4. Falls back gracefully (trainer_id=0, trainer_name='') when the field
 *      is not set or the named user cannot be found.
 *
 * The result is consistent with:
 *   • get_completed_units (trainer allocation in completion reports)
 *   • get_assessment_submissions (trainer allocation in marking reports)
 *   • get_trainers (trainer list and student counts)
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_trainer_allocations
 *   cohort_id=0        (int, optional — 0 = all cohorts)
 *   trainer_userid=0   (int, optional — 0 = all; -1 = unallocated only)
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_trainer_allocations extends \external_api {
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
                'Filter to a specific trainer. 0 = all; -1 = unallocated students only.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(int $cohort_id = 0, int $trainer_userid = 0): array {
        global $DB;

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'cohort_id'      => $cohort_id,
            'trainer_userid' => $trainer_userid,
        ]);

        $params = ['now_ts' => time()];

        // ── Resolve trainername profile field ID ───────────────────────────
        // Falls back to -1 when the field doesn't exist so no uid_tr rows
        // match (trainer stays NULL / unallocated) without any SQL error.
        $trainerFieldId = (int) $DB->get_field('user_info_field', 'id', ['shortname' => 'trainername']);
        $params['tf_id']     = $trainerFieldId > 0 ? $trainerFieldId : -1;
        // Separate param copy for the group-dedup NOT EXISTS subquery.
        $params['tf_id_grp'] = $trainerFieldId > 0 ? $trainerFieldId : -1;

        // ── Optional filters ──────────────────────────────────────────────────
        $cohortJoin  = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortJoin = "JOIN {cohort_members} cm_flt ON cm_flt.userid = u.id AND cm_flt.cohortid = :cohort_id";
        }

        $trainerWhere = '';
        if ($trainer_userid === -1) {
            // "Unallocated" = no trainer label stored (not "no matched user account").
            $trainerWhere = "AND (uid_tr.data IS NULL OR TRIM(uid_tr.data) = '')";
        } elseif ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            $trainerWhere = "AND trainer.id = :trainer_userid";
        }

        // ── Main query ────────────────────────────────────────────────────────
        //
        // One row per active student.
        //
        // Step 1 (enrol_data): confirm the user is actively enrolled as a student
        //   in at least one visible course; collect their latest last-access
        //   timestamp across all their courses.
        //
        // Step 2 (coh_dedup): alphabetically-first cohort name per student to
        //   avoid row explosion when a student belongs to multiple cohorts.
        //
        // Step 3 (trainername profile field): read the stored trainer name
        //   from user_info_data, then match it against mdl_user to resolve the
        //   trainer user record.
        //

        // ── DB-portable string concatenation helpers ──────────────────────────
        $sql_fullname           = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $sql_trainer_name_match = 'LOWER(TRIM(' . $DB->sql_concat('trainer.firstname', "' '", 'trainer.lastname') . '))';

        $sql = "
            SELECT
                u.id                                                        AS userid,
                {$sql_fullname}                                             AS fullname,
                u.email,
                COALESCE(coh_dedup.cohort_name, '')                        AS cohort_name,
                COALESCE(grp_dedup.group_name, '')                         AS group_name,
                COALESCE(enrol_data.last_access, 0)                        AS last_access,
                COALESCE(trainer.id, 0)                                    AS trainer_id,
                -- Display the raw trainer label (e.g. Mel, Steph). The stored values are
                -- usually first names, so the full-name user match below often resolves no
                -- user — but the label itself is the allocation the RTO uses.
                COALESCE(NULLIF(TRIM(uid_tr.data), ''), '')                AS trainer_name

            FROM {user} u

            -- ── 1. Confirm active student enrolment (any course) ──────────────
            JOIN (
                SELECT   ue2.userid,
                         MAX(COALESCE(ula2.timeaccess, 0)) AS last_access
                FROM     {user_enrolments} ue2
                JOIN     {enrol}   e2   ON e2.id  = ue2.enrolid
                JOIN     {course}  c2   ON c2.id  = e2.courseid
                                      AND c2.id != 1 AND c2.visible = 1
                JOIN     {context} ctx2 ON ctx2.contextlevel = 50
                                       AND ctx2.instanceid  = c2.id
                JOIN     {role_assignments} ra2 ON ra2.userid   = ue2.userid
                                               AND ra2.contextid = ctx2.id
                JOIN     {role}    ro2 ON ro2.id = ra2.roleid
                                     AND ro2.shortname = 'student'
                LEFT JOIN {user_lastaccess} ula2
                             ON ula2.userid   = ue2.userid
                            AND ula2.courseid = c2.id
                WHERE    ue2.status = 0
                  AND    (ue2.timeend = 0 OR ue2.timeend > :now_ts)
                GROUP BY ue2.userid
            ) enrol_data ON enrol_data.userid = u.id

            -- ── 2. Cohort: alphabetically-first name per student ──────────────
            LEFT JOIN (
                SELECT   cm_mem.userid,
                         MIN(coh.name) AS cohort_name
                FROM     {cohort_members} cm_mem
                JOIN     {cohort} coh ON coh.id = cm_mem.cohortid
                GROUP BY cm_mem.userid
            ) coh_dedup ON coh_dedup.userid = u.id

            -- ── 3. Group: alphabetically-first group name per student ─────────
            --  Restrict to visible courses (same filter as the enrolment subquery)
            --  so groups in archived/hidden courses don't pollute the result.
            --  Exclude groups whose name matches any trainername profile field value —
            --  those are trainer-allocation pseudo-groups (e.g. \"Steph\", \"Mel\"), not
            --  real student groups.
            LEFT JOIN (
                SELECT   gm.userid,
                         MIN(g.name) AS group_name
                FROM     {groups_members} gm
                JOIN     {groups} g ON g.id = gm.groupid
                JOIN     {course} gc ON gc.id = g.courseid
                                    AND gc.id != 1
                                    AND gc.visible = 1
                WHERE    NOT EXISTS (
                    SELECT 1
                    FROM   {user_info_data} tf_grp
                    WHERE  tf_grp.fieldid = :tf_id_grp
                      AND  TRIM(tf_grp.data) != ''
                      AND  LOWER(TRIM(tf_grp.data)) = LOWER(TRIM(g.name))
                )
                GROUP BY gm.userid
            ) grp_dedup ON grp_dedup.userid = u.id

            -- ── 4. Trainer from trainername profile field ──────────────────
            LEFT JOIN {user_info_data} uid_tr
                ON uid_tr.userid  = u.id
               AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
                ON  trainer.deleted   = 0
                AND trainer.suspended = 0
                AND {$sql_trainer_name_match}
                  = LOWER(TRIM(uid_tr.data))

            $cohortJoin

            WHERE u.deleted   = 0
              AND u.suspended  = 0
              AND u.id        != 1
              AND u.username  != 'guest'
              $trainerWhere

            ORDER BY trainer_name, u.lastname, u.firstname
        ";

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'userid'       => (int)    $row->userid,
                'fullname'     => (string) $row->fullname,
                'email'        => (string) $row->email,
                'cohort_name'  => (string) $row->cohort_name,
                'group_name'   => (string) $row->group_name,
                'last_access'  => (int)    $row->last_access,
                'trainer_id'   => (int)    $row->trainer_id,
                'trainer_name' => (string) $row->trainer_name,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'       => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'     => new \external_value(PARAM_TEXT, 'Student full name'),
                'email'        => new \external_value(PARAM_TEXT, 'Student email address'),
                'cohort_name'  => new \external_value(PARAM_TEXT, 'Primary cohort name (alphabetically first)'),
                'group_name'   => new \external_value(PARAM_TEXT, 'Alphabetically-first Moodle group name the student belongs to (empty if none)'),
                'last_access'  => new \external_value(PARAM_INT,  'Unix timestamp of last access across all enrolled courses (0 = never)'),
                'trainer_id'   => new \external_value(PARAM_INT,  'Allocated trainer user ID from trainername profile field (0 = unallocated)'),
                'trainer_name' => new \external_value(PARAM_TEXT, 'Allocated trainer full name (empty = unallocated)'),
            ])
        );
    }
}
