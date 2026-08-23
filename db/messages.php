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
 * Message provider definitions for local_lmshomepage.
 *
 * Registers the 'attendance_reminder' message type so Moodle's messaging
 * system can route it correctly and users can configure their notification
 * preferences for it.
 *
 * NOTE: MESSAGE_OUTPUT_EMAIL / MESSAGE_OUTPUT_POPUP constants were removed
 * in Moodle 4.x — do NOT reference them here.  Moodle manages output
 * defaults internally; we simply declare the provider name.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

$messageproviders = [
    'attendance_reminder' => [],
];
