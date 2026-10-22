<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Return course activity completion and standard-log view timestamps for one user.
 *
 * @package    local_lmshomepage
 * @copyright 2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_activity_views extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
            'userid' => new \external_value(PARAM_INT, 'Student user ID', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $courseid, int $userid): array {
        global $DB;

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        if ($courseid <= 1 || $userid <= 0) {
            return [];
        }

        $coursecontext = $DB->get_record('context', [
            'contextlevel' => CONTEXT_COURSE,
            'instanceid' => $courseid,
        ], 'id', IGNORE_MISSING);
        if (!$coursecontext || !$DB->record_exists('user', ['id' => $userid, 'deleted' => 0])) {
            return [];
        }

        // Keep the module list visible and in the same order Moodle presents it.
        $cmrows = $DB->get_recordset_sql("
            SELECT cm.id AS cmid, cm.instance, cm.completion,
                   m.name AS modname, cs.section
              FROM {course_modules} cm
              JOIN {modules} m ON m.id = cm.module
              JOIN {course_sections} cs ON cs.id = cm.section
             WHERE cm.course = :courseid
               AND cm.visible = 1
               AND COALESCE(cm.deletioninprogress, 0) = 0
             ORDER BY cs.section ASC, cm.id ASC
        ", ['courseid' => $courseid]);

        $modules = [];
        $byinstance = [];
        foreach ($cmrows as $cm) {
            $cmid = (int)$cm->cmid;
            $modname = (string)$cm->modname;
            $modules[$cmid] = [
                'cmid' => $cmid,
                'name' => $modname . '_' . (int)$cm->instance,
                'modname' => $modname,
                'completion' => (int)$cm->completion,
                'state' => 0,
                'completion_date' => 0,
                'first_viewed' => 0,
                'last_viewed' => 0,
                'view_status' => 'no_recorded_view',
            ];
            $byinstance[$modname][(int)$cm->instance] = $cmid;
        }
        $cmrows->close();
        if (!$modules) {
            return [];
        }

        // Resolve activity names through their module tables, as other plugin
        // reports do. Invalid/missing optional module tables retain a safe label.
        foreach ($byinstance as $modname => $instances) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', $modname)) {
                continue;
            }
            try {
                [$insql, $params] = $DB->get_in_or_equal(array_keys($instances), SQL_PARAMS_NAMED, 'inst');
                $names = $DB->get_records_sql("SELECT id, name FROM {{$modname}} WHERE id $insql", $params);
                foreach ($names as $name) {
                    $cmid = $instances[(int)$name->id] ?? 0;
                    if ($cmid) {
                        $modules[$cmid]['name'] = (string)$name->name;
                    }
                }
            } catch (\Throwable $e) {
                // The module may have been removed while its course-module row remains.
            }
        }

        [$cmidsql, $cmparams] = $DB->get_in_or_equal(array_keys($modules), SQL_PARAMS_NAMED, 'cm');
        $completionrs = $DB->get_recordset_sql("
            SELECT coursemoduleid, completionstate, COALESCE(timemodified, 0) AS completion_date
              FROM {course_modules_completion}
             WHERE userid = :userid AND coursemoduleid $cmidsql
        ", array_merge(['userid' => $userid], $cmparams));
        foreach ($completionrs as $row) {
            $cmid = (int)$row->coursemoduleid;
            if (isset($modules[$cmid])) {
                $modules[$cmid]['state'] = (int)$row->completionstate;
                $modules[$cmid]['completion_date'] = (int)$row->completion_date;
            }
        }
        $completionrs->close();

        // Course-module events use a module context, not the course context.
        // Scope by the log's courseid and exact CM IDs so this remains narrow.
        // Separate named placeholders are required because Moodle SQL parameters
        // cannot safely reuse the same named placeholder more than once.
        [$ctxsql, $ctxparams] = $DB->get_in_or_equal(array_keys($modules), SQL_PARAMS_NAMED, 'ctxcm');
        [$objsql, $objparams] = $DB->get_in_or_equal(array_keys($modules), SQL_PARAMS_NAMED, 'objcm');
        $logrs = $DB->get_recordset_sql("
            SELECT id, contextinstanceid, objectid, eventname, action, target, timecreated
              FROM {logstore_standard_log}
             WHERE courseid = :logcourseid
               AND userid = :loguserid
               AND (
                   contextinstanceid $ctxsql
                   OR (objecttable = 'course_modules' AND objectid $objsql)
               )
               AND (
                   LOWER(eventname) LIKE '%viewed'
                   OR LOWER(action) = 'viewed'
               )
             ORDER BY timecreated ASC, id ASC
        ", array_merge(
            ['logcourseid' => $courseid, 'loguserid' => $userid],
            $ctxparams,
            $objparams
        ));
        foreach ($logrs as $log) {
            $cmid = (int)$log->contextinstanceid;
            if (!isset($modules[$cmid]) && (int)$log->objectid > 0) {
                $cmid = (int)$log->objectid;
            }
            if (!isset($modules[$cmid])) {
                continue;
            }
            $stamp = (int)$log->timecreated;
            if ($stamp <= 0) {
                continue;
            }
            if ($modules[$cmid]['first_viewed'] === 0) {
                $modules[$cmid]['first_viewed'] = $stamp;
            }
            $modules[$cmid]['last_viewed'] = $stamp;
            $modules[$cmid]['view_status'] = 'recorded_view';
        }
        $logrs->close();

        return array_values($modules);
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(new \external_single_structure([
            'cmid' => new \external_value(PARAM_INT, 'Course module ID'),
            'name' => new \external_value(PARAM_TEXT, 'Activity name'),
            'modname' => new \external_value(PARAM_ALPHANUMEXT, 'Module type'),
            'completion' => new \external_value(PARAM_INT, 'Completion tracking mode'),
            'state' => new \external_value(PARAM_INT, 'Completion state'),
            'completion_date' => new \external_value(PARAM_INT, 'Completion date'),
            'first_viewed' => new \external_value(PARAM_INT, 'First recorded view'),
            'last_viewed' => new \external_value(PARAM_INT, 'Last recorded view'),
            'view_status' => new \external_value(PARAM_ALPHANUMEXT, 'View-event status'),
        ]));
    }
}