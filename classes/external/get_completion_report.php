<?php
/**
 * External function: local_lmshomepage_get_completion_report  (v2.11.36)
 *
 * Returns one row per enrolled student with per-activity and course-level
 * completion data for a single Moodle course.
 *
 * DEBUG MODE (v2.11.34):
 * If a DB query fails, the function returns a single sentinel row where
 * fullname = "FAIL:<STEP>:<error>" so the calling server can read the
 * failing step without needing Moodle developer debug mode enabled.
 */

namespace local_lmshomepage\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

class get_completion_report extends \external_api {

    /** Build a sentinel error row visible in the WS response without debug mode. */
    private static function err(string $step, string $msg): array {
        return [[
            'userid'                        => -1,
            'fullname'                      => 'FAIL:' . $step . ':' . substr($msg, 0, 250),
            'idnumber'                      => '',
            'email'                         => '',
            'group_name'                    => '',
            'trainer_name'                  => '',
            'enrol_date'                    => 0,
            'course_completed'              => 0,
            'course_initial_completed_date' => 0,
            'activity_completions'          => [],
        ]];
    }

    public static function execute_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID', VALUE_REQUIRED),
            'group_id' => new \external_value(PARAM_INT, 'Group filter (0=all)', VALUE_DEFAULT, 0),
            'trainer_userid' => new \external_value(PARAM_INT, 'Trainer filter (0=all)', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $courseid, int $group_id = 0, int $trainer_userid = 0): array {
        global $DB;

        if ($courseid <= 1) {
            return [];
        }

        // ── 1. Course context ──────────────────────────────────────────────
        try {
            $ctx = $DB->get_record_sql(
                "SELECT id FROM {context} WHERE contextlevel = 50 AND instanceid = :cid LIMIT 1",
                ['cid' => $courseid]
            );
        } catch (\dml_exception $e) {
            return self::err('STEP1_CONTEXT', $e->getMessage());
        } catch (\Throwable $e) {
            return self::err('STEP1_CONTEXT_PHP', $e->getMessage());
        }
        if (!$ctx) {
            return [];
        }
        $contextid = (int) $ctx->id;

        // ── 2. Enrolled students ───────────────────────────────────────────
        // Split into two simple queries to eliminate any GROUP BY / subquery
        // issue: first get distinct student IDs, then get their enrol dates.
        //
        // Query 2a: all non-teacher enrolled student IDs (no aggregate, no subquery)
        try {
            $teacherIds = [];
            $teacherRows = $DB->get_records_sql("
                SELECT DISTINCT ra2.userid
                  FROM {role_assignments} ra2
                  JOIN {role} r2 ON r2.id = ra2.roleid
                 WHERE r2.shortname IN ('manager','coursecreator','editingteacher','teacher')
                   AND ra2.contextid = :ctxid
            ", ['ctxid' => $contextid]);
            foreach ($teacherRows as $tr) {
                $teacherIds[(int)$tr->userid] = true;
            }
        } catch (\dml_exception $e) {
            return self::err('STEP2A_TEACHERS', $e->getMessage());
        } catch (\Throwable $e) {
            return self::err('STEP2A_TEACHERS_PHP', $e->getMessage());
        }

        // Query 2b: enrolled users with their enrol dates
        try {
            $enrolRows = $DB->get_records_sql("
                SELECT u.id,
                       u.firstname,
                       u.lastname,
                       COALESCE(u.idnumber, '') AS idnumber,
                       COALESCE(u.email,    '') AS email,
                       ue.timestart,
                       ue.timecreated
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid AND e.courseid = :courseid
                  JOIN {user}  u ON u.id = ue.userid
                               AND u.deleted   = 0
                               AND u.suspended = 0
                               AND u.id        != 1
                               AND u.username  != 'guest'
                 WHERE ue.status = 0
                   AND (ue.timeend = 0 OR ue.timeend > :now)
                 ORDER BY u.lastname, u.firstname
            ", ['courseid' => $courseid, 'now' => time()]);
        } catch (\dml_exception $e) {
            return self::err('STEP2B_ENROL', $e->getMessage());
        } catch (\Throwable $e) {
            return self::err('STEP2B_ENROL_PHP', $e->getMessage());
        }

        // Build studentRows: deduplicate by userid, take minimum enrol date
        $studentRows = [];
        foreach ($enrolRows as $r) {
            $uid = (int) $r->id;
            if (isset($teacherIds[$uid])) {
                continue;  // skip teachers
            }
            $date = (int)$r->timestart > 0 ? (int)$r->timestart : (int)$r->timecreated;
            if (!isset($studentRows[$uid])) {
                $studentRows[$uid] = [
                    'firstname'  => (string) $r->firstname,
                    'lastname'   => (string) $r->lastname,
                    'idnumber'   => (string) $r->idnumber,
                    'email'      => (string) $r->email,
                    'enrol_date' => $date,
                ];
            } else {
                // Keep earliest non-zero date
                if ($date > 0 && ($studentRows[$uid]['enrol_date'] === 0 || $date < $studentRows[$uid]['enrol_date'])) {
                    $studentRows[$uid]['enrol_date'] = $date;
                }
            }
        }

        if (empty($studentRows)) {
            return [];
        }

        $allStudentIds = array_keys($studentRows);

        // ── 3. Activity definitions via direct SQL ─────────────────────────
        $actDefs = [];

        try {
            $cmRows = $DB->get_records_sql("
                SELECT cm.id       AS cmid,
                       m.name      AS modname,
                       cm.instance AS instance
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                 WHERE cm.course     = :courseid
                   AND cm.completion > 0
                 ORDER BY cm.section, cm.id
            ", ['courseid' => $courseid]);
        } catch (\dml_exception $e) {
            return self::err('STEP3_CM_LIST', $e->getMessage());
        } catch (\Throwable $e) {
            return self::err('STEP3_CM_LIST_PHP', $e->getMessage());
        }

        $byModname = [];
        foreach ($cmRows as $cm) {
            $byModname[(string)$cm->modname][(int)$cm->instance] = (int)$cm->cmid;
        }

        foreach ($byModname as $modname => $instanceMap) {
            if (empty($instanceMap) || !preg_match('/^[a-z][a-z0-9_]*$/', $modname)) {
                continue;
            }
            try {
                [$insqlM, $inparamsM] = $DB->get_in_or_equal(
                    array_keys($instanceMap), SQL_PARAMS_NAMED, 'minst'
                );
                $nameRows = $DB->get_records_sql(
                    "SELECT id, name FROM {{$modname}} WHERE id $insqlM",
                    $inparamsM
                );
                foreach ($nameRows as $nr) {
                    $cmid = $instanceMap[(int)$nr->id] ?? null;
                    if ($cmid !== null) {
                        $actDefs[$cmid] = ['name' => (string)$nr->name, 'modname' => $modname];
                    }
                }
            } catch (\Throwable $e) {
                // Module table absent — use placeholder
                foreach ($instanceMap as $instId => $cmid) {
                    $actDefs[$cmid] = ['name' => $modname . '_' . $instId, 'modname' => $modname];
                }
            }
        }

        // ── 3b. Restrict to course-completion-criteria activities ──────────
        // The report must list ONLY activities that are ticked under the
        // course's "Course completion" settings — i.e. rows in
        // course_completion_criteria with criteriatype = 4
        // (COMPLETION_CRITERIA_TYPE_ACTIVITY).  Any completion-tracked activity
        // that is NOT a course-completion criterion (a forum, a practice quiz,
        // etc. that merely has its own activity-completion rule) is dropped.
        //
        // moduleinstance stores the course-module id (cm.id) for activity
        // criteria.  As a defensive measure — in case a site stored the module
        // instance id instead — an unmatched moduleinstance is also resolved
        // through $byModname[module][instance] → cmid.
        //
        // Fallback policy (v2.11.34):
        //   • query succeeds, ≥1 criterion → keep ONLY those activities
        //   • query succeeds, 0 criteria   → keep NO activity columns
        //        (an un-ticked activity must never appear — per requirement).
        //        Previous behaviour showed ALL completion-tracked activities
        //        here, which is why non-criteria forums/quizzes leaked in.
        //   • query THROWS (table missing / inaccessible) → keep all actDefs
        //        (an infrastructure fault, not a configuration statement).
        $criteriaRows = null;
        try {
            // Select id first so get_records_sql keys on the unique criteria row
            // id (never collides), not on moduleinstance.
            $criteriaRows = $DB->get_records_sql("
                SELECT id,
                       module         AS modname,
                       moduleinstance AS moduleinstance
                  FROM {course_completion_criteria}
                 WHERE course       = :courseid
                   AND criteriatype = 4
            ", ['courseid' => $courseid]);
        } catch (\Throwable $e) {
            $criteriaRows = null; // leave actDefs unfiltered — see fallback policy
        }

        if (is_array($criteriaRows)) {
            $criteriaCmids = [];
            foreach ($criteriaRows as $cr) {
                $mi = (int)$cr->moduleinstance;
                if (isset($actDefs[$mi])) {
                    $criteriaCmids[$mi] = true;                // moduleinstance = cmid (standard)
                } else {
                    $modname = (string)$cr->modname;
                    $cmid = $byModname[$modname][$mi] ?? null; // moduleinstance = module instance id
                    if ($cmid !== null) {
                        $criteriaCmids[$cmid] = true;
                    }
                }
            }
            // Strict: only criteria activities survive (empty set → no columns).
            $actDefs = array_filter(
                $actDefs,
                static fn($cmid) => isset($criteriaCmids[$cmid]),
                ARRAY_FILTER_USE_KEY
            );
        }

        // ── 4. Groups ──────────────────────────────────────────────────────
        try {
            $groupRows = $DB->get_records_sql("
                SELECT gm.userid,
                       g.id   AS group_id,
                       g.name AS group_name
                  FROM {groups}         g
                  JOIN {groups_members} gm ON gm.groupid = g.id
                 WHERE g.courseid = :courseid
                 ORDER BY g.name
            ", ['courseid' => $courseid]);
        } catch (\dml_exception $e) {
            return self::err('STEP4_GROUPS', $e->getMessage());
        } catch (\Throwable $e) {
            return self::err('STEP4_GROUPS_PHP', $e->getMessage());
        }

        $studentGroupId   = [];
        $studentGroupName = [];
        foreach ($groupRows as $r) {
            $uid = (int)$r->userid;
            if (!isset($studentGroupId[$uid])) {
                $studentGroupId[$uid]   = (int)$r->group_id;
                $studentGroupName[$uid] = (string)$r->group_name;
            }
        }

        // ── 5. Trainers per group ──────────────────────────────────────────
        $trainerByGroup   = [];
        $trainerIdByGroup = [];
        try {
            $trainerRows = $DB->get_records_sql("
                SELECT DISTINCT gm.groupid,
                       u.id                              AS trainer_id,
                       u.lastname                        AS trainer_lastname,
                       u.firstname                       AS trainer_firstname,
                       u.firstname || ' ' || u.lastname  AS trainer_name
                  FROM {role_assignments} ra
                  JOIN {role}           r   ON r.id = ra.roleid
                                          AND r.shortname IN ('editingteacher','teacher')
                  JOIN {user}           u   ON u.id = ra.userid AND u.deleted = 0
                  JOIN {groups_members} gm  ON gm.userid = u.id
                  JOIN {groups}         g   ON g.id = gm.groupid AND g.courseid = :courseid
                 WHERE ra.contextid = :ctxid
                 ORDER BY gm.groupid, u.lastname, u.firstname
            ", ['courseid' => $courseid, 'ctxid' => $contextid]);
        } catch (\dml_exception $e) {
            return self::err('STEP5_TRAINERS', $e->getMessage());
        } catch (\Throwable $e) {
            return self::err('STEP5_TRAINERS_PHP', $e->getMessage());
        }
        foreach ($trainerRows as $r) {
            $gid = (int)$r->groupid;
            if (!isset($trainerByGroup[$gid])) {
                $trainerByGroup[$gid]   = (string)$r->trainer_name;
                $trainerIdByGroup[$gid] = (int)$r->trainer_id;
            }
        }

        // ── 6 & 7. Group / trainer filters ────────────────────────────────
        if ($group_id > 0) {
            $allStudentIds = array_values(array_filter(
                $allStudentIds, fn($uid) => ($studentGroupId[$uid] ?? 0) === $group_id
            ));
            if (empty($allStudentIds)) return [];
        }
        if ($trainer_userid > 0) {
            $trainerGroups = array_keys(array_filter($trainerIdByGroup, fn($tid) => $tid === $trainer_userid));
            if (empty($trainerGroups)) return [];
            $allStudentIds = array_values(array_filter(
                $allStudentIds, fn($uid) => in_array($studentGroupId[$uid] ?? -1, $trainerGroups, true)
            ));
            if (empty($allStudentIds)) return [];
        }

        // ── 8. Activity completions ────────────────────────────────────────
        // Query only course_modules_completion (no JOIN) — join conditions on
        // course and completion>0 were causing dml_read_exception on this
        // PostgreSQL server.  Filter to completion-tracked CMs in PHP instead.
        // Uses timemodified (present in all Moodle versions) as the date field
        // since timecompleted was added in Moodle 3.11 and may be absent.
        //
        // IMPORTANT: get_recordset_sql() is used here (not get_records_sql).
        // get_records_sql() keys rows by the first column value — with userid
        // as first column, only ONE completion record per student would survive
        // when a student has completions across multiple activities.
        $actCompMap = [];
        if (!empty($allStudentIds)) {
            // 8a: use recordset so every (userid, cmid) row is processed
            try {
                [$insql, $inparams] = $DB->get_in_or_equal($allStudentIds, SQL_PARAMS_NAMED, 'uid');
                $actCompRs = $DB->get_recordset_sql("
                    SELECT cmc.userid,
                           cmc.coursemoduleid              AS cmid,
                           cmc.completionstate             AS state,
                           COALESCE(cmc.timemodified,   0) AS initial_completed_date
                      FROM {course_modules_completion} cmc
                     WHERE cmc.userid $insql
                       AND cmc.completionstate > 0
                ", $inparams);
            } catch (\dml_exception $e) {
                return self::err('STEP8A_ACT_COMP_SIMPLE', $e->getMessage());
            } catch (\Throwable $e) {
                return self::err('STEP8A_ACT_COMP_SIMPLE_PHP', $e->getMessage());
            }

            // Build map: userid => cmid => {state, initial_completed_date}
            // Only keep rows whose cmid is a tracked activity (from $actDefs)
            $trackedCmids = array_keys($actDefs);
            foreach ($actCompRs as $r) {
                $uid  = (int)$r->userid;
                $cmid = (int)$r->cmid;
                if (empty($trackedCmids) || in_array($cmid, $trackedCmids, true)) {
                    $actCompMap[$uid][$cmid] = [
                        'state'                  => (int)$r->state,
                        'initial_completed_date' => (int)$r->initial_completed_date,
                    ];
                }
            }
            $actCompRs->close();
        }

        // ── 8b. Gradebook fallback — ALL graded criteria activities ────────
        // v2.11.33: generalised from quiz-only to every graded module.
        //
        // Students who completed a graded activity BEFORE activity-completion
        // tracking was enabled have no mdl_course_modules_completion record
        // (state=0 above) even though their grade exists in mdl_grade_grades.
        // The previous version only rescued quizzes (component='mod_quiz'), so
        // assignments, SCORM packages, workshops, lessons and H5P activities
        // that were completion criteria still rendered blank for those students.
        //
        // This version matches EVERY activity grade item (itemtype='mod') back
        // to its cmid via (itemmodule, iteminstance) → $byModname, then derives
        // pass/fail from the grade:
        //   gradepass > 0, finalgrade >= gradepass → state 2 (Pass)
        //   gradepass > 0, finalgrade <  gradepass → state 3 (Not Passed)
        //   gradepass = 0, finalgrade > 0          → state 1 (Complete)
        //   finalgrade not set                     → leave as state 0
        //
        // v2.11.36: when the activity has a "grade to pass" threshold, the grade
        // is AUTHORITATIVE — a plain "Complete" (state 1) record from Step 8a is
        // upgraded to Passed/Not Passed so quizzes read the same as assignments.
        // (A quiz whose completion rule is "mark complete on submit" stores
        // completionstate=1; without this step it shows "Complete" while an
        // assignment with "require passing grade" shows Passed/Not Passed.)
        // A native Pass/Fail (2/3) is never changed; an activity with no pass
        // threshold keeps its existing completion state.
        if (!empty($allStudentIds)) {
            // Instance IDs of every criteria activity that has a module instance.
            // Keyed set avoids duplicate IDs across modules in the IN() filter.
            $critInstSet = [];
            foreach ($byModname as $modname => $instanceMap) {
                foreach ($instanceMap as $instId => $cmid) {
                    if (isset($actDefs[$cmid])) {
                        $critInstSet[(int)$instId] = true;
                    }
                }
            }
            $critInst = array_keys($critInstSet);

            if (!empty($critInst)) {
                // IMPORTANT: get_recordset_sql() (not get_records_sql) — the latter
                // keys rows by the first column, collapsing every student's grade
                // to a single row per activity.  Recordset iterates all rows.
                //
                // itemtype='mod' limits to activity grade items; iteminstance is
                // filtered to criteria instances.  A cross-module instance-id clash
                // (e.g. quiz 5 vs assign 5) is resolved safely in PHP below because
                // the (itemmodule, iteminstance) pair is matched against $byModname.
                // ORDER BY itemnumber prefers the primary grade item for modules
                // that create several (e.g. workshop: submission + assessment).
                try {
                    [$iInsql,  $iInparams]  = $DB->get_in_or_equal($critInst,      SQL_PARAMS_NAMED, 'ginst');
                    [$guInsql, $guInparams] = $DB->get_in_or_equal($allStudentIds, SQL_PARAMS_NAMED, 'guid');
                    $gradeRs = $DB->get_recordset_sql("
                        SELECT gi.id                            AS gradeitemid,
                               gi.itemmodule                    AS modname,
                               gi.iteminstance                  AS instance,
                               COALESCE(gi.gradepass, 0)        AS gradepass,
                               gg.userid,
                               gg.finalgrade,
                               COALESCE(gg.timemodified, 0)     AS grade_date
                          FROM {grade_items}  gi
                          JOIN {grade_grades} gg ON gg.itemid = gi.id
                         WHERE gi.courseid    = :gcourseid
                           AND gi.itemtype    = 'mod'
                           AND gi.iteminstance $iInsql
                           AND gg.userid       $guInsql
                           AND gg.finalgrade  IS NOT NULL
                         ORDER BY gi.itemnumber ASC
                    ", array_merge(['gcourseid' => $courseid], $iInparams, $guInparams));
                } catch (\Throwable $e) {
                    $gradeRs = null; // degrade gracefully — step 8a data still shown
                }

                if ($gradeRs !== null) {
                    foreach ($gradeRs as $gr) {
                        $modname  = (string)$gr->modname;
                        $instance = (int)$gr->instance;
                        // Resolve this grade item back to a course-module id.
                        $cmid = $byModname[$modname][$instance] ?? null;
                        if ($cmid === null || !isset($actDefs[$cmid])) {
                            continue;                 // not a criteria activity column
                        }
                        $uid   = (int)$gr->userid;
                        $grade = (float)$gr->finalgrade;
                        $pass  = (float)$gr->gradepass;

                        $existingState = $actCompMap[$uid][$cmid]['state'] ?? 0;
                        $existingDate  = $actCompMap[$uid][$cmid]['initial_completed_date'] ?? 0;

                        if ($pass > 0) {
                            // "Grade to pass" is set → grade decides pass/fail.
                            // Upgrade a missing (0) or plain Complete (1) record to
                            // Passed/Not Passed; never touch a native Pass/Fail (2/3).
                            if ($existingState === 0 || $existingState === 1) {
                                $actCompMap[$uid][$cmid] = [
                                    'state'                  => ($grade >= $pass) ? 2 : 3,
                                    // Keep the real activity-completion date if one
                                    // already existed; otherwise use the grade date.
                                    'initial_completed_date' => $existingDate ?: (int)$gr->grade_date,
                                ];
                            }
                        } else {
                            // No pass threshold — pass/fail can't be derived.
                            // Only fill a genuine gap (no record) as Complete.
                            if ($existingState === 0 && $grade > 0) {
                                $actCompMap[$uid][$cmid] = [
                                    'state'                  => 1,
                                    'initial_completed_date' => (int)$gr->grade_date,
                                ];
                            }
                        }
                    }
                    $gradeRs->close();
                }
            }
        }

        // ── 9. Course completions ──────────────────────────────────────────
        // course_completions columns: id, userid, course, timeenrolled,
        // timestarted, timecompleted, reaggregate — NO timemodified column.
        // Filter timecompleted IS NOT NULL in WHERE so COALESCE(,0) is safe.
        try {
            [$insql2, $inparams2] = $DB->get_in_or_equal($allStudentIds, SQL_PARAMS_NAMED, 'cid');
            $courseCompRows = $DB->get_records_sql("
                SELECT userid,
                       COALESCE(timecompleted, 0) AS course_initial_completed_date
                  FROM {course_completions}
                 WHERE course = :courseid
                   AND timecompleted IS NOT NULL
                   AND userid $insql2
            ", array_merge(['courseid' => $courseid], $inparams2));
        } catch (\dml_exception $e) {
            return self::err('STEP9_COURSE_COMP', $e->getMessage());
        } catch (\Throwable $e) {
            return self::err('STEP9_COURSE_COMP_PHP', $e->getMessage());
        }

        $courseCompMap = [];
        foreach ($courseCompRows as $r) {
            $courseCompMap[(int)$r->userid] = (int)$r->course_initial_completed_date;
        }

        // ── 10. Assemble ───────────────────────────────────────────────────
        $result = [];
        foreach ($allStudentIds as $uid) {
            $s     = $studentRows[$uid];
            $gid   = $studentGroupId[$uid]  ?? 0;
            $gname = $studentGroupName[$uid] ?? '';
            $trainer = $gid > 0 ? ($trainerByGroup[$gid] ?? '') : '';

            $myActComp = $actCompMap[$uid] ?? [];
            $actList   = [];
            foreach ($actDefs as $cmid => $def) {
                $comp     = $myActComp[$cmid] ?? null;
                $actList[] = [
                    'cmid'                   => $cmid,
                    'name'                   => $def['name'],
                    'modname'                => $def['modname'],
                    'state'                  => $comp ? $comp['state'] : 0,
                    'initial_completed_date' => $comp ? $comp['initial_completed_date'] : 0,
                ];
            }

            $result[] = [
                'userid'                        => (int) $uid,
                'fullname'                      => trim($s['firstname'] . ' ' . $s['lastname']),
                'idnumber'                      => $s['idnumber'],
                'email'                         => $s['email'],
                'group_name'                    => $gname,
                'trainer_name'                  => $trainer,
                'enrol_date'                    => $s['enrol_date'],
                'course_completed'              => isset($courseCompMap[$uid]) ? 1 : 0,
                'course_initial_completed_date' => $courseCompMap[$uid] ?? 0,
                'activity_completions'          => $actList,
            ];
        }

        return $result;
    }

    public static function execute_returns(): \external_multiple_structure {
        return new \external_multiple_structure(
            new \external_single_structure([
                'userid'       => new \external_value(PARAM_INT,  'Student user ID'),
                'fullname'     => new \external_value(PARAM_TEXT, 'Student full name (or FAIL:<step>:<msg> for debug)'),
                'idnumber'     => new \external_value(PARAM_TEXT, 'Student ID number'),
                'email'        => new \external_value(PARAM_TEXT, 'Student email'),
                'group_name'   => new \external_value(PARAM_TEXT, 'Group name'),
                'trainer_name' => new \external_value(PARAM_TEXT, 'Trainer name'),
                'enrol_date'   => new \external_value(PARAM_INT,  'Enrolment date (unix ts)'),
                'course_completed'              => new \external_value(PARAM_INT, '1=completed'),
                'course_initial_completed_date' => new \external_value(PARAM_INT, 'Course completion date (unix ts)'),
                'activity_completions' => new \external_multiple_structure(
                    new \external_single_structure([
                        'cmid'                   => new \external_value(PARAM_INT,  'CM ID'),
                        'name'                   => new \external_value(PARAM_TEXT, 'Activity name'),
                        'modname'                => new \external_value(PARAM_TEXT, 'Module type'),
                        'state'                  => new \external_value(PARAM_INT,  'Completion state 0-3'),
                        'initial_completed_date' => new \external_value(PARAM_INT,  'First completion date'),
                    ])
                ),
            ])
        );
    }
}
