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
 * Part of the local_lmshomepage plugin.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// ── Core plugin strings ──────────────────────────────────────────────────────

$string['pluginname']      = 'LMS Home Page';
$string['pluginname_desc'] = 'Injects a fully custom LMS home page dashboard into the Moodle front page. Renders a full-width hero, live KPI gauges, My Learning progress, Level Up XP leaderboard, course catalogue and learner cohorts — all sourced from the dashboard application API.';

// ── Dashboard settings ───────────────────────────────────────────────────────

$string['heading_dashboard'] = 'Dashboard settings';
$string['apiurl']            = 'Dashboard application URL';
$string['apiurl_desc']       = 'Base URL of the external dashboard application (e.g. https://your-app.replit.app). No trailing slash.';
$string['siteid']            = 'Site ID';
$string['siteid_desc']       = 'Unique identifier for this Moodle installation — routes API requests to the correct dataset (e.g. aiop, tdt, signature, yilabara, ems, itlc). Alphanumeric and hyphens only.';
$string['apitoken']          = 'API token (optional)';
$string['apitoken_desc']     = 'Optional bearer token passed as Authorization header when calling the dashboard API.';
$string['enabled']           = 'Enable dashboard';
$string['enabled_desc']      = 'Show the custom LMS Home Page on the Moodle front page. When enabled the standard Moodle front page content is replaced by the dashboard widget.';

$string['heading_reporting_exclusions']      = 'Reporting exclusions';
$string['heading_reporting_exclusions_desc'] = 'These exclusions are applied in the database query used by both the Trainer Marking Report and the Overdue Submissions / Grading Backlog KPIs. Enter one pattern per line. Matching is case-insensitive, applies to the whole value, and supports * as a wildcard (for example, test* or *staff*). Leave either field blank when it is not needed.';
$string['excluded_user_patterns']            = 'Excluded username patterns';
$string['excluded_user_patterns_desc']       = 'One Moodle username pattern per line. A pattern without * matches the whole username exactly. * matches any number of characters. Matching accounts are removed before report rows and KPI totals are calculated.';
$string['excluded_course_patterns']          = 'Excluded course-name patterns';
$string['excluded_course_patterns_desc']     = 'One Moodle course full-name pattern per line. A pattern without * matches the whole course name exactly. * matches any number of characters. Matching courses are removed before report rows and KPI totals are calculated.';

// ── Attendance KPI global settings ──────────────────────────────────────────

$string['heading_attendance']      = 'Attendance KPI monitoring';
$string['heading_attendance_desc'] = 'These settings control the automated attendance reminder system. When enabled, a scheduled task runs daily and sends notifications to students whose attendance falls below the KPI threshold. Three escalation stages (Low / Medium / High Risk) allow different messages and schedules depending on how far below the threshold the student has fallen.';

$string['attendance_reminders_enabled']      = 'Enable attendance reminders';
$string['attendance_reminders_enabled_desc'] = 'When ticked, the attendance_kpi_check scheduled task will run daily and send escalating notifications to students below the KPI threshold. Untick to pause all automated attendance notifications.';

$string['attendance_kpi_threshold']      = 'KPI attendance threshold (%)';
$string['attendance_kpi_threshold_desc'] = 'The minimum attendance percentage students must achieve. Students below this value are placed in one of the three risk zones below. If an individual attendance activity has a completion condition set (e.g. "must achieve at least 85% attendance"), that value overrides this site-wide setting for that activity.';

$string['organisation_name']      = 'Organisation name';
$string['organisation_name_desc'] = 'Used as the sign-off name in all notification emails (replaces the {organisation} placeholder). E.g. "AIOP Training Support".';

$string['support_email']      = 'Support reply-to email';
$string['support_email_desc'] = 'Optional email address shown in notifications so students know where to respond. Leave blank to use Moodle\'s default no-reply address.';

// ── Escalation thresholds ────────────────────────────────────────────────────

