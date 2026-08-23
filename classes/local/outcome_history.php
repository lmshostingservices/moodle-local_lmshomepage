<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Training Plan outcome-transition discovery and as-at query helpers.
 *
 * The Training Plan block is maintained separately from this plugin. Its
 * transition-log table name has varied between deployments, so we only use a
 * table once its columns prove that it can reconstruct an outcome safely.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\local;

defined('MOODLE_INTERNAL') || die();

class outcome_history {
    /** @var array|false|null */
    private static $status = null;

    /**
     * Locate a usable Training Plan outcome-transition table and its earliest
     * entry. Returns available=false rather than guessing when no validated
     * schema is present.
     *
     * @return array{available:bool, earliest:int, table:string, id:string, userid:string, courseid:string, newoutcome:string, timecreated:string}
     */
    public static function status(): array {
        global $DB;

        if (self::$status !== null) {
            return self::$status;
        }

        $empty = [
            'available' => false,
            'earliest' => 0,
            'table' => '',
            'id' => '',
            'userid' => '',
            'courseid' => '',
            'newoutcome' => '',
            'timecreated' => '',
        ];

        // Known naming styles first, then inspect similarly named tables when
        // Moodle's DB driver supports table enumeration.
        $tables = [
            'block_trainingplan_outcome_transition_log',
            'block_trainingplan_outcome_transitions',
            'block_trainingplan_outcome_transition',
            'block_trainingplan_outcome_log',
            'block_trainingplan_userseq_outcome_log',
            'block_trainingplan_userseq_history',
        ];
        if (method_exists($DB, 'get_tables')) {
            foreach ($DB->get_tables() as $table) {
                $lower = strtolower((string)$table);
                if (strpos($lower, 'trainingplan') !== false
                    && (strpos($lower, 'outcome') !== false || strpos($lower, 'transition') !== false)
                ) {
                    $tables[] = (string)$table;
                }
            }
        }

        foreach (array_unique($tables) as $table) {
            if (!$DB->get_manager()->table_exists($table)) {
                continue;
            }

            $columns = $DB->get_columns($table);
            $names = [];
            foreach ($columns as $name => $_column) {
                $names[strtolower((string)$name)] = (string)$name;
            }

            $id = self::column($names, ['id']);
            $userid = self::column($names, ['userid', 'user_id']);
            $courseid = self::column($names, ['courseid', 'course_id']);
            $newoutcome = self::column($names, ['newoutcome', 'new_outcome', 'outcome_new', 'newoutcomecode']);
            $timecreated = self::column($names, ['timecreated', 'createdat', 'created_at', 'timestamp']);
            if (!$id || !$userid || !$courseid || !$newoutcome || !$timecreated) {
                continue;
            }

            $earliest = (int)$DB->get_field_sql(
                "SELECT MIN({$timecreated}) FROM {{$table}}"
            );
            self::$status = [
                'available' => true,
                'earliest' => $earliest,
                'table' => $table,
                'id' => $id,
                'userid' => $userid,
                'courseid' => $courseid,
                'newoutcome' => $newoutcome,
                'timecreated' => $timecreated,
            ];
            return self::$status;
        }

        self::$status = $empty;
        return self::$status;
    }

    /**
     * Build a MariaDB collation-safe resolved-outcome expression.
     *
     * The Training Plan block owns both the current-outcome and transition-log
     * schemas. On Wombat, its current outcome column uses utf8mb4_unicode_ci
     * while the transition log uses utf8mb4_general_ci. MariaDB gives a
     * mixed-collation COALESCE expression coercibility NONE, then rejects a
     * comparison such as "= 'RPL'" with Error 1267. Apply one explicit
     * collation to both nullable values before combining them.
     *
     * @param string $historyalias SQL alias returned by as_at_join().
     * @param string $currentfield Fully-qualified current outcome SQL field.
     * @return string SQL expression resolving historical then current outcome.
     */
    public static function resolved_outcome_expression(
        string $historyalias,
        string $currentfield
    ): string {
        return "
            COALESCE(
                NULLIF({$historyalias}.newoutcome, '') COLLATE utf8mb4_general_ci,
                NULLIF({$currentfield}, '') COLLATE utf8mb4_general_ci,
                ''
            )
        ";
    }

    /**
     * Build a correlated join that returns the newest transition at or before
     * $asat for a user/course pair. An ID tie-breaker makes equal timestamps
     * deterministic.
     *
     * @param string $alias SQL alias for the joined transition row.
     * @param string $useridfield Fully-qualified source user ID field.
     * @param string $courseidfield Fully-qualified source course ID field.
     * @param int $asat Unix timestamp inclusive.
     * @param array $params SQL parameter array, updated in-place.
     * @return string SQL LEFT JOIN or an empty string when no valid log exists.
     */
    public static function as_at_join(
        string $alias,
        string $useridfield,
        string $courseidfield,
        int $asat,
        array &$params
    ): string {
        $status = self::status();
        if (!$status['available']) {
            return '';
        }

        $param = 'outcome_history_asat_' . count($params);
        $params[$param] = $asat;
        $table = $status['table'];
        $id = $status['id'];
        $userid = $status['userid'];
        $courseid = $status['courseid'];
        $newoutcome = $status['newoutcome'];
        $timecreated = $status['timecreated'];

        return "
            LEFT JOIN (
                SELECT h.{$id} AS historyid,
                       h.{$userid} AS userid,
                       h.{$courseid} AS courseid,
                       h.{$newoutcome} AS newoutcome
                  FROM {{$table}} h
                 WHERE h.{$id} = (
                    SELECT MAX(h2.{$id})
                      FROM {{$table}} h2
                     WHERE h2.{$userid} = h.{$userid}
                       AND h2.{$courseid} = h.{$courseid}
                       AND h2.{$timecreated} = (
                            SELECT MAX(h3.{$timecreated})
                              FROM {{$table}} h3
                             WHERE h3.{$userid} = h.{$userid}
                               AND h3.{$courseid} = h.{$courseid}
                               AND h3.{$timecreated} <= :{$param}
                       )
                 )
            ) {$alias}
              ON {$alias}.userid = {$useridfield}
             AND {$alias}.courseid = {$courseidfield}
        ";
    }

    /**
     * @param array $available Lowercase column name => actual column name.
     * @param array $candidates Candidate lowercase column names.
     * @return string
     */
    private static function column(array $available, array $candidates): string {
        foreach ($candidates as $candidate) {
            if (isset($available[$candidate])) {
                return $available[$candidate];
            }
        }
        return '';
    }
}