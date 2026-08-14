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
 * External function: local_lmshomepage_get_all_categories
 *
 * Returns ALL course categories from mdl_course_categories, including hidden
 * ones (visible=0), by reading the database directly rather than going through
 * core_course_get_categories which filters out hidden categories unless the
 * calling user has moodle/category:viewhiddencategories.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_all_categories
 *   (no parameters required)
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_all_categories extends \external_api {
    public static function execute_parameters (): \external_function_parameters {
        return new \external_function_parameters([]);
    }

    /**
     * Return all course categories including hidden ones.
     *
     * @return array
     */
    public static function execute (): array {
        global $DB;

        $rows = $DB->get_records_sql("
            SELECT id, name, parent, visible, sortorder
              FROM {course_categories}
             ORDER BY sortorder ASC, name ASC
        ");

        $result = [];
        foreach ($rows as $r) {
            $result[] = [
                'id'      => (int)    $r->id,
                'name'    => (string) $r->name,
                'parent'  => (int)    $r->parent,
                'visible' => (int)    $r->visible,
            ];
        }
        return $result;
    }

    public static function execute_returns (): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'id'      => new \external_value(PARAM_INT,  'Category ID'),
                'name'    => new \external_value(PARAM_TEXT, 'Category name'),
                'parent'  => new \external_value(PARAM_INT,  'Parent category ID (0 = top-level)'),
                'visible' => new \external_value(PARAM_INT,  '1 = visible, 0 = hidden'),
            ])
        );
    }
}
