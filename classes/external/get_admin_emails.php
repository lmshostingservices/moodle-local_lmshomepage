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
 * External function: local_lmshomepage_get_admin_emails
 *
 * Returns the user ID, full name, and email of all Moodle site administrators.
 *
 * Used by M8 Day 30 to send the full overdue escalation report to all admins.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_admin_emails
 *   (no parameters)
 *
 * Returns:
 *   [
 *     {
 *       "userid":   5,
 *       "fullname": "Site Admin",
 *       "email":    "admin@wombat.edu.au"
 *     },
 *     ...
 *   ]
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_admin_emails extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([]);
    }

    /**
     * Return all site administrator email addresses.
     *
     * @return array
     */
    public static function execute(): array {
        global $CFG, $DB;

        // Moodle stores site admin IDs as a comma-separated string in mdl_config.
        $adminIds = explode(',', $CFG->siteadmins ?? '');
        $adminIds = array_filter(array_map('intval', $adminIds));

        if (empty($adminIds)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($adminIds, SQL_PARAMS_NAMED, 'adm');

        $sql = "
            SELECT id, firstname, lastname, email
            FROM {user}
            WHERE id $insql
              AND deleted  = 0
              AND suspended = 0
            ORDER BY lastname, firstname
        ";

        $rows = $DB->get_records_sql($sql, $inparams);

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'userid'   => (int)    $row->id,
                'fullname' => (string) trim($row->firstname . ' ' . $row->lastname),
                'email'    => (string) $row->email,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'   => new \external_value(PARAM_INT,  'Administrator user ID'),
                'fullname' => new \external_value(PARAM_TEXT, 'Administrator full name'),
                'email'    => new \external_value(PARAM_TEXT, 'Administrator email address'),
            ])
        );
    }
}
