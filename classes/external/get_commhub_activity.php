<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Read the local_lmshomepage CommHub delivery log for one uniquely matched user.
 *
 * This deliberately reads only the plugin-owned logger table. It is not a
 * reader for the native CommHub plugin, whose API/schema is not configured here.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_commhub_activity extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'contactemail' => new \external_value(PARAM_EMAIL, 'Exact CRM contact email used to resolve one Moodle user'),
            'limit' => new \external_value(PARAM_INT, 'Page size, from 1 to 100', VALUE_DEFAULT, 20),
            'offset' => new \external_value(PARAM_INT, 'Zero-based page offset', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(string $contactemail, int $limit = 20, int $offset = 0): array {
        global $DB;

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);
        self::validate_parameters(self::execute_parameters(), [
            'contactemail' => $contactemail,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $email = trim($contactemail);
        if ($email === '' || $limit < 1 || $limit > 100 || $offset < 0) {
            throw new \invalid_parameter_exception('Invalid contact email or pagination values.');
        }

        // Case-insensitive exact-address equality only. No name, prefix, or
        // substring matching. Retrieve at most two IDs to detect ambiguity
        // without returning any unmatched student's identifying data.
        $users = $DB->get_records_select(
            'user',
            "deleted = 0 AND id > 1 AND LOWER(email) = LOWER(:email)",
            ['email' => $email],
            'id ASC',
            'id',
            0,
            2
        );
        if (count($users) !== 1) {
            return [
                'match_status' => count($users) > 1 ? 'duplicate' : 'missing',
                'coverage' => 'plugin_log_only',
                'native_inbound' => 'unconfigured',
                'records' => [],
                'limit' => $limit,
                'offset' => $offset,
                'total' => 0,
            ];
        }

        $userid = (int) reset($users)->id;
        $total = $DB->count_records('local_lmshomepage_commhub', ['studentid' => $userid]);
        $rows = $DB->get_records(
            'local_lmshomepage_commhub',
            ['studentid' => $userid],
            'timesent DESC, id DESC',
            'id, timesent, channel, message_subject, message_body, status, course_name, assessment_name',
            $offset,
            $limit
        );
        $records = [];
        foreach ($rows as $row) {
            $records[] = [
                'id' => (int) $row->id,
                'timesent' => (int) $row->timesent,
                'channel' => (string) $row->channel,
                'message_subject' => (string) $row->message_subject,
                'message_body' => (string) $row->message_body,
                'status' => (string) $row->status,
                'course_name' => (string) $row->course_name,
                'assessment_name' => (string) $row->assessment_name,
            ];
        }

        return [
            'match_status' => 'matched',
            'coverage' => 'plugin_log_only',
            'native_inbound' => 'unconfigured',
            'records' => $records,
            'limit' => $limit,
            'offset' => $offset,
            'total' => (int) $total,
        ];
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'match_status' => new \external_value(PARAM_ALPHANUMEXT, 'matched, missing, or duplicate exact-email match status'),
            'coverage' => new \external_value(PARAM_ALPHANUMEXT, 'Source coverage: plugin_log_only'),
            'native_inbound' => new \external_value(PARAM_ALPHANUMEXT, 'Native CommHub inbound coverage: unconfigured'),
            'records' => new \external_multiple_structure(new \external_single_structure([
                'id' => new \external_value(PARAM_INT, 'Stable ID of the plugin-owned log record'),
                'timesent' => new \external_value(PARAM_INT, 'Unix timestamp when logged'),
                'channel' => new \external_value(PARAM_TEXT, 'Logged delivery channel'),
                'message_subject' => new \external_value(PARAM_TEXT, 'Logged message subject'),
                'message_body' => new \external_value(PARAM_TEXT, 'Logged message body, rendered as text by consumers'),
                'status' => new \external_value(PARAM_TEXT, 'Logged delivery status'),
                'course_name' => new \external_value(PARAM_TEXT, 'Related course name'),
                'assessment_name' => new \external_value(PARAM_TEXT, 'Related assessment or activity name'),
            ])),
            'limit' => new \external_value(PARAM_INT, 'Page size'),
            'offset' => new \external_value(PARAM_INT, 'Zero-based page offset'),
            'total' => new \external_value(PARAM_INT, 'Total matched plugin log records'),
        ]);
    }
}