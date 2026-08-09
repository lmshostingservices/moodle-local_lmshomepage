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
 * Plugin version and release metadata for local_lmshomepage.
 *
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// NUMERIC SCHEME RULE: must be exactly 13 digits (YYYYMMDDNNNNN), strictly greater
// than the previous release. The prior value 2026080304 was only 10 digits, making
// it numerically smaller than any prior 13-digit install and suppressing update
// notifications. Production is at 2026080800001; this value is set strictly greater.
$plugin->version   = 2026080800211;  // YYYYMMDDNNNNN — 8 Aug 2026, sequence 211 (fixes non-13-digit version).
$plugin->requires  = 2022041900;        // Moodle 4.0+
$plugin->component = 'local_lmshomepage';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '2.11.39';
