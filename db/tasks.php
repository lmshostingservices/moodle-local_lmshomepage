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
 * Scheduled task definitions for local_lmshomepage.
 *
 * The attendance_kpi_check task runs daily (default: 07:00 site time).
 * It finds all students whose attendance has fallen below the configured
 * KPI threshold and sends them a reminder via Moodle's messaging system.
 * The trainer is also notified via a separate message.
 *
 * Administrators can adjust the schedule under:
 *   Site Administration → Server → Scheduled tasks
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