$string['heading_escalation']      = 'Escalation risk zones';
$string['heading_escalation_desc'] = 'Define the boundaries between the three risk zones. Students below the KPI threshold are assigned a risk level based on their attendance percentage:
<ul>
<li><strong>Low Risk</strong>: between the Medium Risk threshold and the KPI threshold (e.g. 70–79% with defaults).</li>
<li><strong>Medium Risk</strong>: between the High Risk threshold and the Medium Risk threshold (e.g. 60–69% with defaults).</li>
<li><strong>High Risk</strong>: below the High Risk threshold (e.g. below 60% with defaults).</li>
</ul>
Each zone has its own notification settings, email templates, and send schedule below.';

$string['risk_medium_threshold']      = 'Medium risk starts below (%)';
$string['risk_medium_threshold_desc'] = 'Students with attendance below this percentage are classed as Medium Risk (or High Risk if also below the High Risk threshold). Students between this value and the KPI threshold are Low Risk. Default: 70.';

$string['risk_high_threshold']      = 'High risk starts below (%)';
$string['risk_high_threshold_desc'] = 'Students with attendance below this percentage are classed as High Risk. Default: 60.';

// ── Shared per-stage field labels ─────────────────────────────────────────────

$string['notify_student']      = 'Notify student';
$string['notify_student_desc'] = 'When ticked, the student will receive a Moodle message (which also triggers an email if they have email notifications enabled in their profile settings).';

$string['cc_teacher']      = 'CC teacher';
$string['cc_teacher_desc'] = 'When ticked, the course teacher(s) will receive a separate notification message for each student at this risk level.';

$string['admin_email']      = 'Admin / manager CC email(s)';
$string['admin_email_desc'] = 'Optional. One or more email addresses (comma-separated) to receive a plain-text CC copy of notifications at this risk level. Leave blank to skip admin CC for this tier. Emails are sent outside Moodle\'s messaging system using PHP mail — ensure your server mail is configured.';

$string['reminder_days']      = 'Days between repeat notifications';
$string['reminder_days_desc'] = 'Minimum number of days before the same student will receive another notification at this risk level. Set to 0 to notify every day the task runs. A student who escalates to a higher risk level will always receive the higher-level notification regardless of this setting.';

$string['email_subject']      = 'Email subject';
$string['email_subject_desc'] = 'Subject line for student notification emails. Supports placeholders: {course}, {percentage}, {threshold}, {risk_level}, {organisation}.';

$string['student_email_body'] = 'Student notification body';
$string['email_body_desc']    = 'Body text sent to the student. Supports placeholders: {firstname}, {fullname}, {course}, {activity}, {percentage}, {threshold}, {sessions_attended}, {sessions_total}, {risk_level}, {organisation}.';

$string['teacher_email_body']      = 'Teacher notification body';
$string['teacher_email_body_desc'] = 'Body text sent to the teacher. Supports all student placeholders plus: {teacher_firstname}, {teacher_fullname}.';

// ── LOW RISK stage ───────────────────────────────────────────────────────────

$string['heading_low_risk']      = '🟡  Stage 1 — Low Risk';
$string['heading_low_risk_desc'] = 'Sent when a student\'s attendance is below the KPI threshold but above the Medium Risk threshold (e.g. 70–79% with default settings). This is a gentle, supportive reminder encouraging the student to attend upcoming sessions.';

$string['low_risk_subject_default'] = 'Attendance reminder — {course}';

$string['low_risk_student_body_default'] = 'Hi {firstname},

We wanted to let you know that your current attendance in {course} is {percentage}%, which is slightly below the required minimum of {threshold}%.

You have attended {sessions_attended} of {sessions_total} scheduled sessions to date.

To stay on track with your qualification, please ensure you attend all upcoming sessions. Maintaining strong attendance supports your learning and is required for course completion.

If you have any questions or need support, please don\'t hesitate to contact your trainer.

Kind regards,
{organisation}';

$string['low_risk_teacher_body_default'] = 'Hi {teacher_firstname},

This is an automated attendance notice to advise that {fullname} has an attendance rate of {percentage}% in {course}, which is below the required KPI of {threshold}% (Low Risk).

Sessions attended: {sessions_attended} of {sessions_total}

