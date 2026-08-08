<?php
/**
 * Message provider definitions for local_lmshomepage.
 *
 * Registers the 'attendance_reminder' message type so Moodle's messaging
 * system can route it correctly and users can configure their notification
 * preferences for it.
 *
 * NOTE: MESSAGE_OUTPUT_EMAIL / MESSAGE_OUTPUT_POPUP constants were removed
 * in Moodle 4.x — do NOT reference them here.  Moodle manages output
 * defaults internally; we simply declare the provider name.
 */
defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'attendance_reminder' => [],
];
