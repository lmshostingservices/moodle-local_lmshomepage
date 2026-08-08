<?php
/**
 * Scheduled task definitions for local_lmshomepage.
 *
 * The attendance_kpi_check task runs daily (default: 07:00 site time).
 * It finds all students whose attendance has fallen below the configured
 * KPI threshold and sends them a reminder via Moodle's messaging system.
 * The trainer is also notified via a separate message.
 *
 * Administrators can adjust the schedule under:
 *   Site Administration → Server → Scheduled tasks
 */
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_lmshomepage\task\attendance_kpi_check',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '7',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
