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
 * External function: local_lmshomepage_get_monthly_active_user_count
 *
 * Returns the number of distinct STUDENTS who accessed any course within
 * the given time window, using mdl_user_lastaccess.
 *
 * CHANGES (v2.11.25):
 *   • Added since_timestamp param: if > 0, use it directly as the cutoff
 *     instead of rolling back N days from now.  Pass start-of-calendar-month
 *     to get "active this calendar month" rather than "active in the last 30
 *     days" (which drifts throughout the month).
 *   • Added student_only param: if 1, only count users who hold the 'student'
 *     role in at least one visible course.  This prevents admin/trainer
 *     activity from inflating the "Active This Month" KPI on the dashboard.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_monthly_active_user_count
 *   days=30                (optional, default 30; ignored when since_timestamp > 0)
 *   since_timestamp=0      (optional; if > 0, overrides days)
 *   student_only=0         (optional; 1 = students only)
 *
 * Returns:
 *   { "count": 299, "since": 1713744000 }
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_monthly_active_user_count extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'days' => new \external_value(
                PARAM_INT,
                'Number of days to look back (default 30, max 366). Ignored when since_timestamp > 0.',
                VALUE_DEFAULT,
                30
            ),
            'since_timestamp' => new \external_value(
                PARAM_INT,
                'Unix timestamp for the start of the window. If > 0, overrides the days parameter. '
                . 'Pass start-of-current-calendar-month for "active this month" semantics.',
                VALUE_DEFAULT,
                0
            ),
            'student_only' => new \external_value(
                PARAM_INT,
                '0 = all non-guest users (default). 1 = only users with the student role in at least one visible course.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(int $days = 30, int $since_timestamp = 0, int $student_only = 0): array {
        global $DB;

        // Determine the window start
        if ($since_timestamp > 0) {
            $since = $since_timestamp;
        } else {
            $days  = max(1, min(366, $days));
            $since = time() - ($days * 86400);
        }

        $params = ['since' => $since];

        if ($student_only) {
            // Only count users who hold the student role in at least one active visible course.
            $sql = "
                SELECT COUNT(DISTINCT ula.userid) AS cnt
                  FROM {user_lastaccess} ula
                  JOIN {user} u ON u.id = ula.userid
                 WHERE ula.timeaccess >= :since
                   AND u.deleted   = 0
                   AND u.suspended = 0
                   AND u.username != 'guest'
                   AND u.id       != 1
                   AND EXISTS (
                       SELECT 1
                         FROM {user_enrolments} ue
                         JOIN {enrol}   e   ON e.id   = ue.enrolid
                         JOIN {course}  c   ON c.id   = e.courseid
                                           AND c.id  != 1 AND c.visible = 1
                         JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = c.id
                         JOIN {role_assignments} ra ON ra.userid   = u.id
                                                   AND ra.contextid = ctx.id
                         JOIN {role} ro ON ro.id = ra.roleid AND ro.shortname = 'student'
                        WHERE ue.userid = u.id
                          AND ue.status = 0
                          AND (ue.timeend = 0 OR ue.timeend > :now_ts)
                   )
            ";
            $params['now_ts'] = time();
        } else {
            $sql = "
                SELECT COUNT(DISTINCT ula.userid) AS cnt
                  FROM {user_lastaccess} ula
                  JOIN {user} u ON u.id = ula.userid
                 WHERE ula.timeaccess >= :since
                   AND u.deleted   = 0
                   AND u.suspended = 0
                   AND u.username != 'guest'
                   AND u.id       != 1
            ";
        }

        $row   = $DB->get_record_sql($sql, $params);
        $count = $row ? (int) $row->cnt : 0;

        return [
            'count' => $count,
            'since' => $since,
        ];
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'count' => new \external_value(PARAM_INT, 'Number of distinct active users in the window'),
            'since' => new \external_value(PARAM_INT, 'Unix timestamp marking the start of the window'),
        ]);
    }
}
