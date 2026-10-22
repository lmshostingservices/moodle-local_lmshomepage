LMS Home Page — Moodle Plugin
==============================
Version: 2.11.72  (version code 2026100204)
Component: local_lmshomepage
Requires: Moodle 4.0+ (also supports Moodle 5+)

Latest change (2.11.72): adds secure, keyset-paged native CommHub history;
see CHANGES.txt for the packaged release notes and the repository's
docs/commhub-native-reader.md for the full contract and install notes.

CommHub MESSAGE HISTORY
-----------------------
The local_lmshomepage_get_commhub_messages web service reads native CommHub notes
and verified direct student/staff messages. Optional plugin automation logs are
included only when explicitly requested. It requires the integration token to
have moodle/site:config. It is read-only and never sends or writes messages.
The repository documentation covers source coverage, pagination, and install
at docs/commhub-native-reader.md.

The separate local_lmshomepage_get_commhub_activity endpoint also reads only
this plugin-owned log and resolves a unique exact Moodle email match. It
requires moodle/site:config. The native CommHub UI is /blocks/todo/view.php;
its source/API is not included here or configured by these plugin log readers,
so neither endpoint claims native CommHub or full conversation coverage.

Contract: studentid is required and positive; beforeid defaults to 0 (newest
page); limit defaults to 50 and must be 1-100. Results are ordered by message
ID descending and include at most limit entries. Pass nextCursor as beforeid for
the next page; nextCursor is 0 when there is no next page. Each message contains
id, studentid, channel, subject, plaintext body, status, direction (outbound),
and createdAt (Unix seconds).

2.11.54 REPORTING RELEASE — INSTALL & VERIFY
---------------------------------------------
1. Upload local_lmshomepage_v2.11.54.zip through Site Admin → Plugins →
   Install plugins, then complete the Moodle database upgrade.
2. Confirm Site administration → Plugins → Local plugins → LMS Home Page reports
   release 2.11.54 / version 2026082100.
3. In the LMS Hub, compare the Trainer Marking Report with the Overdue
   Submissions and Grading Backlog dashboard cards. They use the same 365-day
   submission window.
4. Check one approved marking-sheet completion and one native course completion
   without an approved marking sheet. Both should appear in Completed Units; the
   approved marking-sheet date takes precedence where it exists.
5. Open a cohort matrix and export CSV. The export must contain exactly the
   student-by-unit cells shown in the matrix, with matching outcomes.


WHAT IT DOES
------------
Replaces the standard Moodle front page with a fully custom, branded learning
portal dashboard. The dashboard is served from your hosted LMS Hub application
and embedded seamlessly — no visible iframe border, no inner scrollbar.

Features:
- Full-width hero, live KPI cards, course catalogue, leaderboard, learner groups
- Role-aware view: guest / learner / teacher / admin (detected from Moodle caps)
- Personalised greeting using the logged-in user's display name
- Seamless height-sync so the page scrolls naturally, not inside a box
- Supports: TDT Online Learning (tdt), Inner West Council (innerwest / iwc),
  Signature Training College (signature / stc) — add more in plugin settings


UPGRADING FROM A PREVIOUS VERSION
-----------------------------------
1. Download the new local_lmshomepage.zip
2. Go to: Site Admin → Plugins → Install plugins
3. Upload the ZIP and click "Install plugin from the ZIP file"
4. Moodle will detect the higher version number and run the upgrade
5. Click "Upgrade Moodle database now" if prompted

ALL EXISTING SETTINGS ARE PRESERVED — API URL, Site ID, API token, and the
enabled/disabled state are stored in Moodle's config_plugins table and survive
upgrades automatically. No tokens or web services need to be reconfigured.


FRESH INSTALLATION
-------------------
1. Download local_lmshomepage.zip
2. Site Admin → Plugins → Install plugins → Upload ZIP → Install
3. After install, go to: Site Admin → Local plugins → LMS Home Page
4. Fill in:
   - Dashboard URL:  https://your-app.replit.app  (no trailing slash)
   - Site ID:        tdt  OR  innerwest  OR  signature
   - API token:      (leave blank if not required)
   - Enable:         tick the checkbox
5. Save changes — the custom portal will appear on the Moodle home page immediately


UNINSTALLING
-------------
Site Admin → Plugins → Plugins overview → Local plugins → LMS Home Page → Uninstall
The standard Moodle front page is restored automatically.


SUPPORT
--------
Contact your LMS Hosting Services representative for assistance.
