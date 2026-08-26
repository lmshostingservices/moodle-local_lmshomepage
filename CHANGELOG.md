# Changelog — LMS Home Page

All notable changes to this plugin are documented here.

Entries before 2.11.66 are reconstructed from the release history that had been kept as comments
in `version.php`, which is where this plugin recorded its changes until now. The wording is
theirs; only the formatting is new. `version.php` remains the source of truth for the version
numbers themselves.

## [2.11.66] - 2026-08-26

### Fixed - the privacy declaration was false

- **`classes/privacy/provider.php` implemented `null_provider`.** That is a formal assertion to
  Moodle and to every site administrator that this plugin stores **no personal data at all**. The
  plugin creates three tables and all three hold personal data:

  | Table | Personal data |
  |---|---|
  | `local_lmshomepage_reminders` | `userid`, `courseid`, risk level |
  | `local_lmshomepage_log` | `userid`, student name, student email, recipient user id, recipient name, notification subject, risk level, percentage |
  | `local_lmshomepage_commhub` | student id, student name, student email, trainer id, trainer name, message subject and **message body** |

  The `privacy:metadata` string went further and stated "No message content is stored", which
  `local_lmshomepage_commhub.message_body` contradicts directly.

  The effect was not only that the Moodle Plugins Directory would reject it. On every site running
  the plugin, a subject access request returned nothing from it and an erasure request deleted
  nothing, while the data was really there.

- Replaced with a full implementation: `metadata\provider`, `request\plugin\provider` and
  `request\core_userlist_provider`, declaring all three tables field by field and implementing
  `get_contexts_for_userid`, `get_users_in_context`, `export_user_data`, `delete_data_for_user`,
  `delete_data_for_all_users_in_context` and `delete_data_for_users`.

  A single row can name two people — a notification names the learner it is about *and* the person
  it was sent to; a Communication Hub message names the student *and* the trainer. Finding and
  exporting search both roles, and the export separates "about you" from "sent to you" so the
  subject can tell which is which.

  **Erasure does not treat the two alike.** The row exists to record something about the first
  person, so when they leave it goes with them; when the second person leaves, only their own
  fields are cleared (`recipient_userid`/`recipient_name`, `trainerid`/`trainer_name`). Deleting
  on either match would mean one user's erasure request destroying another user's record — a
  trainer leaving would take the learner's notification history with them.

- The single `privacy:metadata` language string is replaced by 39 per-table and per-field strings
  that describe what is actually stored.

## [2.11.65] - 2026-08-26

### Fixed

- Keep the Wombat Moodle login card within the initial viewport.

## [2.11.64]

### Changed

- Read plugin version metadata directly from `version.php`.

## [2.11.63]

### Added

- Expose usernames in AIOP grade and attendance reports.

## [2.11.62]

### Added

- Expose derived grade-report submission status.

## [2.11.61]

### Fixed

- Collation fix for historical RPL report outcomes.

## [2.11.58]

### Changed

- `get_assessment_submissions` performance rewrite. Replaces whole-table Training Plan aggregates
  with student/course-correlated lookups, resolves trainer names once per request, and resolves
  trainer pseudo-group labels once before group deduplication. Output contract, exclusions, grade
  `MAX(id)` deduplication, table guards and PHP post-processing are all preserved. No schema
  changes.

## [2.11.57]

### Added

- Historical Training Plan outcomes in monthly reports. Outcomes resolve to the latest validated
  transition at the end of the reporting month, with an explicit current fallback when the
  transition log has no earlier entry.

## [2.11.56]

### Added

- Configurable reporting exclusions. Excluded Moodle usernames and course full names are
  newline-separated, case-insensitive whole-value patterns with `*` wildcard support. Applied in
  the assessment SQL query shared by the Trainer Marking Report and the dashboard KPIs.

## [2.11.55]

### Fixed

- **Hotfix on top of 2.11.54.** 2.11.54 shipped a fatal PHP parse error: an unescaped double
  quote inside a double-quoted SQL string in `get_trainer_allocations.php` closed the string
  early, making the file unparseable. That killed the function *and* broke
  `admin/webservice/service_functions.php`, since that page classloads every external function.
- Escaped a `${...}` sequence in a SQL comment in `get_assessment_submissions.php` that
  interpolated two undefined variables and emitted deprecation notices into the web service
  output buffer.

## [2.11.54]

### Fixed

