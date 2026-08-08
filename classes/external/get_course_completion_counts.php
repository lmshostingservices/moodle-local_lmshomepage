<?php
/**
 * External function: local_lmshomepage_get_course_completion_counts
 *
 * Returns the number of STUDENTS who have fully completed each course,
 * sourced from mdl_course_completions.timecompleted.
 *
 * FIX (v2.11.25): added student role JOIN so only completions for users who
 * hold the 'student' role in that course are counted.  Without this filter
 * the query counted admins, teachers, and every other user with a
 * timecompleted record, producing a much larger number than the Completed
 * Units report (which does filter to students).  On Wombat this caused the
 * dashboard KPI to show ~752 while the report showed ~28.
 *
 * Optional parameters:
 *   courseids       – comma-separated list of course IDs to restrict the query.
 *   since_timestamp – unix timestamp; if > 0, only count timecompleted >= this.
 *
 * Returns:
 *   [ { "courseid": 42, "completed": 18 }, ... ]
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_course_completion_counts extends \external_api {

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseids' => new \external_value(
                PARAM_TEXT,
                'Optional comma-separated course IDs. Empty = all visible non-site courses.',
                VALUE_DEFAULT,
                ''
            ),
            'since_timestamp' => new \external_value(
                PARAM_INT,
                'Optional unix timestamp. If > 0, only count completions on or after this date.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(string $courseids = '', int $since_timestamp = 0): array {
        global $DB;

        $params = [];
        $where  = 'cc.timecompleted IS NOT NULL';

        if ($courseids !== '') {
            $courseids = preg_replace('/[^0-9,]/', '', $courseids);
            $ids       = array_filter(array_map('intval', explode(',', $courseids)));
            if (!empty($ids)) {
                [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED, 'cid');
                $where  .= " AND cc.course $insql";
                $params  = array_merge($params, $inparams);
            }
        }

        if ($since_timestamp > 0) {
            $params['since_ts'] = $since_timestamp;
            $where .= ' AND cc.timecompleted >= :since_ts';
        }

        // FIX: join to role_assignments so only student completions are counted.
        // Without this, admins and teachers with timecompleted records inflate the KPI.
        $sql = "
            SELECT cc.course AS courseid, COUNT(*) AS completed
              FROM {course_completions} cc
              JOIN {context} ctx ON ctx.contextlevel = 50 AND ctx.instanceid = cc.course
              JOIN {role_assignments} ra  ON ra.userid    = cc.userid
                                        AND ra.contextid  = ctx.id
              JOIN {role}             ro  ON ro.id        = ra.roleid
                                        AND ro.shortname  = 'student'
             WHERE $where
          GROUP BY cc.course
          ORDER BY cc.course
        ";

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'courseid'  => (int) $row->courseid,
                'completed' => (int) $row->completed,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'courseid'  => new \external_value(PARAM_INT, 'Course ID'),
                'completed' => new \external_value(PARAM_INT, 'Number of students with timecompleted set (student role only, optionally filtered by since_timestamp)'),
            ])
        );
    }
}
