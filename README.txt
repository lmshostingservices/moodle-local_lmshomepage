LMS Home Page — Moodle Plugin
==============================
Version: 2.11.38  (version code 2026080304)
Component: local_lmshomepage
Requires: Moodle 4.0+ (also supports Moodle 5+)

Latest change (2.11.36): Completion Report quizzes now show Passed/Not Passed like
assignments (grade-to-pass driven), and the report lists ONLY activities ticked as
course-completion criteria. See CHANGES.txt for full history.


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
