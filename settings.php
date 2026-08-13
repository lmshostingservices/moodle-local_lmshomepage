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

// ── Register the notification log report under Site Admin → Reports ──────────
// This is done outside $hassiteconfig so it's always registered,
// but admin_externalpage applies its own capability check.
$ADMIN->add('reports', new admin_externalpage(
    'local_lmshomepage_report',
    get_string('report_name', 'local_lmshomepage'),
    new moodle_url('/local/lmshomepage/report.php'),
    'moodle/site:config'
));

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_lmshomepage',
        get_string('pluginname', 'local_lmshomepage')
    );

    $ADMIN->add('localplugins', $settings);

    // ════════════════════════════════════════════════════════════════════════
    // SECTION 1 — Dashboard settings
    // ════════════════════════════════════════════════════════════════════════

    $settings->add(new admin_setting_heading(
        'local_lmshomepage/heading_dashboard',
        get_string('heading_dashboard', 'local_lmshomepage'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/enabled',
        get_string('enabled', 'local_lmshomepage'),
        get_string('enabled_desc', 'local_lmshomepage'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/apiurl',
        get_string('apiurl', 'local_lmshomepage'),
        get_string('apiurl_desc', 'local_lmshomepage'),
        '',
        PARAM_URL,
        60
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/siteid',
        get_string('siteid', 'local_lmshomepage'),
        get_string('siteid_desc', 'local_lmshomepage'),
        '',
        PARAM_ALPHANUMEXT,
        30
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_lmshomepage/apitoken',
        get_string('apitoken', 'local_lmshomepage'),
        get_string('apitoken_desc', 'local_lmshomepage'),
        ''
    ));

    // ════════════════════════════════════════════════════════════════════════
    // SECTION 2 — Attendance KPI monitoring (global settings)
    // ════════════════════════════════════════════════════════════════════════

    $settings->add(new admin_setting_heading(
        'local_lmshomepage/heading_attendance',
        get_string('heading_attendance', 'local_lmshomepage'),
        get_string('heading_attendance_desc', 'local_lmshomepage')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/attendance_reminders_enabled',
        get_string('attendance_reminders_enabled', 'local_lmshomepage'),
        get_string('attendance_reminders_enabled_desc', 'local_lmshomepage'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/attendance_kpi_threshold',
        get_string('attendance_kpi_threshold', 'local_lmshomepage'),
        get_string('attendance_kpi_threshold_desc', 'local_lmshomepage'),
        '80',
        PARAM_INT,
        6
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/organisation_name',
        get_string('organisation_name', 'local_lmshomepage'),
        get_string('organisation_name_desc', 'local_lmshomepage'),
        '',
        PARAM_TEXT,
        60
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/support_email',
        get_string('support_email', 'local_lmshomepage'),
        get_string('support_email_desc', 'local_lmshomepage'),
        '',
        PARAM_EMAIL,
        60
    ));

    // ════════════════════════════════════════════════════════════════════════
    // SECTION 3 — Escalation risk zones (thresholds)
    // ════════════════════════════════════════════════════════════════════════

    $settings->add(new admin_setting_heading(
        'local_lmshomepage/heading_escalation',
        get_string('heading_escalation', 'local_lmshomepage'),
        get_string('heading_escalation_desc', 'local_lmshomepage')
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/risk_medium_threshold',
        get_string('risk_medium_threshold', 'local_lmshomepage'),
        get_string('risk_medium_threshold_desc', 'local_lmshomepage'),
        '70',
        PARAM_INT,
        6
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/risk_high_threshold',
        get_string('risk_high_threshold', 'local_lmshomepage'),
        get_string('risk_high_threshold_desc', 'local_lmshomepage'),
        '60',
        PARAM_INT,
        6
    ));

    // ════════════════════════════════════════════════════════════════════════
    // SECTION 4 — LOW RISK notification settings
    //   Zone: between risk_medium_threshold and attendance_kpi_threshold
    //   Example with defaults: 70 – 79%
    // ════════════════════════════════════════════════════════════════════════

    $settings->add(new admin_setting_heading(
        'local_lmshomepage/heading_low_risk',
        get_string('heading_low_risk', 'local_lmshomepage'),
        get_string('heading_low_risk_desc', 'local_lmshomepage')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/low_risk_notify_student',
        get_string('notify_student', 'local_lmshomepage'),
        get_string('notify_student_desc', 'local_lmshomepage'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/low_risk_cc_teacher',
        get_string('cc_teacher', 'local_lmshomepage'),
        get_string('cc_teacher_desc', 'local_lmshomepage'),
        0
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/low_risk_admin_email',
        get_string('admin_email', 'local_lmshomepage'),
        get_string('admin_email_desc', 'local_lmshomepage'),
        '',
        PARAM_TEXT,
        80
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/low_risk_reminder_days',
        get_string('reminder_days', 'local_lmshomepage'),
        get_string('reminder_days_desc', 'local_lmshomepage'),
        '14',
        PARAM_INT,
        6
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/low_risk_subject',
        get_string('email_subject', 'local_lmshomepage'),
        get_string('email_subject_desc', 'local_lmshomepage'),
        get_string('low_risk_subject_default', 'local_lmshomepage'),
        PARAM_TEXT,
        80
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_lmshomepage/low_risk_student_body',
        get_string('student_email_body', 'local_lmshomepage'),
        get_string('email_body_desc', 'local_lmshomepage'),
        get_string('low_risk_student_body_default', 'local_lmshomepage'),
        PARAM_RAW,
        80,
        14
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_lmshomepage/low_risk_teacher_body',
        get_string('teacher_email_body', 'local_lmshomepage'),
        get_string('teacher_email_body_desc', 'local_lmshomepage'),
        get_string('low_risk_teacher_body_default', 'local_lmshomepage'),
        PARAM_RAW,
        80,
        10
    ));

    // ════════════════════════════════════════════════════════════════════════
    // SECTION 5 — MEDIUM RISK notification settings
    //   Zone: between risk_high_threshold and risk_medium_threshold
    //   Example with defaults: 60 – 69%
    // ════════════════════════════════════════════════════════════════════════

    $settings->add(new admin_setting_heading(
        'local_lmshomepage/heading_medium_risk',
        get_string('heading_medium_risk', 'local_lmshomepage'),
        get_string('heading_medium_risk_desc', 'local_lmshomepage')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/medium_risk_notify_student',
        get_string('notify_student', 'local_lmshomepage'),
        get_string('notify_student_desc', 'local_lmshomepage'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/medium_risk_cc_teacher',
        get_string('cc_teacher', 'local_lmshomepage'),
        get_string('cc_teacher_desc', 'local_lmshomepage'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/medium_risk_admin_email',
        get_string('admin_email', 'local_lmshomepage'),
        get_string('admin_email_desc', 'local_lmshomepage'),
        '',
        PARAM_TEXT,
        80
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/medium_risk_reminder_days',
        get_string('reminder_days', 'local_lmshomepage'),
        get_string('reminder_days_desc', 'local_lmshomepage'),
        '7',
        PARAM_INT,
        6
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/medium_risk_subject',
        get_string('email_subject', 'local_lmshomepage'),
        get_string('email_subject_desc', 'local_lmshomepage'),
        get_string('medium_risk_subject_default', 'local_lmshomepage'),
        PARAM_TEXT,
        80
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_lmshomepage/medium_risk_student_body',
        get_string('student_email_body', 'local_lmshomepage'),
        get_string('email_body_desc', 'local_lmshomepage'),
        get_string('medium_risk_student_body_default', 'local_lmshomepage'),
        PARAM_RAW,
        80,
        14
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_lmshomepage/medium_risk_teacher_body',
        get_string('teacher_email_body', 'local_lmshomepage'),
        get_string('teacher_email_body_desc', 'local_lmshomepage'),
        get_string('medium_risk_teacher_body_default', 'local_lmshomepage'),
        PARAM_RAW,
        80,
        10
    ));

    // ════════════════════════════════════════════════════════════════════════
    // SECTION 6 — HIGH RISK notification settings
    //   Zone: below risk_high_threshold
    //   Example with defaults: below 60%
    // ════════════════════════════════════════════════════════════════════════

    $settings->add(new admin_setting_heading(
        'local_lmshomepage/heading_high_risk',
        get_string('heading_high_risk', 'local_lmshomepage'),
        get_string('heading_high_risk_desc', 'local_lmshomepage')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/high_risk_notify_student',
        get_string('notify_student', 'local_lmshomepage'),
        get_string('notify_student_desc', 'local_lmshomepage'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_lmshomepage/high_risk_cc_teacher',
        get_string('cc_teacher', 'local_lmshomepage'),
        get_string('cc_teacher_desc', 'local_lmshomepage'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/high_risk_admin_email',
        get_string('admin_email', 'local_lmshomepage'),
        get_string('admin_email_desc', 'local_lmshomepage'),
        '',
        PARAM_TEXT,
        80
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/high_risk_reminder_days',
        get_string('reminder_days', 'local_lmshomepage'),
        get_string('reminder_days_desc', 'local_lmshomepage'),
        '3',
        PARAM_INT,
        6
    ));

    $settings->add(new admin_setting_configtext(
        'local_lmshomepage/high_risk_subject',
        get_string('email_subject', 'local_lmshomepage'),
        get_string('email_subject_desc', 'local_lmshomepage'),
        get_string('high_risk_subject_default', 'local_lmshomepage'),
        PARAM_TEXT,
        80
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_lmshomepage/high_risk_student_body',
        get_string('student_email_body', 'local_lmshomepage'),
        get_string('email_body_desc', 'local_lmshomepage'),
        get_string('high_risk_student_body_default', 'local_lmshomepage'),
        PARAM_RAW,
        80,
        16
    ));

    $settings->add(new admin_setting_configtextarea(
        'local_lmshomepage/high_risk_teacher_body',
        get_string('teacher_email_body', 'local_lmshomepage'),
        get_string('teacher_email_body_desc', 'local_lmshomepage'),
        get_string('high_risk_teacher_body_default', 'local_lmshomepage'),
        PARAM_RAW,
        80,
        12
    ));
}
