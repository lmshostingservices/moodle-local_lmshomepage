<?php
/**
 * External function: local_lmshomepage_log_commhub
 *
 * Writes a single communication record to the local_lmshomepage_commhub
 * table. Called by the LMS Hosting Services server after every automated
 * notification is sent (M7 due-date reminder, M8 overdue escalations,
 * M9 trainer marking alerts).
 *
 * Usage (REST POST):
 *   wsfunction=local_lmshomepage_log_commhub
 *   studentid=1042
 *   student_name=Emma Thompson
 *   student_email=e.thompson@wombat.edu.au
 *   trainerid=678
 *   trainer_name=Sarah Mitchell
 *   courseid=12
 *   course_name=Certificate IV Community Services
 *   assessment_name=Assessment 1
 *   channel=email           (email | sms | moodle)
 *   message_subject=Friendly Reminder - Your assessment is due in 7 days
 *   message_body=Hi Emma...
 *   status=sent             (sent | failed | pending)
 *
 * Returns:
 *   { "id": 42, "success": true }
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class log_commhub extends \external_api {

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'studentid'       => new \external_value(PARAM_INT,  'Student user ID'),
            'student_name'    => new \external_value(PARAM_TEXT, 'Student full name'),
            'student_email'   => new \external_value(PARAM_TEXT, 'Student email address'),
            'trainerid'       => new \external_value(PARAM_INT,  'Trainer user ID (0 if not applicable)', VALUE_DEFAULT, 0),
            'trainer_name'    => new \external_value(PARAM_TEXT, 'Trainer full name',                     VALUE_DEFAULT, ''),
            'courseid'        => new \external_value(PARAM_INT,  'Course ID',                             VALUE_DEFAULT, 0),
            'course_name'     => new \external_value(PARAM_TEXT, 'Course full name',                      VALUE_DEFAULT, ''),
            'assessment_name' => new \external_value(PARAM_TEXT, 'Assessment/activity name',              VALUE_DEFAULT, ''),
            'channel'         => new \external_value(PARAM_TEXT, 'Delivery channel: email, sms, or moodle'),
            'message_subject' => new \external_value(PARAM_TEXT, 'Message subject line'),
            'message_body'    => new \external_value(PARAM_TEXT, 'Full message body'),
            'status'          => new \external_value(PARAM_TEXT, 'Delivery status: sent, failed, or pending', VALUE_DEFAULT, 'sent'),
        ]);
    }

    /**
     * Insert a communication record into local_lmshomepage_commhub.
     *
     * @param int    $studentid
     * @param string $student_name
     * @param string $student_email
     * @param int    $trainerid
     * @param string $trainer_name
     * @param int    $courseid
     * @param string $course_name
     * @param string $assessment_name
     * @param string $channel
     * @param string $message_subject
     * @param string $message_body
     * @param string $status
     * @return array { id, success }
     */
    public static function execute(
        int    $studentid,
        string $student_name,
        string $student_email,
        int    $trainerid       = 0,
        string $trainer_name    = '',
        int    $courseid        = 0,
        string $course_name     = '',
        string $assessment_name = '',
        string $channel         = 'email',
        string $message_subject = '',
        string $message_body    = '',
        string $status          = 'sent'
    ): array {
        global $DB;

        // Validate channel.
        $allowed_channels = ['email', 'sms', 'moodle'];
        if (!in_array($channel, $allowed_channels)) {
            $channel = 'email';
        }

        // Validate status.
        $allowed_statuses = ['sent', 'failed', 'pending'];
        if (!in_array($status, $allowed_statuses)) {
            $status = 'sent';
        }

        $record = (object) [
            'timesent'        => time(),
            'studentid'       => $studentid,
            'student_name'    => $student_name,
            'student_email'   => $student_email,
            'trainerid'       => $trainerid,
            'trainer_name'    => $trainer_name,
            'courseid'        => $courseid,
            'course_name'     => $course_name,
            'assessment_name' => $assessment_name,
            'channel'         => $channel,
            'message_subject' => $message_subject,
            'message_body'    => $message_body,
            'status'          => $status,
        ];

        try {
            $id = $DB->insert_record('local_lmshomepage_commhub', $record);
            return ['id' => (int) $id, 'success' => true];
        } catch (\dml_exception $e) {
            return ['id' => 0, 'success' => false];
        }
    }

    public static function execute_returns(): \external_single_structure {
        return new \external_single_structure([
            'id'      => new \external_value(PARAM_INT,  'ID of the inserted commhub record (0 on failure)'),
            'success' => new \external_value(PARAM_BOOL, 'true if the record was inserted successfully'),
        ]);
    }
}
