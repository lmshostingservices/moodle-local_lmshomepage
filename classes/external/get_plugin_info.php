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
 * External function: local_lmshomepage_get_plugin_info
 *
 * Returns the installed plugin release string and version integer, read
 * directly from the plugin's version.php file.
 *
 * This function is intentionally the simplest possible — it requires no
 * parameters, performs no DB work, and is safe to call with any token that
 * has access to the lmshomepage_dashboard service.  Its primary purpose is
 * to let the LMS Hosting Services admin panel verify the *exact* version
 * installed on each client site without needing Moodle admin credentials.
 *
 * Usage (REST):
 *   wsfunction=local_lmshomepage_get_plugin_info
 *
 * Returns:
 *   { "release": "2.11.43", "version": 2026081100002 }
 *
 * Probe logic (server side):
 *   - Valid response with release + version  → plugin installed at this version
 *   - errorcode "wsfunctionnotimplemented"   → plugin missing OR older version
 *   - any other Moodle exception             → plugin present but misconfigured
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_plugin_info extends \external_api {
    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([]);
    }

    /**
     * Return installed plugin version metadata.
     *
     * Reads version.php on every request so an upgrade is visible immediately.
     * core_plugin_manager keeps plugin metadata in memory for the request and
     * can briefly expose the previous release during the post-upgrade window.
     *
     * @return array { release: string, version: int }
     */
    public static function execute(): array {
        self::validate_context(\context_system::instance());

        $release = 'unknown';
        $version = 0;
        $plugindir = \core_component::get_plugin_directory('local_lmshomepage');

        if (is_string($plugindir)) {
            $versionfile = $plugindir . '/version.php';
            if (is_readable($versionfile)) {
                // version.php assigns its metadata to this local $plugin object.
                // Use include (not include_once): Moodle may already have loaded
                // the file elsewhere, but this probe needs a fresh disk read.
                $plugin = new \stdClass();
                include($versionfile);

                $release = isset($plugin->release) ? (string) $plugin->release : 'unknown';
                $version = isset($plugin->version) ? (int) $plugin->version : 0;
            }
        }

        return ['release' => $release, 'version' => $version];
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'release' => new \external_value(PARAM_TEXT, 'Plugin release string, e.g. "2.11.43"'),
            'version' => new \external_value(PARAM_INT,  'Plugin version integer, e.g. 2026081100002'),
        ]);
    }
}
