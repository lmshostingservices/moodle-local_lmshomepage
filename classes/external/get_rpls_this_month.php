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
 * External function: local_lmshomepage_get_rpls_this_month
 *
 * ADDED (v2.11.48) — Dashboard Data Review (12 Aug 2026), Issue 1.
 *
 * Companion to local_lmshomepage_get_competencies_this_month. Per the
 * client's request, this recognises individual Training Plan units with an
 * RPL outcome as at the end of the reporting month, where the unit's
 * end/completion date (block_trainingplan_userseq.enddate) falls within it.
 *
 * Together, get_competencies_this_month + get_rpls_this_month replace the
 * old single "Units Completed this Month" KPI (which conflated C, CT, and
 * RPL outcomes via the shared mdl_course_completions write path — see that
 * function's docblock for the full root-cause writeup) with two KPIs that
 * each have one clear, auditable data source.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_rpls_this_month
 *   date_from=0        (int, unix timestamp. 0 = start of current calendar month.)
 *   date_to=0          (int, unix timestamp. 0 = start of next calendar month.)
 *   cohort_id=0        (int, optional — 0 = all cohorts)
 *   trainer_userid=0   (int, optional — 0 = all trainers)
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_rpls_this_month extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'date_from' => new \external_value(
                PARAM_INT,
                'Start of date range (unix timestamp on Training Plan enddate). 0 = start of current calendar month.',
                VALUE_DEFAULT,
                0
            ),
            'date_to' => new \external_value(
                PARAM_INT,
                'End of date range (unix timestamp on Training Plan enddate). 0 = start of next calendar month.',
                VALUE_DEFAULT,
                0
            ),
            'cohort_id' => new \external_value(
                PARAM_INT,
                'Filter to a specific cohort ID. 0 = all cohorts.',
                VALUE_DEFAULT,
                0
            ),
            'trainer_userid' => new \external_value(
                PARAM_INT,
                'Filter to a specific trainer user ID. 0 = all trainers.',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute(int $date_from = 0, int $date_to = 0, int $cohort_id = 0, int $trainer_userid = 0): array {
        global $DB;

        self::validate_context(\context_system::instance());
        self::validate_parameters(self::execute_parameters(), [
            'date_from'      => $date_from,
            'date_to'        => $date_to,
            'cohort_id'      => $cohort_id,
            'trainer_userid' => $trainer_userid,
        ]);

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('block_trainingplan_userseq')) {
            // Site does not use block_trainingplan — nothing to report.
            return [];
        }

        if ($date_from <= 0 || $date_to <= 0) {
            $now        = time();
            $monthstart = mktime(0, 0, 0, (int)date('n', $now), 1, (int)date('Y', $now));
            $nextmonth  = mktime(0, 0, 0, (int)date('n', $now) + 1, 1, (int)date('Y', $now));
            $date_from  = $monthstart;
            $date_to    = $nextmonth;
        }

        $params = [
            'date_from' => $date_from,
            'date_to'   => $date_to,
        ];
        $historyStatus = \local_lmshomepage\local\outcome_history::status();
        $historyJoin = \local_lmshomepage\local\outcome_history::as_at_join(
            'hist',
            'tp.userid',
            'tp.courseid',
            $date_to - 1,
            $params
        );
        $outcomeExpression = \local_lmshomepage\local\outcome_history::resolved_outcome_expression(
            'hist',
            'tp.outcome'
        );
        $historyOutcomeExpression = \local_lmshomepage\local\outcome_history::outcome_or_empty_expression(
            'hist.newoutcome'
        );
        $currentOutcomeExpression = \local_lmshomepage\local\outcome_history::outcome_or_empty_expression(
            'tp.outcome'
        );
        $rplOutcomeExpression = \local_lmshomepage\local\outcome_history::outcome_literal_expression('RPL');

        $trainerFieldId = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'trainername']);
        $params['tf_id'] = $trainerFieldId > 0 ? $trainerFieldId : -1;

        $sqlFullname        = $DB->sql_concat('u.firstname', "' '", 'u.lastname');
        $sqlTrainerFullname = $DB->sql_concat('trainer.firstname', "' '", 'trainer.lastname');

        $trainerJoin = "
            LEFT JOIN {user_info_data} uid_tr
                ON uid_tr.userid = u.id AND uid_tr.fieldid = :tf_id
            LEFT JOIN {user} trainer
                ON trainer.deleted = 0 AND trainer.suspended = 0
               AND LOWER(TRIM({$sqlTrainerFullname}))
                 = LOWER(TRIM(uid_tr.data))
        ";

        $trainerWhere = '';
        if ($trainer_userid > 0) {
            $params['trainer_userid'] = $trainer_userid;
            $trainerWhere = "AND trainer.id = :trainer_userid";
        }

        $cohortJoin = '';
        if ($cohort_id > 0) {
            $params['cohort_id'] = $cohort_id;
            $cohortJoin = "JOIN {cohort_members} cm_flt ON cm_flt.userid = u.id AND cm_flt.cohortid = :cohort_id";
        }

        $sql = "
            SELECT
                tp.id                                                     AS userseqid,
                tp.userid                                                 AS userid,
                {$sqlFullname}                                            AS fullname,
                u.email,
                tp.courseid                                               AS courseid,
                c.shortname                                               AS course_shortname,
                COALESCE(NULLIF(c.fullname, ''), c.shortname)            AS course_fullname,
                COALESCE(coh_sub.cohort_name, '')                        AS cohort_name,
                tp.enddate                                                AS rpl_date,
                $outcomeExpression                                      AS outcome,
                $historyOutcomeExpression                                  AS history_outcome,
                $currentOutcomeExpression                                  AS current_outcome,
                COALESCE(trainer.id, 0)                                  AS trainer_id,
                COALESCE({$sqlTrainerFullname}, NULLIF(TRIM(uid_tr.data), ''), '') AS trainer_name
            FROM {block_trainingplan_userseq} tp
            JOIN {user}   u  ON u.id = tp.userid
                             AND u.deleted = 0 AND u.suspended = 0
                             AND u.id != 1 AND u.username != 'guest'
            JOIN {course} c  ON c.id = tp.courseid AND c.id != 1
            LEFT JOIN (
                SELECT cm_i.userid, MIN(coh_i.name) AS cohort_name
                FROM   {cohort_members} cm_i
                JOIN   {cohort} coh_i ON coh_i.id = cm_i.cohortid
                GROUP  BY cm_i.userid
            ) coh_sub ON coh_sub.userid = u.id
            $trainerJoin
            $historyJoin
            $cohortJoin
            WHERE $outcomeExpression = $rplOutcomeExpression
              AND tp.enddate >= :date_from
              AND tp.enddate <  :date_to
              $trainerWhere
            ORDER BY tp.enddate DESC
        ";

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $unitName = (string)$row->course_fullname;
            $outcome = (string)$row->outcome;
            $basis = (string)$row->history_outcome !== ''
                ? 'historical'
                : ($historyStatus['available'] ? 'current_no_transition' : 'current_no_history');
            $result[] = [
                'userid'       => (int)    $row->userid,
                'fullname'     => (string) $row->fullname,
                'email'        => (string) $row->email,
                'courseid'     => (int)    $row->courseid,
                'unit_code'    => (string) $row->course_shortname,
                'unit_name'    => $unitName,
                'cohort_name'  => (string) $row->cohort_name,
                'rpl_date'     => (int)    $row->rpl_date,
                'outcome'      => $outcome,
                'outcome_basis'=> $basis,
                'history_earliest' => (int)$historyStatus['earliest'],
                'trainer_id'   => (int)    $row->trainer_id,
                'trainer_name' => (string) $row->trainer_name,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'       => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'     => new \external_value(PARAM_TEXT, 'Student full name'),
                'email'        => new \external_value(PARAM_TEXT, 'Student email'),
                'courseid'     => new \external_value(PARAM_INT,  'Course ID (= VET unit)'),
                'unit_code'    => new \external_value(PARAM_TEXT, 'VET unit code (course shortname)'),
                'unit_name'    => new \external_value(PARAM_TEXT, 'Full VET unit name'),
                'cohort_name'  => new \external_value(PARAM_TEXT, 'Cohort name'),
                'rpl_date'     => new \external_value(PARAM_INT,  'Training Plan end/completion date (block_trainingplan_userseq.enddate)'),
                'outcome'      => new \external_value(PARAM_TEXT, 'Training Plan outcome as at the end of the reporting month'),
                'outcome_basis'=> new \external_value(PARAM_ALPHAEXT, 'historical, current_no_transition, or current_no_history'),
                'history_earliest' => new \external_value(PARAM_INT, 'Earliest available Training Plan outcome transition timestamp (0 when no valid log is available)'),
                'trainer_id'   => new \external_value(PARAM_INT,  'Trainer user ID (0 = unassigned or unmatched)'),
                'trainer_name' => new \external_value(PARAM_TEXT, 'Trainer name from trainername profile field'),
            ])
        );
    }
}
