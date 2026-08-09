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

namespace local_lmshomepage;

defined('MOODLE_INTERNAL') || die();

/**
 * Hook callbacks for Moodle 5+.
 * Moodle 4.x uses the equivalent lib.php callback functions.
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Inject the full-width body class and widget CSS link into <head>.
     *
     * Called by the Moodle 4.3+ Hooks API via before_standard_head_html_generation.
     * For Moodle < 4.3, the lib.php callback local_lmshomepage_before_standard_html_head()
     * is used instead.
     */
    public static function before_standard_html_head (\core\hook\output\before_standard_head_html_generation $hook): void {
        static $head_done = false;
        if ($head_done || !self::is_active()) {
            return;
        }
        $head_done = true;
        $apiurl = self::api_url();
        // Inject full-width override styles inline — avoids calling add_body_class()
        // after output has started (which throws a fatal error in Moodle 5+).
        $hook->add_html(self::fullwidth_css());
        $hook->add_html('<link rel="stylesheet" href="' . s($apiurl . '/moodle-widget.css') . '">');
    }

    private static function fullwidth_css (): string {
        return '<style>'
             // Full-width layout overrides
             . '#page,#page-wrapper,#page-content,.main-inner,#region-main,#region-main-box,[role="main"]'
             . '{max-width:100%!important;padding-left:0!important;padding-right:0!important;'
             . 'margin-left:0!important;margin-right:0!important;width:100%!important;}'
             . '.columns-3,.columns-2{display:block!important;}'
             // Hide standard Moodle sidebars
             . 'aside,#block-region-side-pre,#block-region-side-post{display:none!important;}'
             // ── Wombat theme specifics ───────────────────────────────────────────
             // Hide the Wombat custom navbar greeting ("Hi Admin User, Welcome to Wombat!")
             . '.wombat-custom-navbar{display:none!important;}'
             // Hide the Wombat sidebar/drawer and hamburger toggle — primary top nav stays visible
             . '.wombat-sidebar,#wombat-menu-toggle,#wombat-sidebar-close{display:none!important;}'
             // Remove padding/margin from Wombat layout row and content area
             . '.wombat-layout-row{padding:0!important;margin:0!important;display:block!important;}'
             . '.wombat-content-area,.wombat-content-full{padding:0!important;width:100%!important;max-width:100%!important;}'
             // NOTE: .wombat-header / .wombat-top-bar are intentionally NOT hidden —
             // the primary navigation bar must remain accessible so users can navigate
             // away from the home page.  The Wombat theme's own CSS handles the
             // body padding-top offset for its fixed navbar; we must not override it.
             // ────────────────────────────────────────────────────────────────────
             // Remove Moodle stub <br> that causes a gap above the widget
             . '[role="main"]>br,#region-main>br,#user-notifications{display:none!important;}'
             // Hide the course/page title area (standard themes) — NOT #page-header
             // because on some themes #page-header contains the primary navigation bar.
             // Target only the content-area chrome: the page title, breadcrumb heading,
             // and activity header.  The primary navbar above these is untouched.
             . '.page-context-header,.context-header,.page-header-headings,'
             . '.activity-header,.course-header{display:none!important;}'
             // Hide the secondary course navigation tabs (Home / Settings / Participants / Reports …)
             . '[data-region="secondary-navigation"],.secondary-navigation,'
             . '.nav-tabs.secondary-navigation-tabs,.context-nav,.tertiary-navigation{display:none!important;}'
             // Hide the default front-page Moodle course content (activities, sections, enrol button)
             . '.course-content,.frontpage-course-list,.frontpage-sections,'
             . '.siteadminnode,.page-mod-type-section{display:none!important;}'
             . '</style>';
    }

    /**
     * Inject the dashboard container and widget script before the footer.
     */
    public static function before_footer (\core\hook\output\before_footer_html_generation $hook): void {
        static $footer_done = false;
        if ($footer_done || !self::is_active()) {
            return;
        }
        $footer_done = true;
        $hook->add_html(self::widget_html());
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Returns true if the user should receive the 'admin' dashboard role.
     * Three independent checks — any one passing is sufficient:
     *
     *  1. get_archetype_roles('manager') lookup (standard Moodle API).
     *  2. Direct DB JOIN on role.archetype = 'manager' — bypasses any
     *     stale function-level cache that may miss newly-created custom roles.
     *  3. has_capability('moodle/site:viewreports') at system context —
     *     catches managers whose role was configured without explicitly
     *     inheriting the manager archetype but who still have report access.
     */
    private static function user_has_manager_role (int $userid): bool {
        global $DB;

        // ── Check 1: Moodle archetype API ─────────────────────────────────────
        $roleids = [];
        foreach (get_archetype_roles('manager') as $r) {
            $roleids[] = (int) $r->id;
        }
        if (!empty($roleids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
            if ($DB->record_exists_select(
                'role_assignments',
                "userid = :userid AND roleid $insql",
                array_merge(['userid' => $userid], $inparams)
            )) {
                return true;
            }
        }

        // ── Check 2: Direct DB join on role.archetype column ──────────────────
        // This catches custom roles where get_archetype_roles() returns a
        // cached or incomplete list (e.g. right after role creation).
        $sql = "SELECT ra.id
                  FROM {role_assignments} ra
                  JOIN {role} r ON r.id = ra.roleid AND r.archetype = 'manager'
                 WHERE ra.userid = :userid";
        if ($DB->record_exists_sql($sql, ['userid' => $userid])) {
            return true;
        }

        // ── Check 3: Capability fallback ──────────────────────────────────────
        // If the role is configured to grant moodle/site:viewreports at system
        // context (which all manager-archetype roles receive by default), treat
        // the user as admin.
        $systemctx = \context_system::instance();
        if (has_capability('moodle/site:viewreports', $systemctx, $userid, false)) {
            return true;
        }

        return false;
    }

    /**
     * Returns true if the user has been assigned a teacher or non-editing teacher
     * role anywhere in the system (course context, category context, etc.).
     * This catches non-editing teachers who only have course-level role assignments
     * and therefore won't have the relevant capabilities at the system context.
     */
    private static function user_has_teacher_role (int $userid): bool {
        global $DB;

        // Collect role IDs for 'teacher' and 'editingteacher' archetypes.
        $roleids = [];
        foreach (['teacher', 'editingteacher'] as $archetype) {
            foreach (get_archetype_roles($archetype) as $r) {
                $roleids[] = (int) $r->id;
            }
        }
        if (empty($roleids)) {
            return false;
        }

        list($insql, $inparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED);
        return $DB->record_exists_select(
            'role_assignments',
            "userid = :userid AND roleid $insql",
            array_merge(['userid' => $userid], $inparams)
        );
    }

    private static function is_active (): bool {
        global $PAGE, $USER, $COURSE;

        // ── Hard exclusions — checked FIRST, before any other logic ──────────
        // The before_standard_head_html_generation hook can fire before Moodle
        // fully populates $PAGE->pagetype / $PAGE->pagelayout in certain
        // contexts (e.g. admin pages, popups, maintenance mode).  These
        // exclusions guarantee we never inject on non-home pages regardless of
        // hook timing.
        $excluded_layouts = ['admin', 'maintenance', 'login', 'popup', 'embedded', 'secure', 'redirect'];
        if (!empty($PAGE->pagelayout) && in_array($PAGE->pagelayout, $excluded_layouts, true)) {
            return false;
        }
        if (!empty($PAGE->pagetype) && (
            str_starts_with($PAGE->pagetype, 'admin-') ||
            str_starts_with($PAGE->pagetype, 'my-')
        )) {
            return false;
        }
        // ─────────────────────────────────────────────────────────────────────

        if (!get_config('local_lmshomepage', 'enabled')) {
            return false;
        }
        if (!self::api_url()) {
            return false;
        }
        // Do nothing for logged-out or guest users — let Moodle's own login
        // page handle unauthenticated visitors cleanly.
        if (!isloggedin() || isguestuser($USER)) {
            return false;
        }
        // Standard Moodle front page — pagetype is 'site-index' on all
        // versions; pagelayout is 'frontpage' on standard themes.
        if ($PAGE->pagetype === 'site-index' || $PAGE->pagelayout === 'frontpage') {
            return true;
        }
        // Some custom themes (e.g. Wombat) render the Moodle site home as a
        // COURSE VIEW of the site course (course id = SITEID, always 1) using
        // the 'course' layout rather than the 'frontpage' layout.  In that
        // case pagetype is 'course-view-*' and pagelayout is 'course', so the
        // checks above would both be false.  Matching on SITEID ensures we
        // only activate on the site course, not on regular course pages.
        if ($PAGE->pagelayout === 'course'
            && !empty($COURSE)
            && (int) $COURSE->id === SITEID) {
            return true;
        }
        return false;
    }

    private static function api_url (): string {
        return rtrim((string) get_config('local_lmshomepage', 'apiurl'), '/');
    }

    public static function widget_html (): string {
        // ── Single-render guarantee ───────────────────────────────────────────
        // This guard lives here — not in the callers — because on Moodle 4.3–4.5
        // BOTH lib.php's local_lmshomepage_before_footer() AND the Hooks API
        // before_footer() callback call this function within the same PHP request.
        // Each caller has its own `static $done` flag, but those are separate
        // static variables that cannot block the cross-path second call.
        // Only by guarding inside widget_html() itself is the static shared
        // across all call paths:
        //   Moodle 4.0–4.2 → only lib.php fires         → 1 call  ✓
        //   Moodle 4.3–4.5 → lib.php + Hooks API fire   → 1 call  ✓
        //   Moodle 5.0+    → only Hooks API fires        → 1 call  ✓
        static $rendered = false;
        if ($rendered) {
            return '';
        }
        $rendered = true;

        global $USER;

        $apiurl   = self::api_url();
        $apitoken = (string) get_config('local_lmshomepage', 'apitoken');
        $siteid   = clean_param((string) get_config('local_lmshomepage', 'siteid'), PARAM_ALPHANUMEXT);

        // fullname() returns the user's display name. s() encodes it for safe
        // embedding in an HTML attribute value (escapes <, >, ", &, ').
        $username  = (!empty($USER) && !isguestuser($USER)) ? s(fullname($USER))   : '';
        $firstname = (!empty($USER) && !isguestuser($USER)) ? s($USER->firstname)  : '';
        $userid    = (!empty($USER) && !isguestuser($USER)) ? (int) $USER->id      : 0;

        // Determine role.
        // When a Moodle admin uses "Log in as" on a student profile, $USER becomes
        // that student but capability checks may still reflect admin-level access.
        // Detect this explicitly and always show the learner portal in that case.
        $context = \context_system::instance();
        if (!isloggedin() || isguestuser($USER)) {
            $role = 'guest';
        } elseif (\core\session\manager::is_loggedinas()) {
            // Admin is impersonating a student — always show learner view.
            $role = 'learner';
        } elseif (is_siteadmin($USER)
            || has_capability('moodle/site:viewreports', $context)
            || has_capability('moodle/site:viewparticipants', $context)
            || self::user_has_manager_role($USER->id)) {
            $role = 'admin';
        } elseif (has_capability('moodle/grade:viewall', $context)
               || has_capability('moodle/course:update', $context)
               || has_capability('gradereport/grader:view', $context)
               || self::user_has_teacher_role($USER->id)) {
            $role = 'teacher';
        } else {
            $role = 'learner';
        }

        $cachebust = date('YmdH'); // Changes every hour — forces fresh widget fetch

        // Guard script is served as a static external file from the plugin
        // directory (same Moodle origin → CSP-safe). It uses a window flag +
        // MutationObserver to remove any duplicate #lms-homepage-widget divs
        // that AJAX fragment responses inject into the DOM.
        $guardurl = (new \moodle_url('/local/lmshomepage/widget_guard.js'))->out(false);

        return '<div id="lms-homepage-widget"'
             . ' data-api="'       . s($apiurl)   . '"'
             . ' data-token="'     . s($apitoken) . '"'
             . ' data-site="'      . s($siteid)   . '"'
             . ' data-username="'  . $username    . '"'
             . ' data-userid="'    . $userid      . '"'
             . ' data-firstname="' . $firstname   . '"'
             . ' data-role="'      . $role        . '"'
             . '></div>'
             . '<script src="' . s($guardurl) . '?v=' . $cachebust . '"></script>'
             . '<script src="' . s($apiurl)   . '/moodle-widget.js?v=' . $cachebust . '" defer></script>';
    }
}
