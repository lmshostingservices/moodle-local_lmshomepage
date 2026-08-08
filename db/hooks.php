<?php
/**
 * Moodle 4.3+ Hooks API registration.
 *
 * For Moodle < 4.3, the lib.php callback local_lmshomepage_before_footer()
 * handles the widget footer injection.  The old before_standard_html_head
 * lib.php callback has been removed to avoid the Moodle 4.3+ deprecation
 * notice from process_legacy_callbacks(); <head> injection is handled
 * exclusively by the before_standard_head_html_generation hook below.
 */
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_lmshomepage\hook_callbacks::class . '::before_standard_html_head',
        'priority' => 500,
    ],
    [
        'hook'     => \core\hook\output\before_footer_html_generation::class,
        'callback' => \local_lmshomepage\hook_callbacks::class . '::before_footer',
        'priority' => 500,
    ],
];
