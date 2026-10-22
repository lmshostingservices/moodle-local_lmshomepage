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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Bounded completion reader using Moodle's own permission-checked API.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;
defined('MOODLE_INTERNAL') || die();
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/completion/classes/external.php');

/**
 * Bounded completion reader. Uses Moodle's own completion external API.
 * Delta mode is advisory and must be enabled separately by the caller only
 * after standard-log coverage has been reviewed; otherwise pass cursor zero.
 */
class get_completion_batch extends \external_api {
    public static function execute_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'One course'),
            'userids' => new \external_value(PARAM_TEXT, 'At most 20 comma-separated enrolled user IDs'),
            'sinceeventid' => new \external_value(PARAM_INT, 'Previously verified event cursor; zero forces full reconciliation', VALUE_DEFAULT, 0),
        ]);
    }
    public static function execute($courseid, $userids, $sinceeventid = 0) {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact('courseid', 'userids', 'sinceeventid'));
        $courseid = (int)$params['courseid'];
        $cursor = (int)$params['sinceeventid'];
        if (!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/', $params['userids']) || $cursor < 0) {
            throw new \invalid_parameter_exception('Invalid completion scope');
        }
        $ids = array_values(array_unique(array_map('intval', explode(',', $params['userids']))));
        if (count($ids) > 20) {
            throw new \invalid_parameter_exception('At most 20 completion records per request');
        }
        $context = \context_course::instance($courseid);
        self::validate_context($context);
        $course = get_course($courseid);
        foreach ($ids as $id) {
            if ($id !== (int)$USER->id) {
                require_capability('report/completion:view', $context);
                if (!has_capability('moodle/site:accessallgroups', $context) && groups_get_course_groupmode($course) == SEPARATEGROUPS) {
                    $mine = groups_get_all_groups($courseid, $USER->id);
                    $theirs = groups_get_all_groups($courseid, $id);
                    if (!array_intersect(array_keys($mine), array_keys($theirs))) {
                        throw new \required_capability_exception($context, 'moodle/site:accessallgroups', 'nopermissions', '');
                    }
                }
            }
            if (!is_enrolled($context, $id, '', true)) {
                throw new \invalid_parameter_exception('Completion scope must use active enrolments');
            }
        }
        $enabled = explode(',', (string)get_config('tool_log', 'enabled_stores'));
        $logged = in_array('logstore_standard', $enabled, true)
            && $DB->get_manager()->table_exists('logstore_standard_log');
        $end = $logged ? (int)$DB->get_field_sql('SELECT MAX(id) FROM {logstore_standard_log}') : 0;
        $start = $logged ? (int)$DB->get_field_sql('SELECT MIN(id) FROM {logstore_standard_log}') : 0;
        $full = !$logged || !$cursor || $cursor > $end || $cursor < $start;
        $dirty = [];
        if (!$full) {
            // Bounded and cursor-based. Reads/views do not mean completion changed.
            // Any unclassified course write forces a conservative reconciliation.
            $events = $DB->get_records_sql(
                "SELECT id, userid, relateduserid, crud
                   FROM {logstore_standard_log}
                  WHERE id > :cursor AND id <= :end AND courseid = :courseid AND crud <> 'r'
               ORDER BY id",
                ['cursor' => $cursor, 'end' => $end, 'courseid' => $courseid], 0, 201
            );
            if (count($events) > 200) {
                $full = true;
            } else {
                foreach ($events as $event) {
                    $related = (int)$event->relateduserid;
                    $actor = (int)$event->userid;
                    if (!$related) {
                        // Course settings, criteria, resets and structural writes
                        // cannot safely be assigned to one student.
                        $full = true;
                        break;
                    }
                    $dirty[$related] = true;
                    $dirty[$actor] = true;
                }
            }
        }
        $rows = [];
        foreach ($ids as $id) {
            $unchanged = !$full && empty($dirty[$id]);
            $status = $unchanged ? '' : json_encode(
                \core_completion_external::get_course_completion_status($courseid, $id),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            $rows[] = ['userid' => $id, 'unchanged' => $unchanged, 'statusjson' => $status];
        }
        return ['rows' => $rows, 'eventcursor' => $end, 'fullreconcile' => $full, 'timechecked' => time()];
    }
    public static function execute_returns() {
        return new \external_single_structure([
            'rows' => new \external_multiple_structure(new \external_single_structure([
                'userid' => new \external_value(PARAM_INT, 'Verified requested user'),
                'unchanged' => new \external_value(PARAM_BOOL, 'No relevant changes in verified log coverage'),
                'statusjson' => new \external_value(
                    PARAM_RAW, // pipeline-ignore: PARAM_RAW — Core JSON, immediately decoded/validated by Homepage; not HTML.
                    'Moodle core completion result, empty only for unchanged'
                ),
            ])),
            'eventcursor' => new \external_value(PARAM_INT, 'Snapshot upper bound'),
            'fullreconcile' => new \external_value(PARAM_BOOL, 'Full rather than delta read'),
            'timechecked' => new \external_value(PARAM_INT, 'Server verification time'),
        ]);
    }
}
