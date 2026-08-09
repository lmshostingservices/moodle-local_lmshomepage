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
 * External function: local_lmshomepage_get_active_user_count
 *
 * Returns the count of non-suspended, non-deleted Moodle user accounts.
 * Queries mdl_user directly so it is not subject to the capability
 * restrictions that prevent core_user_get_users from exposing the
 * suspended field via a token with limited permissions.
 *
 * This is the authoritative "active users" count — e.g. ITLC has 1,439
 * non-suspended users and 13,146 suspended (historical students). This
 * function returns the 1,439 figure correctly.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_active_user_count
 *
 * Returns:
 *   { "count": 1439 }
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_active_user_count extends \external_api {
    public static function execute_parameters (): \external_function_parameters {
        return new \external_function_parameters([]);
    }

    /**
     * Count non-suspended, non-deleted user accounts.
     * Excludes the Moodle guest account (id=1 / username='guest').
     *
     * @return array { count: int }
     */
    public static function execute (): array {
        global $DB;

        $count = $DB->count_records_select(
            'user',
            "deleted = 0 AND suspended = 0 AND id != 1 AND username != 'guest'"
        );

        return ['count' => (int) $count];
    }

    public static function execute_returns (): \external_single_structure {
        return new \external_single_structure([
            'count' => new \external_value(PARAM_INT, 'Number of non-suspended, non-deleted user accounts'),
        ]);
    }
}
