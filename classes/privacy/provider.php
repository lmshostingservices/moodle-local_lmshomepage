<?php
namespace local_lmshomepage\privacy;

use core_privacy\local\metadata\null_provider;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy API: this plugin stores no personal data.
 *
 * The current user's display name is read from $USER->firstname / lastname
 * and passed as a data-attribute to the browser widget solely for the
 * purpose of rendering a personalised greeting.  It is never transmitted
 * to any external server and is never persisted.
 */
class provider implements null_provider {

    /**
     * Returns the reason this plugin does not store personal data.
     *
     * @return string Language string key.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
