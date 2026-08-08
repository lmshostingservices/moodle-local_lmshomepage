<?php
defined('MOODLE_INTERNAL') || die();

// NUMERIC SCHEME RULE: must be exactly 13 digits (YYYYMMDDNNNNN), strictly greater
// than the previous release. The prior value 2026080304 was only 10 digits, making
// it numerically smaller than any prior 13-digit install and suppressing update
// notifications. Production is at 2026080800001; this value is set strictly greater.
$plugin->version   = 2026080800210;  // YYYYMMDDNNNNN — 8 Aug 2026, sequence 210.
$plugin->requires  = 2022041900;        // Moodle 4.0+
$plugin->component = 'local_lmshomepage';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '2.11.38';
