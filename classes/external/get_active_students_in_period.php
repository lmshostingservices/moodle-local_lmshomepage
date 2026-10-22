<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License
// as published by the Free Software Foundation, either version 3 of the License,
// or (at your option) any later version.

/**
 * Return students with actual Moodle standard-log activity in a date interval.
 *
 * @package    local_lmshomepage
 * @copyright 2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_active_students_in_period extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'date_from' => new \external_value(PARAM_INT, 'Inclusive Unix timestamp lower bound', VALUE_REQUIRED),
            'date_to' => new \external_value(PARAM_INT, 'Exclusive Unix timestamp upper bound', VALUE_REQUIRED),
            'cohort_id' => new \external_value(PARAM_INT, 'Optional current cohort filter; 0 = all cohorts', VALUE_DEFAULT, 0),
            'trainer_userid' => new \external_value(PARAM_INT, 'Optional trainer profile filter; 0 = all trainers', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $date_from, int $date_to, int $cohort_id = 0, int $trainer_userid = 0): array {
        global $DB;

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'date_from' => $date_from,
            'date_to' => $date_to,
            'cohort_id' => $cohort_id,
            'trainer_userid' => $trainer_userid,
        ]);
        if ($date_from <= 0 || $date_to <= $date_from) {
            throw new \invalid_parameter_exception('date_to must be after date_from.');
        }
        if (!$DB->get_manager()->table_exists('logstore_standard_log')) {
            throw new \coding_exception('Standard Moodle log storage is unavailable for period-based activity reporting.');
        }

        $params = [
            'date_from' => $date_from,
            'date_to' => $date_to,
        ];
        $trainerFieldId = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'trainername']);
        $params['tf_id'] = $trainerFieldId > 0 ? $trainerFieldId : -1;
        $sqlFullname = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $sqlTrainerFullname = $DB->sql_concat('trainer.firstname', "' '", 'trainer.lastname');

        $filters = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $filters .= ' AND EXISTS (SELECT 1 FROM {cohort_members} cmf WHERE cmf.userid = u.id AND cmf.cohortid = :cohort_id)';
        }
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            $filters .= ' AND trainer.id = :trainer_userid';
        }

        $sql = "
            SELECT
                u.id AS userid,
                {$sqlFullname} AS fullname,
                u.email,
                COALESCE(coh_sub.cohort_name, '') AS cohort_name,
                COALESCE(grp_sub.group_name, '') AS group_name,
                latest.last_access AS last_access,
                COALESCE(trainer.id, 0) AS trainer_id,
                COALESCE({$sqlTrainerFullname}, NULLIF(TRIM(uid_tr.data), ''), '') AS trainer_name
            FROM (
                SELECT latest_time.userid, latest_time.last_access,
                       (
                           SELECT MAX(l2.id)
                           FROM {logstore_standard_log} l2
                           WHERE l2.userid = latest_time.userid
                             AND l2.timecreated = latest_time.last_access
                             AND l2.courseid > 1
                             AND EXISTS (
                                 SELECT 1
                                 FROM {context} ctx2
                                 JOIN {role_assignments} ra2 ON ra2.contextid = ctx2.id AND ra2.userid = l2.userid
                                 JOIN {role} r2 ON r2.id = ra2.roleid AND r2.shortname = 'student'
                                 WHERE ctx2.contextlevel = 50 AND ctx2.instanceid = l2.courseid
                             )
                       ) AS latest_logid
                FROM (
                    SELECT l.userid, MAX(l.timecreated) AS last_access
                    FROM {logstore_standard_log} l
                    WHERE l.courseid > 1
                      AND l.userid > 1
                      AND l.timecreated >= :date_from
                      AND l.timecreated < :date_to
                      AND EXISTS (
                          SELECT 1
                          FROM {context} ctx
                          JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = l.userid
                          JOIN {role} r ON r.id = ra.roleid AND r.shortname = 'student'
                          WHERE ctx.contextlevel = 50 AND ctx.instanceid = l.courseid
                      )
                    GROUP BY l.userid
                ) latest_time
            ) latest
            JOIN {logstore_standard_log} lastlog ON lastlog.id = latest.latest_logid
            JOIN {user} u ON u.id = latest.userid AND u.deleted = 0
            LEFT JOIN (
                SELECT cm_i.userid, MIN(coh_i.name) AS cohort_name
                FROM {cohort_members} cm_i
                JOIN {cohort} coh_i ON coh_i.id = cm_i.cohortid
                GROUP BY cm_i.userid
            ) coh_sub ON coh_sub.userid = u.id
            LEFT JOIN (
                SELECT gm_i.userid, g_i.courseid, MIN(g_i.name) AS group_name
                FROM {groups_members} gm_i
                JOIN {groups} g_i ON g_i.id = gm_i.groupid
                GROUP BY gm_i.userid, g_i.courseid
            ) grp_sub ON grp_sub.userid = u.id AND grp_sub.courseid = lastlog.courseid
            LEFT JOIN {user_info_data} uid_tr ON uid_tr.userid = u.id AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
              ON trainer.deleted = 0 AND trainer.suspended = 0
             AND LOWER(TRIM({$sqlTrainerFullname})) = LOWER(TRIM(uid_tr.data))
            WHERE 1 = 1 $filters
            ORDER BY latest.last_access DESC, u.id
        ";
        $rows = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'userid' => (int)$row->userid,
                'fullname' => (string)$row->fullname,
                'email' => (string)$row->email,
                'cohort_name' => (string)$row->cohort_name,
                'group_name' => (string)$row->group_name,
                'last_access' => (int)$row->last_access,
                'trainer_id' => (int)$row->trainer_id,
                'trainer_name' => (string)$row->trainer_name,
            ];
        }
        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(new \external_single_structure([
            'userid' => new \external_value(PARAM_INT, 'Student user ID'),
            'fullname' => new \external_value(PARAM_TEXT, 'Student name'),
            'email' => new \external_value(PARAM_TEXT, 'Email'),
            'cohort_name' => new \external_value(PARAM_TEXT, 'Current cohort label'),
            'group_name' => new \external_value(PARAM_TEXT, 'Current group for the most recently active course'),
            'last_access' => new \external_value(PARAM_INT, 'Latest actual standard-log event in the requested interval'),
            'trainer_id' => new \external_value(PARAM_INT, 'Current trainer profile match, or zero'),
            'trainer_name' => new \external_value(PARAM_TEXT, 'Current trainer profile label'),
        ]));
    }
}
