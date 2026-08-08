/* LMS Homepage Widget — duplicate guard
 *
 * Loaded as an external script (CSP-safe, same Moodle origin) to prevent
 * the #lms-homepage-widget div from appearing more than once in the DOM.
 *
 * Why this is needed: Moodle delivers the widget footer code to the browser
 * more than once per page session — via AJAX fragment responses when showing
 * modals (e.g. the "already logged in" confirm dialog), via hook re-fires on
 * Moodle 4.3–4.5 where both lib.php and the Hooks API callbacks execute, and
 * via AJAX-navigation themes (Academi, custom Wombat theme) that fetch pages
 * as XHR and inject the response into the existing DOM.
 *
 * PHP-level guards do not help because each AJAX response is a fresh HTTP
 * request (own PHP process, own static variables). An inline <script> guard
 * would be blocked by Moodle's Content Security Policy in Moodle 4.3+.
 *
 * This external file is allowed by CSP (same origin), executes once per page
 * session via the window.__lmshp_guard flag, and uses MutationObserver to
 * continuously clean up any duplicate divs that AJAX injection adds later.
 */
(function () {
    'use strict';

    // Already running — bail out. Happens when the guard <script> tag is
    // injected a second time via DOM manipulation (not innerHTML, which
    // browsers block from executing scripts automatically).
    if (window.__lmshp_guard) { return; }
    window.__lmshp_guard = true;

    function dedupe() {
        var all = document.querySelectorAll('#lms-homepage-widget');
        // Keep the first instance; remove every subsequent one.
        for (var i = 1; i < all.length; i++) {
            if (all[i].parentNode) {
                all[i].parentNode.removeChild(all[i]);
            }
        }
    }

    // Dedupe immediately in case the hook already fired twice during this
    // synchronous page render.
    dedupe();

    // Watch for any future duplicates added asynchronously by AJAX responses.
    if (typeof MutationObserver !== 'undefined') {
        var observer = new MutationObserver(dedupe);
        observer.observe(document.documentElement, {
            childList: true,
            subtree: true
        });
    }
}());
