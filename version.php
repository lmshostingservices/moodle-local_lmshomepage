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
 * Version metadata for the local_lmshomepage plugin.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2026082400;        // YYYYMMDDNN — standard 10-digit Moodle format; NN = 00–99 daily increment
$plugin->requires  = 2022041900;        // Moodle 4.0+
$plugin->component = 'local_lmshomepage';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '2.11.60';         // 2.11.60: collation-safe historical Training Plan outcomes.
                                         // 2.11.58: get_assessment_submissions performance rewrite.
                                         // Replaces whole-table Training Plan aggregates with
                                         // student/course-correlated lookups, resolves trainer
                                         // names once per request, and resolves trainer pseudo-group
                                         // labels once before group deduplication. Output contract,
                                         // exclusions, grade MAX(id) deduplication, table guards,
                                         // and PHP post-processing are preserved. No schema changes.
                                         // 2.11.57: historical Training Plan outcomes in monthly reports.
                                          // Outcomes resolve to the latest validated transition at
                                          // the end of the reporting month, with an explicit current
                                          // fallback when the transition log has no earlier entry.
                                          // 2.11.56: configurable reporting exclusions.
                                         // Excluded Moodle usernames and course full names are
                                         // newline-separated, case-insensitive whole-value patterns
                                         // with * wildcard support. Applied in the assessment SQL query
                                         // shared by the Trainer Marking Report and dashboard KPIs.
                                         // 2.11.55: HOTFIX on top of 2.11.54.
                                         // 2.11.54 shipped a fatal PHP parse error: an unescaped
                                         // double quote inside a double-quoted SQL string in
                                         // get_trainer_allocations.php closed the string early,
                                         // making the file unparseable. That killed the function
                                         // AND broke admin/webservice/service_functions.php, since
                                         // that page classloads every external function.
                                         // Also escaped a ${...} sequence in a SQL comment in
                                         // get_assessment_submissions.php that interpolated two
                                         // undefined variables and emitted deprecation notices
                                         // into the web service output buffer.
                                         // 2.11.54: Reconciled reporting sources and recordset handling.
                                         // Bug 1 (RPT-16): Group column showed trainer name instead of group.
                                         //   get_assessment_submissions + get_trainer_allocations: the group
                                         //   dedup subquery selected MIN(group_id/name) with no filter on group
                                         //   type. Trainer-allocation pseudo-groups (e.g. "Steph", "Mel") are
                                         //   real Moodle groups and were selected when a student had no real
                                         //   cohort group. Fixed: added NOT EXISTS in both group subqueries to
                                         //   exclude groups whose name matches any trainername profile field
                                         //   value. Group now returns blank when no real group exists.
                                         // Bug 2 (RPT-17): Staff accounts appearing as students in Marking
                                         //   Report. Staff test accounts (Steph, Mel) held active student role
                                         //   enrolments so the SQL student-role filter passed. Fixed in PHP
                                         //   post-processing: rows with no cohort AND no training plan are
                                         //   skipped. Guard: only applied when TP tables exist, so genuine
                                         //   uncohorted students on sites without the training plan block are
                                         //   unaffected.
                                         // No schema changes.
                                         // 2.11.52: Seven report fixes (RPT-3/8/9/11/13/14/15).
                                         // get_completed_units: uses local_finalmarkingsheet.deemedcompetentdate
                                         //   (status='approved') as primary completion source when table present,
                                         //   falling back to course_completions.timecompleted (RPT-3 alignment).
                                         // get_assessment_submissions: adds has_training_plan field (1 if student
                                         //   has a TP due date, 0 if falling back to native assign.duedate).
                                         //   Client filter "Training Plan only" hides students with no plan (RPT-9).
                                         // get_trainer_allocations: group query now filters to visible courses
                                         //   (JOIN {course} gc ON gc.visible=1) to match enrolment scope (RPT-15).
                                         // Dashboard assessment-status KPI: days_lookback changed 180→365 to match
                                         //   getWombatReportMarking window — aligns overdueSubmissions / gradingBacklog
                                         //   KPI counts with Trainer Marking Report CSV totals (RPT-8 / RPT-11).
                                         // Matrix "View marking report" link: replaced wouter <Link> with hard
                                         //   window.location.href navigation so URL params re-read on mount (RPT-13).
                                         // Matrix CSV: switched from transposed (unit-rows, student-columns) to long
                                         //   format (one row per student × unit) with items_complete/items_total (RPT-14).
                                         // 2.11.51: get_trainer_allocations now returns real group_name — added a
                                         // GROUP BY subquery on {groups_members} + {groups} to resolve the
                                         // alphabetically-first Moodle group name per student. Previously the field
                                         // was hardcoded to '' (empty string) for API compatibility since v2.11.25,
                                         // leaving the Group column blank in the Trainer Allocation Report.
                                         // No schema changes; no other functions changed.
                                         // 2.11.50: Fixed get_assessment_submissions — the assign_grades LEFT JOIN
                                         // had no dedup constraint, so a student graded more than once on the same
                                         // assignment (regrade / resubmission) produced one duplicate result row
                                         // per extra grade record. Found via client testing: 'Keira Wright /
                                         // CHCCOM005 v25.0 / Assessment task 1' appeared 5+ times in one CSV
                                         // export. This same duplication was inflating the dashboard's Overdue
                                         // Submissions KPI (549, which counts rows from this function) and was
                                         // breaking Trainer Marking Report pagination client-side (duplicate rows
                                         // produced duplicate React keys, freezing the page-forward control after
                                         // page 5). Constrained the JOIN to the single latest qualifying grade
                                         // record per (assignment, student) via a MAX(id) subquery. No other
                                         // function changed; no schema changes.
                                         // 2.11.49: Fixed attendance_kpi_check scheduled task — was failing on
                                         // 100% of runs (confirmed live via mdl_task_log, 21/21 logged runs
                                         // failed) because it queried mdl_attendance directly with no guard for
                                         // whether mod_attendance is installed; it is NOT installed on this site.
                                         // Added the same $DB->get_manager()->table_exists('attendance') guard
                                         // already used elsewhere in this plugin (get_attendance_report.php, and
                                         // this session's own get_competencies_this_month / get_rpls_this_month)
                                         // — the task now exits cleanly with an mtrace note instead of throwing.
                                         // Pre-existing bug, unrelated to the 2.11.48 dashboard KPI changes.
                                         // No other files changed in this release.
                                         // 2.11.48: Dashboard Data Review (12 Aug 2026) fixes for Issues 1 & 2.
                                         // Added local_lmshomepage_get_competencies_this_month (approved marking
                                         // sheets only) and local_lmshomepage_get_rpls_this_month (Training Plan
                                         // RPL outcomes) to replace the old "Units Completed this Month" KPI, which
                                         // counted raw mdl_course_completions rows and so mixed genuine C
                                         // completions with CT/RPL syncs (root-caused live: 135 RPL + 11 CT + 19 C
                                         // = ~165/184 non-competency rows out of ~210 that month, vs 37 in the
                                         // Completed Units report). Added local_lmshomepage_get_enrolled_this_month
                                         // (one row per student, keyed to earliest cohort_members.timeadded) to
                                         // replace the old per-course-enrolment "Enrolled this Month" count, which
                                         // counted a student once per course. No existing functions changed or
                                         // removed — get_completed_units, get_enrolled_students, and
                                         // get_course_completion_counts are untouched and still used elsewhere.
                                         // 2.11.47: Normalised to 10-digit version format; DB-portable CONCAT across all functions.