- **RPT-16 — Group column showed the trainer name instead of the group.** In
  `get_assessment_submissions` and `get_trainer_allocations`, the group dedup subquery selected
  `MIN(group_id/name)` with no filter on group type. Trainer-allocation pseudo-groups are real
  Moodle groups and were selected when a student had no real cohort group. Added `NOT EXISTS` to
  both group subqueries to exclude groups whose name matches any trainername profile field value.
  Group now returns blank when no real group exists.
- **RPT-17 — Staff accounts appearing as students in the Marking Report.** Staff test accounts
  held active student role enrolments, so the SQL student-role filter passed. Fixed in PHP
  post-processing: rows with no cohort *and* no training plan are skipped. Guarded so it only
  applies when the Training Plan tables exist, leaving genuine uncohorted students on sites
  without that block unaffected.

No schema changes.

## [2.11.52]

### Fixed

- **RPT-3** — `get_completed_units` uses `local_finalmarkingsheet.deemedcompetentdate`
  (`status='approved'`) as the primary completion source when the table is present, falling back
  to `course_completions.timecompleted`.
- **RPT-9** — `get_assessment_submissions` adds a `has_training_plan` field (1 when the student
  has a Training Plan due date, 0 when falling back to the native `assign.duedate`), so the
  client's "Training Plan only" filter can hide students with no plan.
- **RPT-15** — `get_trainer_allocations` group query filters to visible courses
  (`JOIN {course} gc ON gc.visible = 1`) to match enrolment scope.
- **RPT-8 / RPT-11** — dashboard assessment-status KPI `days_lookback` changed from 180 to 365 to
  match the `getWombatReportMarking` window, aligning the overdue-submissions and grading-backlog
  KPI counts with the Trainer Marking Report CSV totals.
- **RPT-13** — the matrix "View marking report" link uses a hard `window.location.href`
  navigation instead of a wouter `<Link>`, so URL parameters are re-read on mount.
- **RPT-14** — matrix CSV switched from transposed (unit rows, student columns) to long format,
  one row per student × unit, with `items_complete` / `items_total`.

## [2.11.51]

### Fixed

- `get_trainer_allocations` now returns a real `group_name`. Added a `GROUP BY` subquery on
  `{groups_members}` and `{groups}` to resolve the alphabetically-first Moodle group name per
  student. The field had been hardcoded to an empty string for API compatibility since 2.11.25,
  leaving the Group column blank in the Trainer Allocation Report. No schema changes; no other
  functions changed.

## [2.11.50]

### Fixed

- `get_assessment_submissions` — the `assign_grades` LEFT JOIN had no dedup constraint, so a
  student graded more than once on the same assignment (regrade or resubmission) produced one
  duplicate row per extra grade record. Found in client testing, where a single student/unit/task
  appeared five or more times in one CSV export.

  The same duplication was inflating the dashboard's Overdue Submissions KPI and breaking Trainer
  Marking Report pagination client-side — duplicate rows produced duplicate React keys, which
  froze the page-forward control after page 5.

  The JOIN is now constrained to the single latest qualifying grade record per
  (assignment, student) via a `MAX(id)` subquery. No other function changed; no schema changes.

## [2.11.49]

### Fixed

- The `attendance_kpi_check` scheduled task was failing on 100% of runs — confirmed live in
  `mdl_task_log`, 21 of 21 logged runs failed — because it queried `mdl_attendance` directly with
  no guard for whether `mod_attendance` is installed, and it is not installed on this site. Added
  the same `$DB->get_manager()->table_exists('attendance')` guard already used elsewhere in this
  plugin, so the task now exits cleanly with an mtrace note instead of throwing.

  Pre-existing bug, unrelated to the 2.11.48 dashboard KPI changes. No other files changed.

## [2.11.48]

### Added

- `local_lmshomepage_get_competencies_this_month` (approved marking sheets only) and
  `local_lmshomepage_get_rpls_this_month` (Training Plan RPL outcomes), replacing the old "Units
  Completed this Month" KPI. That KPI counted raw `mdl_course_completions` rows and so mixed
  genuine competency completions with CT and RPL syncs — root-caused live at roughly 165 of 210
  non-competency rows in one month, against 37 in the Completed Units report.
- `local_lmshomepage_get_enrolled_this_month`, one row per student keyed to the earliest
  `cohort_members.timeadded`, replacing the old per-course-enrolment "Enrolled this Month" count
  which counted a student once per course.

No existing functions were changed or removed: `get_completed_units`, `get_enrolled_students` and
`get_course_completion_counts` are untouched and still used elsewhere.

## [2.11.47]

### Changed

- Normalised to the 10-digit version format; DB-portable `CONCAT` across all functions.
