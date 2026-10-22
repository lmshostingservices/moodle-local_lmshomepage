# Changelog

## [2.11.73] - 2026-10-22

Moodle plugin version: `2026102200`.

### Added

- Read-only `local_lmshomepage_get_completion_batch` web service, limited to
  20 requested enrolled users in one course per request.
- Moodle core completion results with completion-report capability checks,
  active-enrolment checks and separate-group access restrictions.
- Optional standard-log cursor support. Missing log coverage, expired or
  invalid cursors, bounded event overflow and unclassified course writes
  require full reconciliation rather than assuming results are unchanged.

### Compatibility and rollout

- Uses Moodle's core completion external implementation from
  `completion/classes/external.php`.
- Registers the new reader in the LMS Homepage dashboard service.
- Adds the matching upgrade savepoint; no Moodle database schema changes.
- The Homepage application must be published separately to use bulk reads.
  It retains a paced core-API fallback where the new reader is unavailable.
- Log-delta mode in the Homepage application remains disabled by default
  until participating sites' event-log coverage is reviewed.
- Back up first, check the installed version is older than this release,
  and pilot on EMS before rolling out to other sites.

### Verification

- PHP syntax and 14 isolated plugin contract checks passed.
- Installable archive structure, version and service registration checked.
- Not yet installed or integration-tested inside live Moodle.

### Release-console review corrections

- Added the complete Moodle GPL header and file attribution metadata to the
  completion batch reader.
- Narrowed CommHub cursor, source-list and plaintext response declarations to
  `PARAM_TEXT`, retaining the existing strict cursor/source parsing and access
  checks.
- Documented the completion reader's JSON-only `PARAM_RAW` return exception on
  its declaration. The Homepage decodes and shape-checks this server-generated
  JSON; it is not rendered directly as HTML.
- Corrected the reported callback/class spacing and CSV export statement
  formatting. No saved records, delivery actions or financial operations were
  changed by these release-review corrections.

Earlier release notes are retained in `CHANGES.txt`.
