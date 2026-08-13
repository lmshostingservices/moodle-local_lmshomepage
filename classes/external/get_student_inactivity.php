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
 * joins the wombat_trainer custom profile field to surface the allocated
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
                'Filter to a specific trainer (by user ID from wombat_trainer profile field). 0 = all trainers.',
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

        $params = [];
        $now    = time();
        $cutoff = $now - ($threshold_days * DAYSECS);
        $params['cutoff']  = $cutoff;
        $params['now_ts']  = $now;
        $params['now_ts2'] = $now; // second reference in WHERE (named params can't repeat)

        $cohortJoin  = '';
        $cohortWhere = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortJoin  = "JOIN {cohort_members} cm2 ON cm2.userid = u.id AND cm2.cohortid = :cohort_id";
        }

        $trainerWhere = '';
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            $trainerWhere = "AND trainer.id = :trainer_userid";
        }

        // Derive the trainer from course role assignments (editingteacher / teacher).
        // For each student, pick the lowest user ID teacher across all their enrolled
        // courses. No custom profile field required.
        $trainerJoin = "
            LEFT JOIN (
                SELECT ue_t.userid AS student_id, MIN(u_t.id) AS trainer_id
                FROM   {user_enrolments} ue_t
                JOIN   {enrol}           e_t  ON e_t.id  = ue_t.enrolid
                JOIN   {context}         c_t  ON c_t.contextlevel = 50
                                              AND c_t.instanceid = e_t.courseid
                JOIN   {role_assignments} ra_t ON ra_t.contextid = c_t.id
                JOIN   {role}            ro_t ON ro_t.id = ra_t.roleid
                                             AND ro_t.shortname IN ('editingteacher','teacher')
                JOIN   {user}            u_t  ON u_t.id = ra_t.userid
                                             AND u_t.deleted = 0 AND u_t.suspended = 0
                WHERE  ue_t.status = 0
                GROUP  BY ue_t.userid
            ) t_map ON t_map.student_id = u.id
            LEFT JOIN {user} trainer ON trainer.id = t_map.trainer_id
        ";

        // Max last-access across all courses, per user.
        $sql = "
            SELECT
                u.id                                                 AS userid,
                CONCAT(u.firstname, ' ', u.lastname)                AS fullname,
                u.email,
                COALESCE(coh.name, '')                              AS cohort_name,
                COALESCE(g.name, '')                                AS group_name,
                COALESCE(MAX(ula.timeaccess), 0)                    AS last_access,
                FLOOR((:now_ts - COALESCE(MAX(ula.timeaccess), u.firstaccess)) / 86400)
                                                                     AS days_inactive,
                COALESCE(trainer.id, 0)                             AS trainer_id,
                COALESCE(CONCAT(trainer.firstname, ' ', trainer.lastname), '')
                                                                     AS trainer_name
            FROM {user} u
            -- Active enrolments only
            JOIN {user_enrolments} ue ON ue.userid = u.id AND ue.status = 0
                 AND (ue.timeend = 0 OR ue.timeend > :now_ts2)
            JOIN {enrol} e            ON e.id = ue.enrolid
            JOIN {course} c           ON c.id = e.courseid AND c.id != 1 AND c.visible = 1
            -- Cohort membership (first cohort found)
            LEFT JOIN {cohort_members} cm  ON cm.userid = u.id
            LEFT JOIN {cohort} coh         ON coh.id = cm.cohortid
            -- Group membership (first group found)
            LEFT JOIN {groups_members} gm  ON gm.userid = u.id
            LEFT JOIN {groups} g           ON g.id = gm.groupid AND g.courseid = e.courseid
            -- Last access per course
            LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
            $trainerJoin
            $cohortJoin
            WHERE u.deleted  = 0
              AND u.suspended = 0
              AND u.id       != 1
              AND u.username != 'guest'
              $cohortWhere
              $trainerWhere
            GROUP BY u.id, u.firstname, u.lastname, u.email, u.firstaccess,
                     coh.name, g.name, trainer.id, trainer.firstname, trainer.lastname
            HAVING COALESCE(MAX(ula.timeaccess), u.firstaccess) <= :cutoff
            ORDER BY days_inactive DESC
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
