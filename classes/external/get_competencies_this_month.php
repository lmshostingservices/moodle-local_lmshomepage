<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// You can redistribute it and/or modify it under the terms of the GNU General Public License
// As published by the Free Software Foundation, either version 3 of the License, or any later version.
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// However, it is WITHOUT ANY WARRANTY, including implied warranties of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
// See the GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// You should have received a copy of the GNU General Public License along with Moodle.
// If not, see <http://www.gnu.org/licenses/>.

/**
 * External function: local_lmshomepage_get_competencies_this_month
 *
 * ADDED (v2.11.48) — Dashboard Data Review (12 Aug 2026), Issue 1.
 *
 * The previous "Units Completed this Month" KPI counted raw
 * mdl_course_completions rows regardless of WHY the course was marked
 * complete. block_trainingplan's cron writes that same timecompleted field
 * for THREE different outcomes (C, CT, RPL — see
 * update_trainingplan_status::mark_course_completed()), so the KPI included
 * RPL/CT completions alongside genuine assessed competencies. Live check on
 * 12 Aug 2026: of ~210 raw completions that month, 135 were RPL, 11 were CT,
 * and only 19 were real marksheet-based C outcomes — while the separate
 * "Completed Units" report (which does filter more narrowly) showed 37,
 * and 36 marking sheets were actually approved that month. Hence the
 * 196-vs-37 KPI/report mismatch reported by the client.
 *
 * This function is a genuinely NEW data source: it counts only
 * genuine competencies — one row per APPROVED marking sheet
 * (local_finalmarkingsheet.status = 'approved'), dated by
 * deemedcompetentdate (the date the assessor actually marked the student
 * competent), which is unaffected by cron-timing drift on
 * mdl_course_completions.timecompleted.
 *
 * Rows are selected from approved marking sheets, then their Training Plan
 * outcome is resolved as at the end of the reporting month. This prevents a
 * later Training Plan change from rewriting a historical report where a
 * transition log is available.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_competencies_this_month
 *   date_from=0        (int, unix timestamp. 0 = start of current calendar month.)
 *   date_to=0          (int, unix timestamp. 0 = start of next calendar month.)
 *   cohort_id=0        (int, optional — 0 = all cohorts)
 *   trainer_userid=0   (int, optional — 0 = all trainers)
 *
 * Both date_from and date_to must be supplied together to override the
 * "this month" default; supplying only one is treated as "use the default
 * for both" (fail-safe rather than producing an unbounded query).
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_competencies_this_month extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'date_from' => new \external_value(
                PARAM_INT,
                'Start of date range (unix timestamp on deemedcompetentdate). 0 = start of current calendar month.',
                VALUE_DEFAULT,
                0
            ),
            'date_to' => new \external_value(
                PARAM_INT,
                'End of date range (unix timestamp on deemedcompetentdate). 0 = start of next calendar month.',
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
        if (!$dbman->table_exists('local_finalmarkingsheet')) {
            // Site does not use the marking sheet plugin — nothing to report.
            return [];
        }

        // Default to the current calendar month when either bound is unset.
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
            'fms.userid',
            'fms.courseid',
            $date_to - 1,
            $params
        );

        // ── Trainer from 'trainername' custom profile field ────────────────
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
                fms.id                                                    AS marksheetid,
                fms.userid                                                AS userid,
                {$sqlFullname}                                            AS fullname,
                u.email,
                fms.courseid                                              AS courseid,
                c.shortname                                               AS course_shortname,
                COALESCE(NULLIF(c.fullname, ''), c.shortname)            AS course_fullname,
                COALESCE(coh_sub.cohort_name, '')                        AS cohort_name,
                fms.deemedcompetentdate                                   AS deemed_competent_date,
                COALESCE(NULLIF(hist.newoutcome, ''), NULLIF(tp.outcome, ''), '') AS outcome,
                COALESCE(hist.newoutcome, '')                             AS history_outcome,
                COALESCE(tp.outcome, '')                                  AS current_outcome,
                COALESCE(trainer.id, 0)                                  AS trainer_id,
                COALESCE({$sqlTrainerFullname}, NULLIF(TRIM(uid_tr.data), ''), '') AS trainer_name
            FROM {local_finalmarkingsheet} fms
            JOIN {user}   u  ON u.id = fms.userid
                             AND u.deleted = 0 AND u.suspended = 0
                             AND u.id != 1 AND u.username != 'guest'
            JOIN {course} c  ON c.id = fms.courseid AND c.id != 1
            LEFT JOIN (
                SELECT cm_i.userid, MIN(coh_i.name) AS cohort_name
                FROM   {cohort_members} cm_i
                JOIN   {cohort} coh_i ON coh_i.id = cm_i.cohortid
                GROUP  BY cm_i.userid
            ) coh_sub ON coh_sub.userid = u.id
            $trainerJoin
            LEFT JOIN {block_trainingplan_userseq} tp
                ON tp.userid = fms.userid AND tp.courseid = fms.courseid
            $historyJoin
            $cohortJoin
            WHERE fms.status = 'approved'
              AND fms.deemedcompetentdate >= :date_from
              AND fms.deemedcompetentdate <  :date_to
              $trainerWhere
            ORDER BY fms.deemedcompetentdate DESC
        ";

        $rows = $DB->get_records_sql($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $unitName = (string)$row->course_fullname;
            $outcome = (string)$row->outcome;
            if ((string)$row->history_outcome !== '') {
                $basis = 'historical';
            } else if ($outcome === '') {
                $basis = 'unavailable';
            } else {
                $basis = $historyStatus['available'] ? 'current_no_transition' : 'current_no_history';
            }
            $result[] = [
                'userid'                => (int)    $row->userid,
                'fullname'              => (string) $row->fullname,
                'email'                 => (string) $row->email,
                'courseid'              => (int)    $row->courseid,
                'unit_code'             => (string) $row->course_shortname,
                'unit_name'             => $unitName,
                'cohort_name'           => (string) $row->cohort_name,
                'deemed_competent_date' => (int)    $row->deemed_competent_date,
                'outcome'               => $outcome,
                'outcome_basis'         => $basis,
                'history_earliest'      => (int)$historyStatus['earliest'],
                'trainer_id'            => (int)    $row->trainer_id,
                'trainer_name'          => (string) $row->trainer_name,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'                => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'              => new \external_value(PARAM_TEXT, 'Student full name'),
                'email'                 => new \external_value(PARAM_TEXT, 'Student email'),
                'courseid'              => new \external_value(PARAM_INT,  'Course ID (= VET unit)'),
                'unit_code'             => new \external_value(PARAM_TEXT, 'VET unit code (course shortname)'),
                'unit_name'             => new \external_value(PARAM_TEXT, 'Full VET unit name'),
                'cohort_name'           => new \external_value(PARAM_TEXT, 'Cohort name'),
                'deemed_competent_date' => new \external_value(PARAM_INT,  'Date assessor marked student competent (local_finalmarkingsheet.deemedcompetentdate)'),
                'outcome'               => new \external_value(PARAM_TEXT, 'Training Plan outcome as at the end of the reporting month; unexpected values are returned unchanged'),
                'outcome_basis'         => new \external_value(PARAM_ALPHAEXT, 'historical, current_no_transition, current_no_history, or unavailable'),
                'history_earliest'      => new \external_value(PARAM_INT, 'Earliest available Training Plan outcome transition timestamp (0 when no valid log is available)'),
                'trainer_id'            => new \external_value(PARAM_INT,  'Trainer user ID (0 = unassigned or unmatched)'),
                'trainer_name'          => new \external_value(PARAM_TEXT, 'Trainer name from trainername profile field'),
            ])
        );
    }
}
