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

/**
 * Read a student's CommHub message history from native and plugin stores.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_commhub_messages extends \external_api {
    /** Maximum integer that can be represented exactly by JavaScript clients. */
    const MAX_SAFE_INTEGER = 9007199254740991;

    /** Source names and their lexical order are part of the cursor contract. */
    const KNOWN_SOURCES = ['autolog', 'studentcomm', 'todo'];

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'studentid' => new \external_value(PARAM_INT, 'Immutable Moodle user id resolved by the CRM.'),
            'aftercursor' => new \external_value(PARAM_TEXT, 'Opaque keyset cursor from a previous response; empty starts at newest.', VALUE_DEFAULT, ''),
            'limit' => new \external_value(PARAM_INT, 'Page size, 1 to 100.', VALUE_DEFAULT, 50),
            'sources' => new \external_value(PARAM_TEXT, 'CSV subset of todo,studentcomm,autolog. Default todo,studentcomm.', VALUE_DEFAULT, 'todo,studentcomm'),
        ]);
    }

    public static function execute(int $studentid, string $aftercursor = '', int $limit = 50,
            string $sources = 'todo,studentcomm'): array {
        global $DB;

        $context = \context_system::instance();
        self::validate_context($context);
        // CRM reads across user accounts; require the privileged integration
        // token capability, not merely possession of a dashboard service token.
        require_capability('moodle/site:config', $context);
        self::validate_parameters(self::execute_parameters(), [
            'studentid' => $studentid,
            'aftercursor' => $aftercursor,
            'limit' => $limit,
            'sources' => $sources,
        ]);

        if ($limit < 1 || $limit > 100 || strlen($aftercursor) > 200) {
            return self::error('invalid_pagination', self::coverage_all('excluded'));
        }
        $cursor = null;
        if ($aftercursor !== '') {
            $cursor = self::parse_cursor($aftercursor);
            if ($cursor === null) {
                return self::error('invalid_pagination', self::coverage_all('excluded'));
            }
        }

        if ($studentid <= 1 || $studentid > self::MAX_SAFE_INTEGER) {
            return self::error('invalid_student', self::coverage_all('excluded'));
        }
        $user = $DB->get_record('user', ['id' => $studentid], 'id, deleted');
        if (!$user || (int)$user->deleted === 1) {
            return self::error('invalid_student', self::coverage_all('excluded'));
        }

        $requested = self::parse_sources($sources);
        if ($requested === null) {
            return self::error('invalid_sources', self::coverage_all('excluded'));
        }

        $coverage = self::coverage_all('excluded');
        $rows = [];
        if (isset($requested['todo'])) {
            $coverage['todo'] = self::collect_todo($DB, $studentid, $limit, $cursor, $rows);
        }
        if (isset($requested['studentcomm'])) {
            $coverage['studentcomm'] = self::collect_studentcomm($DB, $studentid, $limit, $cursor, $rows);
        }
        if (isset($requested['autolog'])) {
            $coverage['autolog'] = self::collect_autolog($DB, $studentid, $limit, $cursor, $rows);
        }

        $anyok = false;
        foreach ($requested as $source => $_) {
            if (($coverage[$source] ?? '') === 'ok') {
                $anyok = true;
                break;
            }
        }
        if (!$anyok) {
            return self::error('source_unavailable', $coverage);
        }

        // Each source has already applied the cursor in SQL and returned at
        // most limit+1 rows. That is sufficient to find the global page, with
        // no scan of older message history and no fixed history cap.
        usort($rows, function ($a, $b) {
            if ($a['createdat'] !== $b['createdat']) {
                return $b['createdat'] <=> $a['createdat'];
            }
            if ($a['source'] !== $b['source']) {
                return strcmp($a['source'], $b['source']);
            }
            return $b['_local'] <=> $a['_local'];
        });
        $hasmore = count($rows) > $limit;
        $page = array_slice($rows, 0, $limit);

        $messages = [];
        foreach ($page as $row) {
            $messages[] = [
                'id' => $row['source'] . ':' . $row['_local'],
                'source' => $row['source'],
                'studentid' => $studentid,
                'channel' => $row['channel'],
                'subject' => $row['subject'],
                'body' => $row['body'],
                'status' => $row['status'],
                'direction' => $row['direction'],
                'createdAt' => $row['createdat'],
            ];
        }

        $nextcursor = '';
        if ($hasmore && $page) {
            $last = end($page);
            $nextcursor = $last['createdat'] . ':' . $last['source'] . ':' . $last['_local'];
        }

        return [
            'status' => 'ok',
            'error' => '',
            'studentid' => $studentid,
            'messages' => $messages,
            'coverage' => $coverage,
            'nextcursor' => $nextcursor,
        ];
    }

    /**
     * Read real CommHub notes. block_todo_items.notetype=1 is the note type;
     * userid is the student and assignedby is the author. Email-note means a
     * request to email, not evidence of successful delivery.
     */
    private static function collect_todo(\moodle_database $DB, int $studentid, int $limit,
            ?array $cursor, array &$rows): string {
        if (!$DB->get_manager()->table_exists('block_todo_items')) {
            return 'unavailable';
        }
        global $CFG;
        $todoaccess = $CFG->dirroot . '/blocks/todo/locallib.php';
        if (!is_readable($todoaccess)) {
            return 'blocked_scope';
        }
        require_once($todoaccess);
        if (!function_exists('block_todo_is_staff')) {
            return 'blocked_scope';
        }

        // block_todo enforces this same staff classifier on all CommHub writes.
        // Validate note authors through it rather than assuming an author is
        // staff merely because their id differs from the student.
        $candidateSql = "SELECT DISTINCT t.assignedby AS authorid
                           FROM {block_todo_items} t
                           JOIN {user} author ON author.id = t.assignedby AND author.deleted = 0
                          WHERE t.userid = :studentid
                            AND t.notetype = 1
                            AND t.assignedby > 1
                            AND t.assignedby <> :authornotstudent";
        try {
            $candidates = $DB->get_records_sql($candidateSql, [
                'studentid' => $studentid,
                'authornotstudent' => $studentid,
            ]);
        } catch (\Throwable $e) {
            return 'error';
        }
        $staffids = [];
        foreach ($candidates as $candidate) {
            $authorid = self::safe_positive_integer($candidate->authorid ?? null);
            if ($authorid === null) {
                continue;
            }
            try {
                $isstaff = block_todo_is_staff($authorid);
            } catch (\Throwable $e) {
                return 'blocked_scope';
            }
            if ($isstaff) {
                $staffids[$authorid] = $authorid;
            }
        }
        if (!$staffids) {
            return 'ok';
        }

        try {
            $authorparams = [];
            $authorplaceholders = [];
            foreach (array_values($staffids) as $index => $staffid) {
                $name = 'author' . $index;
                $authorplaceholders[] = ':' . $name;
                $authorparams[$name] = $staffid;
            }
            [$cursorcondition, $cursorparams] = self::cursor_condition('todo', 't.created', 't.id', $cursor);
            $sql = "SELECT t.id, t.task, t.created, t.emailnote
                      FROM {block_todo_items} t
                      JOIN {user} author ON author.id = t.assignedby AND author.deleted = 0
                     WHERE t.userid = :studentid
                       AND t.notetype = 1
                       AND t.assignedby > 1
                       AND t.assignedby <> :authornotstudent
                       AND t.assignedby IN (" . implode(',', $authorplaceholders) . ")
                       AND t.id > 0 AND t.id < 9007199254740992
                       AND t.created > 0 AND t.created < 9007199254740992
                       $cursorcondition
                  ORDER BY t.created DESC, t.id DESC";
            $params = ['studentid' => $studentid, 'authornotstudent' => $studentid] + $authorparams + $cursorparams;
            $records = $DB->get_records_sql($sql, $params, 0, $limit + 1);
            foreach ($records as $record) {
                $id = self::safe_positive_integer($record->id ?? null);
                $created = self::safe_positive_integer($record->created ?? null);
                if ($id === null || $created === null) {
                    continue;
                }
                $emailrequested = (int)$record->emailnote === 1;
                $rows[] = [
                    'source' => 'todo',
                    '_local' => $id,
                    'channel' => $emailrequested ? 'email' : 'note',
                    'subject' => '',
                    'body' => self::plaintext($record->task ?? '', FORMAT_HTML),
                    'status' => $emailrequested ? 'email_requested' : 'note',
                    'direction' => 'outbound',
                    'createdat' => $created,
                ];
            }
            return 'ok';
        } catch (\Throwable $e) {
            return 'error';
        }
    }

    /**
     * Read only verified direct student/staff conversations.
     *
     * local_studentcomm stores no reliable role flag: roletype is participant
     * by default and can be overridden on insertion. Its own service's
     * is_staff_user() is the trusted classifier (manageall capability,
     * configured Student Services accounts and configured trainer roles in
     * course contexts). Every direct conversation must currently contain
     * exactly two participants, including this student and one classified
     * staff member. Sender membership is also checked in the message query.
     */
    private static function collect_studentcomm(\moodle_database $DB, int $studentid, int $limit,
            ?array $cursor, array &$rows): string {
        $dbman = $DB->get_manager();
        foreach (['local_studentcomm_msg', 'local_studentcomm_conv', 'local_studentcomm_part'] as $table) {
            if (!$dbman->table_exists($table)) {
                return 'unavailable';
            }
        }
        $service = '\\local_studentcomm\\local\\service';
        if (!class_exists($service) || !method_exists($service, 'is_staff_user')) {
            return 'blocked_scope';
        }

        try {
            // A staff target is not a student contact for this endpoint; fail
            // closed rather than returning staff-to-staff or peer messages.
            $targetisstaff = $service::is_staff_user($studentid);
        } catch (\Throwable $e) {
            return 'blocked_scope';
        }
        if ($targetisstaff) {
            return 'blocked_scope';
        }

        $candidateSql = "SELECT DISTINCT otherp.userid AS counterpartid
                           FROM {local_studentcomm_conv} c
                           JOIN {local_studentcomm_part} studentp
                             ON studentp.conversationid = c.id AND studentp.userid = :studentid AND studentp.visible = 1
                           JOIN {local_studentcomm_part} otherp
                             ON otherp.conversationid = c.id AND otherp.userid <> :notstudent AND otherp.visible = 1
                           JOIN {user} counterpart ON counterpart.id = otherp.userid AND counterpart.deleted = 0
                          WHERE c.type = :directtype
                            AND (SELECT COUNT(*) FROM {local_studentcomm_part} countp
                                  WHERE countp.conversationid = c.id) = 2";
        try {
            $candidates = $DB->get_records_sql($candidateSql, [
                'studentid' => $studentid,
                'notstudent' => $studentid,
                'directtype' => 'direct',
            ]);
        } catch (\Throwable $e) {
            return 'error';
        }
        $staffids = [];
        foreach ($candidates as $candidate) {
            $counterpartid = self::safe_positive_integer($candidate->counterpartid ?? null);
            if ($counterpartid === null) {
                continue;
            }
            try {
                $isstaff = $service::is_staff_user($counterpartid);
            } catch (\Throwable $e) {
                return 'blocked_scope';
            }
            if ($isstaff) {
                $staffids[$counterpartid] = $counterpartid;
            }
        }
        if (!$staffids) {
            return 'ok';
        }

        try {
            $staffparams = [];
            $staffplaceholders = [];
            foreach (array_values($staffids) as $index => $staffid) {
                $name = 'staff' . $index;
                $staffplaceholders[] = ':' . $name;
                $staffparams[$name] = $staffid;
            }
            $senderplaceholders = [];
            $senderparams = [];
            foreach (array_values($staffids) as $index => $staffid) {
                $name = 'senderstaff' . $index;
                $senderplaceholders[] = ':' . $name;
                $senderparams[$name] = $staffid;
            }
            [$cursorcondition, $cursorparams] =
                self::cursor_condition('studentcomm', 'm.timecreated', 'm.id', $cursor);
            $sql = "SELECT m.id, m.userid AS senderid, m.message, m.format, m.timecreated, c.subject
                      FROM {local_studentcomm_msg} m
                      JOIN {local_studentcomm_conv} c ON c.id = m.conversationid AND c.type = :directtype
                      JOIN {local_studentcomm_part} studentp
                        ON studentp.conversationid = m.conversationid AND studentp.userid = :studentid AND studentp.visible = 1
                      JOIN {local_studentcomm_part} otherp
                        ON otherp.conversationid = m.conversationid AND otherp.userid <> :notstudent AND otherp.visible = 1
                      JOIN {local_studentcomm_part} senderp
                        ON senderp.conversationid = m.conversationid AND senderp.userid = m.userid
                      JOIN {user} counterpart ON counterpart.id = otherp.userid AND counterpart.deleted = 0
                      JOIN {user} sender ON sender.id = m.userid AND sender.deleted = 0
                     WHERE (SELECT COUNT(*) FROM {local_studentcomm_part} countp
                             WHERE countp.conversationid = c.id) = 2
                       AND otherp.userid IN (" . implode(',', $staffplaceholders) . ")
                       AND (m.userid = :student_sender OR m.userid IN (" . implode(',', $senderplaceholders) . "))
                       AND m.id > 0 AND m.id < 9007199254740992
                       AND m.timecreated > 0 AND m.timecreated < 9007199254740992
                       $cursorcondition
                  ORDER BY m.timecreated DESC, m.id DESC";
            $params = [
                'directtype' => 'direct',
                'studentid' => $studentid,
                'notstudent' => $studentid,
                'student_sender' => $studentid,
            ] + $staffparams + $senderparams + $cursorparams;
            $records = $DB->get_records_sql($sql, $params, 0, $limit + 1);
            foreach ($records as $record) {
                $id = self::safe_positive_integer($record->id ?? null);
                $created = self::safe_positive_integer($record->timecreated ?? null);
                $senderid = self::safe_positive_integer($record->senderid ?? null);
                if ($id === null || $created === null || $senderid === null) {
                    continue;
                }
                $rows[] = [
                    'source' => 'studentcomm',
                    '_local' => $id,
                    'channel' => 'message',
                    'subject' => self::plaintext($record->subject ?? '', FORMAT_HTML),
                    'body' => self::plaintext($record->message ?? '', (int)($record->format ?? FORMAT_HTML)),
                    'status' => '',
                    'direction' => $senderid === $studentid ? 'inbound' : 'outbound',
                    'createdat' => $created,
                ];
            }
            return 'ok';
        } catch (\Throwable $e) {
            return 'error';
        }
    }

    /** Read optional plugin automation log, only when explicitly requested. */
    private static function collect_autolog(\moodle_database $DB, int $studentid, int $limit,
            ?array $cursor, array &$rows): string {
        if (!$DB->get_manager()->table_exists('local_lmshomepage_commhub')) {
            return 'unavailable';
        }
        try {
            [$cursorcondition, $cursorparams] = self::cursor_condition('autolog', 'a.timesent', 'a.id', $cursor);
            $sql = "SELECT a.id, a.timesent, a.channel, a.message_subject, a.message_body, a.status
                      FROM {local_lmshomepage_commhub} a
                     WHERE a.studentid = :studentid
                       AND a.id > 0 AND a.id < 9007199254740992
                       AND a.timesent > 0 AND a.timesent < 9007199254740992
                       $cursorcondition
                  ORDER BY a.timesent DESC, a.id DESC";
            $records = $DB->get_records_sql($sql, ['studentid' => $studentid] + $cursorparams, 0, $limit + 1);
            foreach ($records as $record) {
                $id = self::safe_positive_integer($record->id ?? null);
                $created = self::safe_positive_integer($record->timesent ?? null);
                if ($id === null || $created === null) {
                    continue;
                }
                $rows[] = [
                    'source' => 'autolog',
                    '_local' => $id,
                    'channel' => self::plain_label($record->channel ?? ''),
                    'subject' => self::plaintext($record->message_subject ?? '', FORMAT_HTML),
                    'body' => self::plaintext($record->message_body ?? '', FORMAT_HTML),
                    'status' => self::plain_label($record->status ?? ''),
                    'direction' => 'outbound',
                    'createdat' => $created,
                ];
            }
            return 'ok';
        } catch (\Throwable $e) {
            return 'error';
        }
    }

    /**
     * SQL-exclusive keyset condition for this source's place in global sort.
     * At a timestamp tie, source is ascending and local id descending.
     */
    private static function cursor_condition(string $source, string $timefield, string $idfield,
            ?array $cursor): array {
        if ($cursor === null) {
            return ['', []];
        }
        $sourceorder = strcmp($source, $cursor['source']);
        if ($sourceorder < 0) {
            return ["AND $timefield < :cursor_time", ['cursor_time' => $cursor['createdat']]];
        }
        if ($sourceorder > 0) {
            return ["AND $timefield <= :cursor_time", ['cursor_time' => $cursor['createdat']]];
        }
        return [
            "AND ($timefield < :cursor_time OR ($timefield = :cursor_equal_time AND $idfield < :cursor_local))",
            [
                'cursor_time' => $cursor['createdat'],
                'cursor_equal_time' => $cursor['createdat'],
                'cursor_local' => $cursor['_local'],
            ],
        ];
    }

    /** Parse a non-empty, strictly supported source CSV; never fall back silently. */
    private static function parse_sources(string $sources): ?array {
        if (strlen($sources) > 100 || trim($sources) === '') {
            return null;
        }
        $requested = [];
        foreach (explode(',', $sources) as $source) {
            $source = strtolower(trim($source));
            if ($source === '' || !in_array($source, self::KNOWN_SOURCES, true) || isset($requested[$source])) {
                return null;
            }
            $requested[$source] = true;
        }
        return $requested ?: null;
    }

    /** Parse and range-check a bounded cursor. */
    private static function parse_cursor(string $cursor): ?array {
        if (strlen($cursor) > 200
                || !preg_match('/^([1-9][0-9]{0,15}):(autolog|studentcomm|todo):([1-9][0-9]{0,15})$/D', $cursor, $matches)) {
            return null;
        }
        $created = self::safe_positive_integer($matches[1]);
        $localid = self::safe_positive_integer($matches[3]);
        if ($created === null || $localid === null) {
            return null;
        }
        return ['createdat' => $created, 'source' => $matches[2], '_local' => $localid];
    }

    /** Return a positive exact JS-safe integer, or null for invalid values. */
    private static function safe_positive_integer($value): ?int {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return null;
        }
        $string = (string)$value;
        if ($string === '' || $string === '0' || strlen($string) > 16
                || (strlen($string) === 16 && strcmp($string, '9007199254740991') > 0)) {
            return null;
        }
        $integer = (int)$string;
        return $integer > 0 ? $integer : null;
    }

    /** Convert stored HTML/plain text into safe plaintext. */
    private static function plaintext($content, $format): string {
        $content = (string)$content;
        return $content === '' ? '' : content_to_text($content, $format);
    }

    /** Keep source labels plain text and bounded. */
    private static function plain_label($value): string {
        return clean_param((string)$value, PARAM_TEXT);
    }

    private static function coverage_all(string $state): array {
        return ['todo' => $state, 'studentcomm' => $state, 'autolog' => $state];
    }

    private static function error(string $code, array $coverage): array {
        return [
            'status' => 'error',
            'error' => $code,
            'studentid' => 0,
            'messages' => [],
            'coverage' => $coverage,
            'nextcursor' => '',
        ];
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_ALPHA, 'ok or error.'),
            'error' => new \external_value(PARAM_ALPHANUMEXT, 'Error code; empty on success.'),
            'studentid' => new \external_value(PARAM_INT, 'Requested student id, or 0 on error.'),
            'messages' => new \external_multiple_structure(new \external_single_structure([
                'id' => new \external_value(PARAM_TEXT, 'Stable source-qualified id, source:localid.'),
                'source' => new \external_value(PARAM_ALPHA, 'todo, studentcomm, or autolog.'),
                'studentid' => new \external_value(PARAM_INT, 'Student this message belongs to.'),
                'channel' => new \external_value(PARAM_TEXT, 'Source channel.'),
                'subject' => new \external_value(PARAM_TEXT, 'Plaintext subject, possibly empty.'),
                'body' => new \external_value(PARAM_TEXT, 'Sanitised plaintext message body.'),
                'status' => new \external_value(PARAM_TEXT, 'Truthful source status; email_requested is not delivery.'),
                'direction' => new \external_value(PARAM_ALPHA, 'inbound or outbound.'),
                'createdAt' => new \external_value(PARAM_INT, 'Positive Unix timestamp in seconds.'),
            ])),
            'coverage' => new \external_single_structure([
                'todo' => new \external_value(PARAM_ALPHANUMEXT, 'ok, unavailable, error, blocked_scope, or excluded.'),
                'studentcomm' => new \external_value(PARAM_ALPHANUMEXT, 'ok, unavailable, error, blocked_scope, or excluded.'),
                'autolog' => new \external_value(PARAM_ALPHANUMEXT, 'ok, unavailable, error, or excluded.'),
            ]),
            'nextcursor' => new \external_value(PARAM_TEXT, 'Opaque exclusive keyset cursor; empty when exhausted.'),
        ]);
    }
}