A gentle reminder has been sent to the student. No immediate action is required at this stage, but you may wish to check in with them if you haven\'t already.

This notification was sent automatically by the LMS attendance monitoring system.';

// ── MEDIUM RISK stage ────────────────────────────────────────────────────────

$string['heading_medium_risk']      = '🟠  Stage 2 — Medium Risk';
$string['heading_medium_risk_desc'] = 'Sent when a student\'s attendance is below the Medium Risk threshold but above the High Risk threshold (e.g. 60–69% with default settings). A more urgent message encouraging prompt action and contact with the trainer.';

$string['medium_risk_subject_default'] = 'Important: Your attendance requires attention — {course}';

$string['medium_risk_student_body_default'] = 'Hi {firstname},

This is an important notice regarding your attendance in {course}.

Your current attendance has dropped to {percentage}%, which is significantly below the required minimum of {threshold}%. You have attended {sessions_attended} of {sessions_total} scheduled sessions.

At this level, your progress and qualification completion are at risk. Please take the following steps:

  • Attend all remaining scheduled sessions without exception
  • Contact your trainer as soon as possible to discuss your attendance
  • Let us know if you are experiencing any difficulties so we can explore support options

Bringing your attendance back above {threshold}% is essential to remain on track with your qualification.

Kind regards,
{organisation}';

$string['medium_risk_teacher_body_default'] = 'Hi {teacher_firstname},

This is an automated attendance alert to advise that {fullname} has reached a Medium Risk attendance level of {percentage}% in {course} (required: {threshold}%).

Sessions attended: {sessions_attended} of {sessions_total}

A reminder has been sent directly to the student. We recommend following up with them to understand any barriers to attendance and explore available support options.

This notification was sent automatically by the LMS attendance monitoring system.';

// ── HIGH RISK stage ──────────────────────────────────────────────────────────

$string['heading_high_risk']      = '🔴  Stage 3 — High Risk';
$string['heading_high_risk_desc'] = 'Sent when a student\'s attendance falls below the High Risk threshold (e.g. below 60% with default settings). An urgent escalation message requiring immediate action. Teachers and optionally admin/management are notified.';

$string['high_risk_subject_default'] = 'Urgent: Critical attendance level — {course}';

$string['high_risk_student_body_default'] = 'Hi {firstname},

We are writing to urgently advise that your attendance in {course} has reached a critical level of {percentage}% — well below the required minimum of {threshold}%.

You have attended only {sessions_attended} of {sessions_total} scheduled sessions.

IMMEDIATE ACTION IS REQUIRED:

  • Contact your trainer or student support team TODAY — do not delay
  • If you have extenuating circumstances affecting your attendance, please inform us immediately so we can explore options to support you
  • A formal review of your enrolment status may be required if attendance does not improve

Failure to address your attendance urgently may result in formal action regarding your enrolment. We strongly encourage you to reach out for support as soon as possible — we are here to help.

Kind regards,
{organisation}';

$string['high_risk_teacher_body_default'] = 'Hi {teacher_firstname},

Urgent: {fullname} has reached a critical attendance level of {percentage}% in {course} (required: {threshold}%).

Sessions attended: {sessions_attended} of {sessions_total}

This student is at HIGH RISK. An urgent reminder has been sent directly to the student. Immediate follow-up is strongly recommended, which may include:

  • Direct contact with the student to understand their situation
  • Escalation to student support or management
  • Assessment of whether a formal enrolment review is required

Please treat this matter with urgency.

This notification was sent automatically by the LMS attendance monitoring system.';

// ── Admin notification log report ────────────────────────────────────────────

$string['report_name']          = 'Attendance Notification Log';
$string['report_name_nav']      = 'Attendance Notification Log';
$string['report_table_missing'] = 'The notification log table does not exist yet. Please upgrade the LMS Home Page plugin (Site Administration → Notifications) to create the required database tables.';

// ── Scheduled task ───────────────────────────────────────────────────────────

$string['task_attendance_kpi_check'] = 'Attendance KPI check and escalation notifications';

// ── Privacy ──────────────────────────────────────────────────────────────────

