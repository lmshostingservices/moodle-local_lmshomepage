<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_lmshomepage_upgrade($oldversion) {
    if ($oldversion < 2026080800) {
        upgrade_plugin_savepoint(true, 2026080800, 'local', 'lmshomepage');
    }
    return true;
}
