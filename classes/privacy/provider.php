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
 * Privacy API implementation for the LMS Home Page plugin.
 *
 * FIX-LMSHP-PRIVACY (v2.11.66): this class previously implemented null_provider, which is a
 * formal assertion that the plugin stores no personal data at all. That was not true. The plugin
 * creates three tables and all three hold personal data:
 *
 *   local_lmshomepage_reminders  userid, courseid, risk level
 *   local_lmshomepage_log        userid, student name, student email, recipient user id,
 *                                recipient name, notification subject, risk level, percentage
 *   local_lmshomepage_commhub    student id, student name, student email, trainer id,
 *                                trainer name, message subject and MESSAGE BODY
 *
 * The old privacy:metadata string went further and stated "No message content is stored", which
 * local_lmshomepage_commhub.message_body contradicts directly.
 *
 * The consequence was not only a failed plugins-directory review: on every site running the
 * plugin a subject access request returned nothing from it and an erasure request deleted
 * nothing, while the data was really there.
 *
 * Two people can appear in a single row. A notification names the learner it is about AND the
 * person it was sent to; a commhub message names the student AND the trainer.
 *
 * Finding and exporting therefore search both roles. ERASING does not treat them alike: the row
 * exists to record something about the first person, so when they leave it goes with them, and
 * when the second person leaves only their own fields are cleared. Deleting on either match would
 * mean one user's erasure request destroying another user's record. See delete_for_userids().
 *
 * @package    local_lmshomepage
 * @copyright  2026 College Australia
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_lmshomepage\privacy;

use context;
use context_user;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * Privacy provider for local_lmshomepage.
 */
