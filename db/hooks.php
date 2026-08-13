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
 * Moodle 4.3+ Hooks API registration.
 *
 * For Moodle < 4.3, the lib.php callback local_lmshomepage_before_footer()
 * handles the widget footer injection.  The old before_standard_html_head
 * lib.php callback has been removed to avoid the Moodle 4.3+ deprecation
 * notice from process_legacy_callbacks(); <head> injection is handled
 * exclusively by the before_standard_head_html_generation hook below.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
