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

namespace local_lmshomepage\privacy;

use core_privacy\local\metadata\null_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy API: this plugin stores no personal data.
 *
 * The current user's display name is read from $USER->firstname / lastname
 * and passed as a data-attribute to the browser widget solely for the
 * purpose of rendering a personalised greeting.  It is never transmitted
 * to any external server and is never persisted.
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements null_provider {
    /**
     * Returns the reason this plugin does not store personal data.
     *
     * @return string Language string key.
     */
    public static function get_reason (): string {
        return 'privacy:metadata';
    }
}