class provider implements
        \core_privacy\local\metadata\provider,
        \core_privacy\local\request\core_userlist_provider,
        \core_privacy\local\request\plugin\provider {

    /**
     * Describe every piece of personal data this plugin stores.
     *
     * @param collection $collection the collection to add to.
     * @return collection the same collection, populated.
     */
    public static function get_metadata(collection $collection): collection {

        $collection->add_database_table(
            'local_lmshomepage_reminders',
            [
                'userid'     => 'privacy:metadata:reminders:userid',
                'courseid'   => 'privacy:metadata:reminders:courseid',
                'risk_level' => 'privacy:metadata:reminders:risk_level',
                'timesent'   => 'privacy:metadata:reminders:timesent',
            ],
            'privacy:metadata:reminders'
        );

        $collection->add_database_table(
            'local_lmshomepage_log',
            [
                'userid'           => 'privacy:metadata:log:userid',
                'student_name'     => 'privacy:metadata:log:student_name',
                'student_email'    => 'privacy:metadata:log:student_email',
                'courseid'         => 'privacy:metadata:log:courseid',
                'course_name'      => 'privacy:metadata:log:course_name',
                'activity_name'    => 'privacy:metadata:log:activity_name',
                'risk_level'       => 'privacy:metadata:log:risk_level',
                'percentage'       => 'privacy:metadata:log:percentage',
                'kpi_threshold'    => 'privacy:metadata:log:kpi_threshold',
                'recipient_type'   => 'privacy:metadata:log:recipient_type',
                'recipient_userid' => 'privacy:metadata:log:recipient_userid',
                'recipient_name'   => 'privacy:metadata:log:recipient_name',
                'subject'          => 'privacy:metadata:log:subject',
                'timesent'         => 'privacy:metadata:log:timesent',
            ],
            'privacy:metadata:log'
        );

        $collection->add_database_table(
            'local_lmshomepage_commhub',
            [
                'studentid'       => 'privacy:metadata:commhub:studentid',
                'student_name'    => 'privacy:metadata:commhub:student_name',
                'student_email'   => 'privacy:metadata:commhub:student_email',
                'trainerid'       => 'privacy:metadata:commhub:trainerid',
                'trainer_name'    => 'privacy:metadata:commhub:trainer_name',
                'courseid'        => 'privacy:metadata:commhub:courseid',
                'course_name'     => 'privacy:metadata:commhub:course_name',
                'assessment_name' => 'privacy:metadata:commhub:assessment_name',
                'channel'         => 'privacy:metadata:commhub:channel',
                'message_subject' => 'privacy:metadata:commhub:message_subject',
                'message_body'    => 'privacy:metadata:commhub:message_body',
                'status'          => 'privacy:metadata:commhub:status',
                'timesent'        => 'privacy:metadata:commhub:timesent',
            ],
            'privacy:metadata:commhub'
        );

        return $collection;
    }

    /**
     * Return the contexts holding data for this user.
     *
     * All three tables record site-level facts about a person rather than about a course
     * activity, so the data belongs in that person's own user context.
     *
     * @param int $userid the user to search for.
     * @return contextlist the contexts holding data for this user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();

        $found = $DB->record_exists('local_lmshomepage_reminders', ['userid' => $userid])
            || $DB->record_exists_select(
                'local_lmshomepage_log',
                'userid = :u1 OR recipient_userid = :u2',
                ['u1' => $userid, 'u2' => $userid]
            )
            || $DB->record_exists_select(
                'local_lmshomepage_commhub',
                'studentid = :s OR trainerid = :t',
                ['s' => $userid, 't' => $userid]
            );

        if ($found) {
            $contextlist->add_user_context($userid);
        }

        return $contextlist;
    }

    /**
     * Return the users who have data in the given context.
     *
     * A single row can name two people, so both roles are searched.
     *
     * @param userlist $userlist the userlist to add matching users to.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if (!$context instanceof context_user) {
            return;
        }

        $params = ['userid' => $context->instanceid];

        $userlist->add_from_sql('userid', "SELECT userid
                                             FROM {local_lmshomepage_reminders}
                                            WHERE userid = :userid", $params);

        $userlist->add_from_sql('userid', "SELECT userid
                                             FROM {local_lmshomepage_log}
                                            WHERE userid = :userid", $params);

        $userlist->add_from_sql('userid', "SELECT recipient_userid AS userid
                                             FROM {local_lmshomepage_log}
                                            WHERE recipient_userid = :userid", $params);

        $userlist->add_from_sql('userid', "SELECT studentid AS userid
                                             FROM {local_lmshomepage_commhub}
                                            WHERE studentid = :userid", $params);

        $userlist->add_from_sql('userid', "SELECT trainerid AS userid
                                             FROM {local_lmshomepage_commhub}
                                            WHERE trainerid = :userid", $params);
    }

    /**
     * Export all data this plugin holds about the approved user.
     *
     * @param approved_contextlist $contextlist the approved contexts to export for.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof context_user || $context->instanceid != $userid) {
                continue;
            }

            $root = [get_string('pluginname', 'local_lmshomepage')];

            // Attendance reminders sent about this user.
            $reminders = $DB->get_records('local_lmshomepage_reminders', ['userid' => $userid], 'timesent ASC');
            if ($reminders) {
                $rows = [];
                foreach ($reminders as $r) {
                    $rows[] = (object)[
                        'course'     => self::course_name((int)$r->courseid),
                        'risk_level' => $r->risk_level,
                        'timesent'   => transform::datetime($r->timesent),
                    ];
                }
                writer::with_context($context)->export_data(
                    array_merge($root, [get_string('privacy:path:reminders', 'local_lmshomepage')]),
                    (object)['reminders' => $rows]
                );
            }

            // Notification log. Two roles in one table, exported separately so the person can see
            // which rows are about them and which were merely addressed to them.
            $about = $DB->get_records('local_lmshomepage_log', ['userid' => $userid], 'timesent ASC');
            if ($about) {
                writer::with_context($context)->export_data(
                    array_merge($root, [get_string('privacy:path:logabout', 'local_lmshomepage')]),
                    (object)['notifications' => array_values(array_map(static function ($r) { return self::log_row($r); }, $about))]
                );
            }

            $received = $DB->get_records('local_lmshomepage_log', ['recipient_userid' => $userid], 'timesent ASC');
            if ($received) {
                writer::with_context($context)->export_data(
                    array_merge($root, [get_string('privacy:path:logreceived', 'local_lmshomepage')]),
                    (object)['notifications' => array_values(array_map(static function ($r) { return self::log_row($r); }, $received))]
                );
            }

            // Communication hub messages, as student and as trainer.
            $asstudent = $DB->get_records('local_lmshomepage_commhub', ['studentid' => $userid], 'timesent ASC');
            if ($asstudent) {
                writer::with_context($context)->export_data(
                    array_merge($root, [get_string('privacy:path:commhubstudent', 'local_lmshomepage')]),
                    (object)['messages' => array_values(array_map(static function ($r) { return self::commhub_row($r); }, $asstudent))]
                );
            }

            $astrainer = $DB->get_records('local_lmshomepage_commhub', ['trainerid' => $userid], 'timesent ASC');
            if ($astrainer) {
                writer::with_context($context)->export_data(
                    array_merge($root, [get_string('privacy:path:commhubtrainer', 'local_lmshomepage')]),
                    (object)['messages' => array_values(array_map(static function ($r) { return self::commhub_row($r); }, $astrainer))]
                );
            }
        }
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param context $context the context to delete in.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;

        if (!$context instanceof context_user) {
            return;
        }

        self::delete_for_userids($DB, [$context->instanceid]);
    }

    /**
     * Delete all data for one user across the approved contexts.
     *
     * @param approved_contextlist $contextlist the approved contexts to delete in.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context instanceof context_user && $context->instanceid == $userid) {
                self::delete_for_userids($DB, [$userid]);
            }
        }
    }

    /**
     * Delete data for several users in one context.
     *
     * @param approved_userlist $userlist the approved users to delete.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof context_user) {
            return;
        }

        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }

        self::delete_for_userids($DB, $userids);
    }

    /**
     * Erase these users from all three tables.
     *
     * A row can carry two people, and the right treatment differs by which one is leaving. An
     * earlier version of this method deleted any row naming the user in either role. That erased
     * more than it should: a trainer's erasure request would have destroyed the record of a
     * message sent to a learner, taking the learner's data with it and leaving a gap the site
     * cannot account for.
     *
     * So each table is handled by who the row is actually ABOUT:
     *
     *   reminders   one subject. Deleted.
     *   log         the row exists to record a notification about `userid`. If that person
     *               leaves, the row goes. If the RECIPIENT leaves, the row is about someone
     *               else, so only the recipient's own fields are cleared.
     *   commhub     the row exists to record a message about `studentid`, and message_body is
     *               very likely to name them. If the student leaves, the row goes. If the
     *               TRAINER leaves, only the trainer's fields are cleared.
     *
     * Clearing rather than nulling the id: recipient_userid and trainerid are NOT NULL integer
     * columns, so they are set to 0 -- the conventional Moodle "no user" value -- and the
     * matching name fields are emptied. What survives is that a notification went to somebody,
     * with nothing left identifying who.
     *
     * @param \moodle_database $db the database to act on.
     * @param array $userids user ids to erase.
     * @return void
     */
    private static function delete_for_userids(\moodle_database $db, array $userids) {
        if (empty($userids)) {
            return;
        }

        // Reminders: one subject per row, nothing to preserve.
        [$insql, $params] = $db->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'rem');
        $db->delete_records_select('local_lmshomepage_reminders', "userid $insql", $params);

        // Notification log: the row is about `userid`.
        [$insql, $params] = $db->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'logs');
        $db->delete_records_select('local_lmshomepage_log', "userid $insql", $params);

        // ...and merely addressed to `recipient_userid`, whose fields are cleared in place.
        [$insql, $params] = $db->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'logr');
        $db->execute("UPDATE {local_lmshomepage_log}
                         SET recipient_userid = 0, recipient_name = ''
                       WHERE recipient_userid $insql", $params);

        // Communication hub: the row is about `studentid`, and message_body will normally
        // discuss them, so the whole row goes with them.
        [$insql, $params] = $db->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'chs');
        $db->delete_records_select('local_lmshomepage_commhub', "studentid $insql", $params);

        // ...and the trainer is the second party, cleared in place.
        [$insql, $params] = $db->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'cht');
        $db->execute("UPDATE {local_lmshomepage_commhub}
                         SET trainerid = 0, trainer_name = ''
                       WHERE trainerid $insql", $params);
    }

    /**
     * Shape one notification-log row for export.
     *
     * @param \stdClass $r the raw record.
     * @return \stdClass the exportable row.
     */
    private static function log_row(\stdClass $r): \stdClass {
        return (object)[
            'student_name'   => $r->student_name,
            'student_email'  => $r->student_email,
            'course'         => !empty($r->course_name) ? $r->course_name : self::course_name((int)$r->courseid),
            'activity'       => $r->activity_name,
            'risk_level'     => $r->risk_level,
            'percentage'     => $r->percentage,
            'kpi_threshold'  => $r->kpi_threshold,
            'recipient_type' => $r->recipient_type,
            'recipient_name' => $r->recipient_name,
            'subject'        => $r->subject,
            'timesent'       => transform::datetime($r->timesent),
        ];
    }

    /**
     * Shape one communication-hub row for export.
     *
     * @param \stdClass $r the raw record.
     * @return \stdClass the exportable row.
     */
    private static function commhub_row(\stdClass $r): \stdClass {
        return (object)[
            'student_name'    => $r->student_name,
            'student_email'   => $r->student_email,
            'trainer_name'    => $r->trainer_name,
            'course'          => !empty($r->course_name) ? $r->course_name : self::course_name((int)$r->courseid),
            'assessment_name' => $r->assessment_name,
            'channel'         => $r->channel,
            'message_subject' => $r->message_subject,
            'message_body'    => $r->message_body,
            'status'          => $r->status,
            'timesent'        => transform::datetime($r->timesent),
        ];
    }

    /**
     * Resolve a course name for export, tolerating a deleted course.
     *
     * @param int $courseid the course id, possibly stale.
     * @return string the course full name, or the raw id when the course has gone.
     */
    private static function course_name(int $courseid): string {
        global $DB;

        if (empty($courseid)) {
            return '';
        }

        $name = $DB->get_field('course', 'fullname', ['id' => $courseid], IGNORE_MISSING);

        return $name !== false ? (string)$name : (string)$courseid;
    }
}
