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
 * External function: local_lmshomepage_get_student_inactivity
 *
 * Returns all enrolled students who have not accessed any course within
 * the last N days. Pulls last-access data from mdl_user_lastaccess and
 * joins the trainername custom profile field to surface the allocated
 * trainer name alongside each student.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_student_inactivity
 *   threshold_days=7         (int, optional — default 7)
 *   cohort_id=0              (int, optional — 0 = all cohorts)
 *   trainer_userid=0         (int, optional — 0 = all trainers)
 *
 * Returns:
 *   [
 *     {
 *       "userid": 1042,
 *       "fullname": "Emma Thompson",
 *       "email": "e.thompson@wombat.edu.au",
 *       "cohort_name": "CHC43015 Jan 2026",
 *       "group_name": "Group A",
 *       "last_access": 1748390400,
 *       "days_inactive": 14,
 *       "trainer_id": 678,
 *       "trainer_name": "Sarah Mitchell"
 *     },
 *     ...
 *   ]
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_student_inactivity extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'threshold_days' => new \external_value(
                PARAM_INT,
                'Students inactive for this many days or more are returned (default 7).',
                VALUE_DEFAULT,
                7
            ),
            'cohort_id' => new \external_value(
                PARAM_INT,
                'Filter to a specific cohort ID. 0 = all cohorts.',
                VALUE_DEFAULT,
                0
            ),
            'trainer_userid' => new \external_value(
                PARAM_INT,
                'Filter to a specific trainer (by user ID from trainername profile field). 0 = all trainers.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    /**
     * Return students who have been inactive for threshold_days or more.
     *
     * @param int $threshold_days Minimum days of inactivity.
     * @param int $cohort_id      Optional cohort filter (0 = all).
     * @param int $trainer_userid Optional trainer filter (0 = all).
     * @return array
     */
    public static function execute(int $threshold_days = 7, int $cohort_id = 0, int $trainer_userid = 0): array {
        global $DB;

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'threshold_days' => $threshold_days,
            'cohort_id'      => $cohort_id,
            'trainer_userid' => $trainer_userid,
        ]);

        $params = [];
        $now    = time();
        $cutoff = $now - ($threshold_days * DAYSECS);
        $params['cutoff']  = $cutoff;
        $params['now_ts']  = $now;
        $params['now_ts2'] = $now; // second reference in WHERE (named params can't repeat)

        $cohortJoin = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortJoin  = "JOIN {cohort_members} cm2 ON cm2.userid = u.id AND cm2.cohortid = :cohort_id";
        }

        $trainerWhere = '';
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            $trainerWhere = "AND trainer.id = :trainer_userid";
        }

        // Trainer from the 'trainername' custom profile field ("Your Trainer Is"), consistent
        // with the Trainer Allocation / Completed Units reports. (Previously this derived the
        // trainer from course editingteacher/teacher roles, which is why the Student Inactivity
        // report never showed the allocated trainer that the RTO records in the profile field.)
        $params['tf_id'] = ((int) $DB->get_field('user_info_field', 'id', ['shortname' => 'trainername'])) ?: -1;

        // DB-portable name concatenation (handles NULL coalescing correctly on all DB engines).
        $sqlFullname        = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $sqlTrainerFullname = $DB->sql_concat('trainer.firstname', "' '", 'trainer.lastname');

        $trainerJoin = "
            LEFT JOIN {user_info_data} uid_tr
                   ON uid_tr.userid = u.id AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
                   ON trainer.deleted = 0 AND trainer.suspended = 0
                  AND LOWER(TRIM({$sqlTrainerFullname}))
                    = LOWER(TRIM(uid_tr.data))
        ";

        // Resolve inactivity first, before joining cohort and group display data.
        // Joining enrolments, cohorts, groups and course access in one aggregate
        // multiplies rows heavily on large sites and caused this report to time out.
        $sql = "
            SELECT
                u.id                                                 AS userid,
                {$sqlFullname}                                       AS fullname,
                u.email,
                GREATEST(COALESCE(MAX(ula.timeaccess), 0), COALESCE(u.lastaccess, 0)) AS last_access,
                FLOOR((:now_ts - GREATEST(COALESCE(MAX(ula.timeaccess), 0), COALESCE(u.lastaccess, 0), COALESCE(u.firstaccess, 0))) / 86400)
                                                                     AS days_inactive,
                COALESCE(trainer.id, 0)                             AS trainer_id,
                COALESCE({$sqlTrainerFullname}, NULLIF(TRIM(uid_tr.data), ''), '')
                                                                     AS trainer_name
            FROM {user} u
            -- Active enrolments only
            JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                 AND (ue.timeend = 0 OR ue.timeend > :now_ts2)
            JOIN {enrol} e            ON e.id = ue.enrolid
            JOIN {course} c           ON c.id = e.courseid AND c.id != 1 AND c.visible = 1
            LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
            $trainerJoin
            $cohortJoin
            WHERE u.deleted  = 0
              AND u.suspended = 0
              AND u.id       != 1
              AND u.username != 'guest'
              $trainerWhere
            GROUP BY u.id, u.firstname, u.lastname, u.email, u.firstaccess, u.lastaccess,
                     trainer.id, trainer.firstname, trainer.lastname, uid_tr.data
            HAVING GREATEST(COALESCE(MAX(ula.timeaccess), 0), COALESCE(u.lastaccess, 0), COALESCE(u.firstaccess, 0)) <= :cutoff
            ORDER BY days_inactive DESC
        ";

        $rows = $DB->get_records_sql($sql, $params);

        // Enrich only the much smaller set of inactive users with one cohort and
        // one active-course group label. Keep the first non-empty value found,
        // matching the report's previous single-label display contract.
        $metadata = [];
        if ($rows) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($rows), SQL_PARAMS_NAMED, 'inactive');
            $metasql = "
                SELECT u.id AS userid, COALESCE(coh.name, '') AS cohort_name,
                       COALESCE(g.name, '') AS group_name
                  FROM {user} u
             LEFT JOIN {cohort_members} cm ON cm.userid = u.id
             LEFT JOIN {cohort} coh ON coh.id = cm.cohortid
             LEFT JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                  AND (ue.timeend = 0 OR ue.timeend > :meta_now)
             LEFT JOIN {enrol} e ON e.id = ue.enrolid
             LEFT JOIN {course} c ON c.id = e.courseid AND c.id != 1 AND c.visible = 1
             LEFT JOIN {groups_members} gm ON gm.userid = u.id
             LEFT JOIN {groups} g ON g.id = gm.groupid AND g.courseid = c.id
                 WHERE u.id $insql
              ORDER BY u.id, coh.name, g.name
            ";
            $inparams['meta_now'] = $now;
            $recordset = $DB->get_recordset_sql($metasql, $inparams);
            foreach ($recordset as $meta) {
                $userid = (int) $meta->userid;
                if (!isset($metadata[$userid])) {
                    $metadata[$userid] = ['cohort_name' => '', 'group_name' => ''];
                }
                if ($metadata[$userid]['cohort_name'] === '' && trim((string) $meta->cohort_name) !== '') {
                    $metadata[$userid]['cohort_name'] = (string) $meta->cohort_name;
                }
                if ($metadata[$userid]['group_name'] === '' && trim((string) $meta->group_name) !== '') {
                    $metadata[$userid]['group_name'] = (string) $meta->group_name;
                }
            }
            $recordset->close();
        }

        $result = [];
        foreach ($rows as $row) {
            $meta = $metadata[(int) $row->userid] ?? ['cohort_name' => '', 'group_name' => ''];
            $result[] = [
                'userid'       => (int)    $row->userid,
                'fullname'     => (string) $row->fullname,
                'email'        => (string) $row->email,
                'cohort_name'  => $meta['cohort_name'],
                'group_name'   => $meta['group_name'],
                'last_access'  => (int)    $row->last_access,
                'days_inactive'=> (int)    $row->days_inactive,
                'trainer_id'   => (int)    $row->trainer_id,
                'trainer_name' => (string) $row->trainer_name,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'        => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'      => new \external_value(PARAM_TEXT, 'Student full name'),
                'email'         => new \external_value(PARAM_TEXT, 'Student email address'),
                'cohort_name'   => new \external_value(PARAM_TEXT, 'Cohort name (first cohort found for this student)'),
                'group_name'    => new \external_value(PARAM_TEXT, 'Group name (first group found for this student)'),
                'last_access'   => new \external_value(PARAM_INT,  'Unix timestamp of last site/course access (0 = never)'),
                'days_inactive' => new \external_value(PARAM_INT,  'Number of days since last access'),
                'trainer_id'    => new \external_value(PARAM_INT,  'Allocated trainer user ID (0 = unassigned)'),
                'trainer_name'  => new \external_value(PARAM_TEXT, 'Allocated trainer full name (empty = unassigned)'),
            ])
        );
    }
}
