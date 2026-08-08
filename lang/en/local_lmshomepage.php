<?php
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

$string['high_risk_subject_default'] = 'URGENT: Critical attendance level — {course}';

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

URGENT: {fullname} has reached a critical attendance level of {percentage}% in {course} (required: {threshold}%).

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

$string['privacy:metadata'] = 'The LMS Home Page plugin stores a record of when attendance reminder notifications were sent (in the local_lmshomepage_reminders table), containing only user ID, course ID, risk level, and the timestamp the notification was sent. No message content is stored. The current user\'s display name is passed to the dashboard widget for a personalised greeting but is not persisted externally.';

// ── Message provider ─────────────────────────────────────────────────────────

$string['messageprovider:attendance_reminder'] = 'Attendance KPI escalation notifications';