// FIX-LMSHP-PRIVACY (v2.11.66): the single privacy:metadata string that used to sit here
// described only the reminders table and stated "No message content is stored", while
// local_lmshomepage_commhub stores message_subject and message_body. It backed a null_provider
// declaration that asserted the plugin held no personal data at all. Replaced with per-table and
// per-field strings that describe what is actually stored.
$string['privacy:metadata:reminders'] = 'A record of attendance reminder notifications sent about a learner, so the same reminder is not sent twice.';
$string['privacy:metadata:reminders:userid'] = 'The ID of the learner the reminder was about.';
$string['privacy:metadata:reminders:courseid'] = 'The ID of the course the reminder related to.';
$string['privacy:metadata:reminders:risk_level'] = 'The risk level that triggered the reminder.';
$string['privacy:metadata:reminders:timesent'] = 'The time the reminder was sent.';

$string['privacy:metadata:log'] = 'A log of KPI and attendance notifications, recording both the learner the notification was about and the person it was sent to.';
$string['privacy:metadata:log:userid'] = 'The ID of the learner the notification was about.';
$string['privacy:metadata:log:student_name'] = 'The name of the learner the notification was about.';
$string['privacy:metadata:log:student_email'] = 'The email address of the learner the notification was about.';
$string['privacy:metadata:log:courseid'] = 'The ID of the course the notification related to.';
$string['privacy:metadata:log:course_name'] = 'The name of the course the notification related to.';
$string['privacy:metadata:log:activity_name'] = 'The name of the activity the notification related to.';
$string['privacy:metadata:log:risk_level'] = 'The risk level recorded for the learner.';
$string['privacy:metadata:log:percentage'] = 'The completion or attendance percentage recorded for the learner.';
$string['privacy:metadata:log:kpi_threshold'] = 'The KPI threshold the learner was measured against.';
$string['privacy:metadata:log:recipient_type'] = 'Whether the notification went to the learner, a trainer or an administrator.';
$string['privacy:metadata:log:recipient_userid'] = 'The ID of the person the notification was sent to.';
$string['privacy:metadata:log:recipient_name'] = 'The name of the person the notification was sent to.';
$string['privacy:metadata:log:subject'] = 'The subject line of the notification.';
$string['privacy:metadata:log:timesent'] = 'The time the notification was sent.';

$string['privacy:metadata:commhub'] = 'A record of messages sent through the Communication Hub, including the message content.';
$string['privacy:metadata:commhub:studentid'] = 'The ID of the learner the message was about or addressed to.';
$string['privacy:metadata:commhub:student_name'] = 'The name of the learner.';
$string['privacy:metadata:commhub:student_email'] = 'The email address of the learner.';
$string['privacy:metadata:commhub:trainerid'] = 'The ID of the trainer involved in the message.';
$string['privacy:metadata:commhub:trainer_name'] = 'The name of the trainer involved in the message.';
$string['privacy:metadata:commhub:courseid'] = 'The ID of the course the message related to.';
$string['privacy:metadata:commhub:course_name'] = 'The name of the course the message related to.';
$string['privacy:metadata:commhub:assessment_name'] = 'The name of the assessment the message related to.';
$string['privacy:metadata:commhub:channel'] = 'The channel the message was sent through.';
$string['privacy:metadata:commhub:message_subject'] = 'The subject line of the message.';
$string['privacy:metadata:commhub:message_body'] = 'The full text of the message.';
$string['privacy:metadata:commhub:status'] = 'Whether the message was sent successfully.';
$string['privacy:metadata:commhub:timesent'] = 'The time the message was sent.';

$string['privacy:path:reminders'] = 'Attendance reminders';
$string['privacy:path:logabout'] = 'Notifications about you';
$string['privacy:path:logreceived'] = 'Notifications sent to you';
$string['privacy:path:commhubstudent'] = 'Communication Hub messages as a learner';
$string['privacy:path:commhubtrainer'] = 'Communication Hub messages as a trainer';

// ── Message provider ─────────────────────────────────────────────────────────

$string['messageprovider:attendance_reminder'] = 'Attendance KPI escalation notifications';
