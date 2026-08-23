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
 * Legacy callback functions for local_lmshomepage.
 *
 * Moodle 4.3+ uses the Hooks API — callbacks are registered in db/hooks.php
 * and implemented in classes/hook_callbacks.php.
 *
 * NOTE: local_lmshomepage_before_standard_html_head() has been intentionally
 * removed.  On Moodle 4.3+ its mere presence triggers a deprecation notice
 * from process_legacy_callbacks(); the Hooks API (before_standard_head_html_generation)
 * handles <head> injection instead.  On Moodle < 4.3 the CSS will not be
 * injected — those versions are no longer supported.
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

/**
 * Inject the dashboard widget div and loader script before the page footer.
 *
 * Called by Moodle 4.x via get_plugins_with_function('before_footer').
 * Moodle 4.3+ uses the before_footer_html_generation hook in db/hooks.php.
 *
 * IMPORTANT: Moodle's before_footer plugin system collects the RETURN VALUE
 * of this function (via $output .= $function ()) and appends it to the footer
 * string.  Using echo here would send output to PHP's raw buffer outside
 * Moodle's footer assembly — causing the widget to appear in the wrong
 * position or not at all.  Always return, never echo.
 */
function local_lmshomepage_before_footer(): string {
    static $done = false;
    if ($done || !_lmshp_is_active()) {
        return '';
    }
    $done = true;
    return \local_lmshomepage\hook_callbacks::widget_html();
}

// ── Internal helpers ────────────────────────────────────────────────────────

function _lmshp_is_active(): bool {
    global $PAGE, $USER, $COURSE;
    if (!get_config('local_lmshomepage', 'enabled')) {
        return false;
    }
    if (!_lmshp_api_url()) {
        return false;
    }
    // Do nothing for logged-out or guest users.
    if (!isloggedin() || isguestuser($USER)) {
        return false;
    }
    // Standard Moodle front page.
    if ($PAGE->pagetype === 'site-index' || $PAGE->pagelayout === 'frontpage') {
        return true;
    }
    // Custom themes (e.g. Wombat) that render the site home as a course view
    // of the site course (SITEID = 1) using the 'course' layout.
    if ($PAGE->pagelayout === 'course'
        && !empty($COURSE)
        && (int) $COURSE->id === SITEID) {
        return true;
    }
    return false;
}

function _lmshp_api_url(): string {
    return rtrim((string) get_config('local_lmshomepage', 'apiurl'), '/');
}
