<?php
/**
 * External function: local_lmshomepage_get_enrolled_students
 *
 * Returns a lightweight summary of ALL student enrolments across every
 * visible course on this Moodle site in a SINGLE database query.
 *
 * Problem it solves
 * -----------------
 * The dashboard server currently calls core_enrol_get_enrolled_users once
 * per course (e.g. 101 separate HTTP requests for ITLC).  Each call returns
 * the full user object (profile photo, custom fields, preferences …) which
 * wastes bandwidth and takes 30–60 seconds to complete.
 *
 * This function runs one optimised SQL query and returns only the fields
 * the dashboard actually needs:
 *   - userid, fullname
 *   - suspended (the field that core_enrol_get_enrolled_users omits)
 *   - lastcourseaccess (from mdl_user_lastaccess)
 *   - roleshortname  (to identify students vs teachers)
 *   - courseid
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_enrolled_students
 *   courseids=42,57,99   (optional — comma-separated IDs; omit for all courses)
 *   roleshortname=student (optional — filter to a specific role; default 'student')
 *
 * Returns:
 *   [
 *     {
 *       "courseid": 42,
 *       "userid": 1001,
 *       "fullname": "Jane Smith",
 *       "suspended": 0,
 *       "lastcourseaccess": 1713744000,
 *       "roleshortname": "student"
 *     },
 *     ...
 *   ]
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_enrolled_students extends \external_api {

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseids' => new \external_value(
                PARAM_TEXT,
                'Optional comma-separated course IDs. Empty = all visible non-site courses.',
                VALUE_DEFAULT,
                ''
            ),
            'roleshortname' => new \external_value(
                PARAM_TEXT,
                'Role shortname filter (e.g. "student"). Empty = all roles.',
                VALUE_DEFAULT,
                'student'
            ),
        ]);
    }

    /**
     * Return lightweight enrolment records with suspended status.
     *
     * @param string $courseids     Comma-separated course IDs (empty = all).
     * @param string $roleshortname Role to filter on (empty = all roles).
     * @return array
     */
    public static function execute(string $courseids = '', string $roleshortname = 'student'): array {
        global $DB;

        // ── Build course ID filter ────────────────────────────────────────
        $courseWhere = 'c.id != 1 AND c.visible = 1';
        $courseParams = [];

        if ($courseids !== '') {
            $courseids = preg_replace('/[^0-9,]/', '', $courseids);
            $ids = array_filter(array_map('intval', explode(',', $courseids)));
            if (!empty($ids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'crs');
                $courseWhere  .= " AND c.id $insql";
                $courseParams  = array_merge($courseParams, $inparams);
            }
        }

        // ── Build role filter ─────────────────────────────────────────────
        $roleJoin  = '';
        $roleWhere = '';
        $roleParams = [];

        if ($roleshortname !== '') {
            $roleshortname = clean_param($roleshortname, PARAM_ALPHANUMEXT);
            $roleJoin  = "JOIN {role} r ON r.id = ra.roleid";
            $roleWhere = "AND r.shortname = :roleshortname";
            $roleParams['roleshortname'] = $roleshortname;
        }

        // ── Main query ────────────────────────────────────────────────────
        // mdl_context  (contextlevel=50 = CONTEXT_COURSE)
        // mdl_role_assignments  — who has what role in each course context
        // mdl_user_enrolments + mdl_enrol — active enrolment records
        // mdl_user_lastaccess   — when user last accessed the specific course
        $sql = "
            SELECT
                CONCAT(ue.userid, '_', e.courseid) AS recid,
                e.courseid,
                u.id        AS userid,
                u.firstname,
                u.lastname,
                CONCAT(u.firstname, ' ', u.lastname) AS fullname,
                u.suspended,
                u.deleted,
                COALESCE(ula.timeaccess, 0) AS lastcourseaccess,
                COALESCE(r2.shortname, '')  AS roleshortname,
                COALESCE(ue.timecreated, 0) AS timecreated
            FROM {user_enrolments} ue
            JOIN {enrol}   e   ON e.id   = ue.enrolid
            JOIN {course}  c   ON c.id   = e.courseid  AND $courseWhere
            JOIN {user}    u   ON u.id   = ue.userid   AND u.deleted = 0 AND u.id != 1
                                                        AND u.username != 'guest'
            -- role assignment in this course's context
            JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = e.courseid
            JOIN {role_assignments} ra ON ra.userid = u.id AND ra.contextid = ctx.id
            $roleJoin
            $roleWhere
            LEFT JOIN {role} r2 ON r2.id = ra.roleid
            LEFT JOIN {user_lastaccess} ula ON ula.userid = u.id AND ula.courseid = e.courseid
            -- only active enrolments (status=0, timeend=0 or timeend in future)
            WHERE ue.status = 0
              AND (ue.timeend = 0 OR ue.timeend > :now)
            ORDER BY e.courseid, u.id
        ";

        $rows = $DB->get_records_sql($sql, array_merge($courseParams, $roleParams, ['now' => time()]));

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'courseid'        => (int)  $row->courseid,
                'userid'          => (int)  $row->userid,
                'fullname'        => (string) trim($row->fullname),
                'suspended'       => (int)  $row->suspended,
                'lastcourseaccess'=> (int)  $row->lastcourseaccess,
                'roleshortname'   => (string) $row->roleshortname,
                'timecreated'     => (int)  $row->timecreated,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'courseid'         => new \external_value(PARAM_INT,    'Course ID'),
                'userid'           => new \external_value(PARAM_INT,    'User ID'),
                'fullname'         => new \external_value(PARAM_TEXT,   'User full name'),
                'suspended'        => new \external_value(PARAM_INT,    '1 if account is suspended, 0 otherwise'),
                'lastcourseaccess' => new \external_value(PARAM_INT,    'Unix timestamp of last access to this course (0 = never)'),
                'roleshortname'    => new \external_value(PARAM_TEXT,   'Role shortname in this course context'),
                'timecreated'      => new \external_value(PARAM_INT,    'Unix timestamp when enrolment record was created (0 = unknown)'),
            ])
        );
    }
}
