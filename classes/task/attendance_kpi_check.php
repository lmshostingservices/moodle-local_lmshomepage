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

namespace local_lmshomepage\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: 3-stage attendance KPI check with escalating notifications.
 *
 * Risk zones (configurable in plugin settings):
 *   Low Risk    — attendance between risk_medium_threshold and attendance_kpi_threshold
 *   Medium Risk — attendance between risk_high_threshold and risk_medium_threshold
 *   High Risk   — attendance below risk_high_threshold
 *
 * For each zone, administrators can configure:
 *   - Whether to notify the student
 *   - Whether to CC the course teacher(s)
 *   - For High Risk: additional admin/manager CC email addresses
 *   - Days between repeat notifications at this level
 *   - A fully editable email subject and body (with placeholder support)
 *
 * Placeholder tokens supported in subject and body:
 *   {firstname}         Student first name
 *   {fullname}          Student full name
 *   {course}            Course full name
 *   {activity}          Attendance activity name
 *   {percentage}        Student's current attendance percentage
 *   {threshold}         KPI threshold (%)
 *   {sessions_attended} Number of sessions the student attended
 *   {sessions_total}    Total sessions taken so far
 *   {risk_level}        Human-readable risk level (Low Risk / Medium Risk / High Risk)
 *   {organisation}      Organisation name from plugin settings
 *   {teacher_firstname} Teacher's first name (teacher body only)
 *   {teacher_fullname}  Teacher's full name (teacher body only)
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attendance_kpi_check extends \core\task\scheduled_task {
    public function get_name (): string {
        return get_string('task_attendance_kpi_check', 'local_lmshomepage');
    }

    public function execute (): void {
        global $DB;

        if (!get_config('local_lmshomepage', 'attendance_reminders_enabled')) {
            mtrace('local_lmshomepage: attendance reminders disabled — skipping.');
            return;
        }

        // ── Load global settings ────────────────────────────────────────────

        $cfg = $this->load_config();

        mtrace("local_lmshomepage: attendance KPI check starting.");
        mtrace("  KPI threshold:     {$cfg['kpi']}%");
        mtrace("  Medium risk below: {$cfg['medium_threshold']}%");
        mtrace("  High risk below:   {$cfg['high_threshold']}%");

        $attendances = $DB->get_records('attendance', [], '', 'id, name, course, grade');

        $checked = 0;
        $skipped = 0;
        foreach ($attendances as $attendance) {
            // Guard: skip attendance records whose course no longer exists.
            // get_course() and context_course::instance() both throw hard
            // exceptions for missing courses — without this guard a single
            // orphaned row kills the entire task.
            if (!$DB->record_exists('course', ['id' => $attendance->course])) {
                mtrace("  Skipping attendance id={$attendance->id} — course id={$attendance->course} not found.");
                $skipped++;
                continue;
            }
            try {
                $this->check_attendance($attendance, $cfg);
                $checked++;
            } catch (\Throwable $e) {
                // Log and continue — one broken attendance must not abort the run.
                mtrace("  ERROR checking attendance id={$attendance->id} (course {$attendance->course}): " . $e->getMessage());
                $skipped++;
            }
        }

        mtrace("local_lmshomepage: attendance KPI check complete. Checked: {$checked}, skipped/errored: {$skipped}.");
    }

    // ── Config loader ─────────────────────────────────────────────────────────

    private function load_config (): array {
        $g = function (string $key, $default) {
            $v = get_config('local_lmshomepage', $key);
            return ($v !== false && $v !== '') ? $v : $default;
        };

        $kpi    = (int) $g('attendance_kpi_threshold',  80);
        $medium = (int) $g('risk_medium_threshold',     70);
        $high   = (int) $g('risk_high_threshold',       60);

        // Sanity: ensure high < medium < kpi.
        if ($high >= $medium) $high = max(0, $medium - 10);
        if ($medium >= $kpi)  $medium = max(0, $kpi - 10);

        $org = trim((string) $g('organisation_name', 'Training Support'));
        if ($org === '') $org = 'Training Support';

        return [
            'kpi'              => $kpi,
            'medium_threshold' => $medium,
            'high_threshold'   => $high,
            'organisation'     => $org,
            'support_email'    => (string) $g('support_email', ''),

            'low' => [
                'notify_student'  => (bool)(int) $g('low_risk_notify_student',  1),
                'cc_teacher'      => (bool)(int) $g('low_risk_cc_teacher',       0),
                'admin_email'     => (string) $g('low_risk_admin_email',         ''),
                'reminder_days'   => max(0, (int) $g('low_risk_reminder_days',  14)),
                'subject'         => (string) $g('low_risk_subject',       'Attendance reminder — {course}'),
                'student_body'    => (string) $g('low_risk_student_body',  $this->default_student_body('low')),
                'teacher_body'    => (string) $g('low_risk_teacher_body',  $this->default_teacher_body('low')),
                'label'           => 'Low Risk',
            ],
            'medium' => [
                'notify_student'  => (bool)(int) $g('medium_risk_notify_student', 1),
                'cc_teacher'      => (bool)(int) $g('medium_risk_cc_teacher',      1),
                'admin_email'     => (string) $g('medium_risk_admin_email',        ''),
                'reminder_days'   => max(0, (int) $g('medium_risk_reminder_days',  7)),
                'subject'         => (string) $g('medium_risk_subject',       'Important: Your attendance requires attention — {course}'),
                'student_body'    => (string) $g('medium_risk_student_body',  $this->default_student_body('medium')),
                'teacher_body'    => (string) $g('medium_risk_teacher_body',  $this->default_teacher_body('medium')),
                'label'           => 'Medium Risk',
            ],
            'high' => [
                'notify_student'  => (bool)(int) $g('high_risk_notify_student', 1),
                'cc_teacher'      => (bool)(int) $g('high_risk_cc_teacher',      1),
                'admin_email'     => (string) $g('high_risk_admin_email',       ''),
                'reminder_days'   => max(0, (int) $g('high_risk_reminder_days',  3)),
                'subject'         => (string) $g('high_risk_subject',       'URGENT: Critical attendance level — {course}'),
                'student_body'    => (string) $g('high_risk_student_body',  $this->default_student_body('high')),
                'teacher_body'    => (string) $g('high_risk_teacher_body',  $this->default_teacher_body('high')),
                'label'           => 'High Risk',
            ],
        ];
    }

    // ── Attendance check per activity ─────────────────────────────────────────

    private function check_attendance (\stdClass $attendance, array $cfg): void {
        global $DB;

        $context = \context_course::instance($attendance->course);
        $course  = get_course($attendance->course);

        // Get activity-level KPI (completion condition), falling back to site-wide.
        $kpi_threshold = $cfg['kpi'];
        $cm = get_coursemodule_from_instance('attendance', $attendance->id, $attendance->course);
        if ($cm) {
            $cm_data = $DB->get_record('course_modules', ['id' => $cm->id], 'completionattendance');
            if ($cm_data && !empty($cm_data->completionattendance)) {
                $kpi_threshold = (int) $cm_data->completionattendance;
            }
        }

        // Students and teachers.
        $students = get_enrolled_users($context, 'mod/attendance:view',           0, 'u.id,u.firstname,u.lastname,u.email', null, 0, 0, true);
        $teachers  = get_enrolled_users($context, 'mod/attendance:takeattendances', 0, 'u.id,u.firstname,u.lastname,u.email', null, 0, 0, true);
        $from_user = \core_user::get_noreply_user();

        foreach ($students as $student) {
            $stats = $this->get_student_attendance_stats($attendance->id, $student->id);
            if ($stats === null) {
                continue;
            }

            $pct       = $stats['percentage'];
            $attended  = $stats['present'];
            $total     = $stats['taken'];

            if ($pct >= $kpi_threshold) {
                continue; // Meeting KPI — no notification needed.
            }

            // Determine risk level for this student.
            $risk_key = $this->get_risk_key($pct, $cfg, $kpi_threshold);
            $stage    = $cfg[$risk_key];

            // Has a notification been sent at this level recently?
            if ($this->notification_sent_recently($student->id, $attendance->course, $risk_key, $stage['reminder_days'])) {
                mtrace("  Skipping {$student->firstname} {$student->lastname} ({$pct}% / {$stage['label']}) — notified recently.");
                continue;
            }

            mtrace("  Notifying {$student->firstname} {$student->lastname} — {$pct}% [{$stage['label']}] (threshold: {$kpi_threshold}%)");

            // Build shared placeholder values.
            $placeholders = [
                '{firstname}'         => $student->firstname,
                '{fullname}'          => fullname($student),
                '{course}'            => $course->fullname,
                '{activity}'          => $attendance->name,
                '{percentage}'        => (string) $pct,
                '{threshold}'         => (string) $kpi_threshold,
                '{sessions_attended}' => (string) $attended,
                '{sessions_total}'    => (string) $total,
                '{risk_level}'        => $stage['label'],
                '{organisation}'      => $cfg['organisation'],
            ];

            // Base log fields shared across all recipients for this student.
            $log_base = [
                'timesent'      => time(),
                'userid'        => $student->id,
                'student_name'  => fullname($student),
                'student_email' => $student->email,
                'courseid'      => $course->id,
                'course_name'   => $course->fullname,
                'activity_name' => $attendance->name,
                'risk_level'    => $risk_key,
                'percentage'    => $pct,
                'kpi_threshold' => $kpi_threshold,
            ];

            // ── Notify student ──────────────────────────────────────────────
            if ($stage['notify_student']) {
                $subject = $this->fill($stage['subject'], $placeholders);
                $body    = $this->fill($stage['student_body'], $placeholders);
                $this->send_moodle_message($from_user, $student, $subject, $body, $course);
                $this->log_notification($log_base + [
                    'recipient_type'   => 'student',
                    'recipient_userid' => $student->id,
                    'recipient_name'   => fullname($student),
                    'subject'          => $subject,
                ]);
            }

            // ── Notify teacher(s) ───────────────────────────────────────────
            if ($stage['cc_teacher']) {
                foreach ($teachers as $teacher) {
                    $teacher_placeholders = $placeholders + [
                        '{teacher_firstname}' => $teacher->firstname,
                        '{teacher_fullname}'  => fullname($teacher),
                    ];
                    $t_subject = $this->build_teacher_subject($stage['label'], fullname($student), $course->fullname);
                    $t_body    = $this->fill($stage['teacher_body'], $teacher_placeholders);
                    $this->send_moodle_message($from_user, $teacher, $t_subject, $t_body, $course);
                    $this->log_notification($log_base + [
                        'recipient_type'   => 'teacher',
                        'recipient_userid' => $teacher->id,
                        'recipient_name'   => fullname($teacher),
                        'subject'          => $t_subject,
                    ]);
                }
            }

            // ── Admin / manager CC (high risk only via PHP mail) ─────────────
            if (!empty($stage['admin_email'])) {
                $admin_emails = array_filter(array_map('trim', explode(',', $stage['admin_email'])));
                foreach ($admin_emails as $admin_addr) {
                    if (validate_email($admin_addr)) {
                        $admin_placeholders = $placeholders + [
                            '{teacher_firstname}' => 'Manager',
                            '{teacher_fullname}'  => 'Manager',
                        ];
                        $a_subject = $this->build_teacher_subject($stage['label'], fullname($student), $course->fullname);
                        $a_body    = $this->fill($stage['teacher_body'], $admin_placeholders);
                        $this->send_external_email($admin_addr, $a_subject, $a_body, $cfg['support_email']);
                        $this->log_notification($log_base + [
                            'recipient_type'   => 'admin',
                            'recipient_userid' => 0,
                            'recipient_name'   => $admin_addr,
                            'subject'          => $a_subject,
                        ]);
                    }
                }
            }

            // Record the notification.
            $this->record_notification($student->id, $attendance->course, $risk_key);
        }
    }

    // ── Risk level determination ──────────────────────────────────────────────

    /**
     * Returns 'low', 'medium', or 'high'.
     * Note: uses the activity-specific $kpi_threshold for the top boundary.
     */
    private function get_risk_key (int $pct, array $cfg, int $kpi_threshold): string {
        if ($pct < $cfg['high_threshold']) {
            return 'high';
        }
        if ($pct < $cfg['medium_threshold']) {
            return 'medium';
        }
        return 'low'; // Between medium_threshold and kpi_threshold.
    }

    // ── Attendance stats ──────────────────────────────────────────────────────

    private function get_student_attendance_stats (int $attendanceid, int $userid): ?array {
        global $DB;

        $session_ids = $DB->get_fieldset_select('attendance_sessions', 'id', 'attendanceid = ?', [$attendanceid]);
        if (empty($session_ids)) {
            return null;
        }

        list($in_sql, $params) = $DB->get_in_or_equal($session_ids);
        $taken = $DB->count_records_select('attendance_sessions', "id {$in_sql} AND lasttaken > 0", $params);
        if ($taken === 0) {
            return null;
        }

        list($in_sql2, $params2) = $DB->get_in_or_equal($session_ids);
        $params2[] = $userid;
        $logs = $DB->get_records_select('attendance_log', "sessionid {$in_sql2} AND studentid = ?", $params2, '', 'statusid');

        if (empty($logs)) {
            return ['percentage' => 0, 'taken' => $taken, 'present' => 0];
        }

        $statuses = $DB->get_records('attendance_statuses', ['attendanceid' => $attendanceid], '', 'id, grade');
        $present_statuses = [];
        foreach ($statuses as $s) {
            if ((float) $s->grade > 0) {
                $present_statuses[$s->id] = true;
            }
        }

        $present = 0;
        foreach ($logs as $log) {
            if (isset($present_statuses[$log->statusid])) {
                $present++;
            }
        }

        return ['percentage' => (int) round(($present / $taken) * 100), 'taken' => $taken, 'present' => $present];
    }

    // ── Notification throttle ─────────────────────────────────────────────────

    private function notification_sent_recently (int $userid, int $courseid, string $risk_level, int $days): bool {
        global $DB;
        if ($days <= 0) {
            return false; // No throttle — always send.
        }
        $since = time() - ($days * DAYSECS);
        return $DB->record_exists_select(
            'local_lmshomepage_reminders',
            'userid = ? AND courseid = ? AND risk_level = ? AND timesent > ?',
            [$userid, $courseid, $risk_level, $since]
        );
    }

    private function log_notification (array $data): void {
        global $DB;
        try {
            $DB->insert_record('local_lmshomepage_log', (object) $data);
        } catch (\Exception $e) {
            // Non-fatal — log to trace but don't abort the task.
            mtrace("  Warning: could not write to notification log: " . $e->getMessage());
        }
    }

    private function record_notification (int $userid, int $courseid, string $risk_level): void {
        global $DB;
        // Remove any stale record for this risk level, then insert fresh.
        $DB->delete_records('local_lmshomepage_reminders', ['userid' => $userid, 'courseid' => $courseid, 'risk_level' => $risk_level]);
        $DB->insert_record('local_lmshomepage_reminders', [
            'userid'     => $userid,
            'courseid'   => $courseid,
            'risk_level' => $risk_level,
            'timesent'   => time(),
        ]);
    }

    // ── Message sending ───────────────────────────────────────────────────────

    private function send_moodle_message (
        \stdClass $from,
        \stdClass $to,
        string $subject,
        string $body,
        \stdClass $course
    ): void {
        $msg                    = new \core\message\message();
        $msg->component         = 'local_lmshomepage';
        $msg->name              = 'attendance_reminder';
        $msg->userfrom          = $from;
        $msg->userto            = $to;
        $msg->subject           = $subject;
        $msg->fullmessage       = $body;
        $msg->fullmessageformat = FORMAT_PLAIN;
        $msg->fullmessagehtml   = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $msg->smallmessage      = $subject;
        $msg->notification      = 1;
        $msg->contexturl        = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $msg->contexturlname    = $course->fullname;
        try {
            message_send($msg);
        } catch (\Throwable $e) {
            // message_send() can throw if the message provider is not yet
            // registered or if the messaging subsystem is misconfigured.
            // Log and continue — this must not propagate back to the task loop.
            mtrace("  Warning: message_send() failed for user id={$to->id}: " . $e->getMessage());
        }
    }

    /**
     * Send a plain-text email to an external address (e.g. admin CC).
     * Uses Moodle's email_to_user() with a fabricated user object.
     */
    private function send_external_email (string $to_email, string $subject, string $body, string $reply_to = ''): void {
        // Clone the cached support-user object so we don't corrupt Moodle's internal
        // reference when overriding the email address for this specific recipient.
        $fake              = clone \core_user::get_support_user();
        $fake->email       = $to_email;
        $fake->firstname   = '';
        $fake->lastname    = 'Admin';
        $fake->mailformat  = 0; // Plain text.
        $fake->maildisplay = 0;

        $from = \core_user::get_noreply_user();
        if ($reply_to && validate_email($reply_to)) {
            $from->email = $reply_to;
        }

        email_to_user($fake, $from, $subject, $body, '', '', '', true);
    }

    // ── Placeholder substitution ──────────────────────────────────────────────

    private function fill (string $template, array $placeholders): string {
        return str_replace(array_keys($placeholders), array_values($placeholders), $template);
    }

    private function build_teacher_subject (string $risk_label, string $student_name, string $course_name): string {
        return "[{$risk_label}] Attendance alert — {$student_name} in {$course_name}";
    }

    // ── Fallback default bodies (used if DB config is blank) ──────────────────

    private function default_student_body (string $level): string {
        $bodies = [
            'low'    => "Hi {firstname},\n\nYour attendance in {course} is {percentage}%, which is below the required {threshold}%.\n\nYou have attended {sessions_attended} of {sessions_total} sessions. Please attend upcoming sessions to stay on track.\n\nKind regards,\n{organisation}",
            'medium' => "Hi {firstname},\n\nIMPORTANT: Your attendance in {course} has dropped to {percentage}%, significantly below the required {threshold}%.\n\nYou have attended {sessions_attended} of {sessions_total} sessions. Please contact your trainer immediately and attend all remaining sessions.\n\nKind regards,\n{organisation}",
            'high'   => "Hi {firstname},\n\nURGENT: Your attendance in {course} is critically low at {percentage}% (required: {threshold}%). You have attended only {sessions_attended} of {sessions_total} sessions.\n\nImmediate action is required. Please contact your trainer today.\n\nKind regards,\n{organisation}",
        ];
        return $bodies[$level] ?? $bodies['low'];
    }

    private function default_teacher_body (string $level): string {
        $labels = ['low' => 'Low Risk', 'medium' => 'Medium Risk', 'high' => 'HIGH RISK'];
        $label  = $labels[$level] ?? 'At Risk';
        return "Hi {teacher_firstname},\n\n[{$label}] {fullname} has an attendance rate of {percentage}% in {course} (required: {threshold}%). Sessions attended: {sessions_attended} of {sessions_total}.\n\nThis notification was sent automatically by the LMS attendance monitoring system.";
    }
}
