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
 * Library of functions for the quizp module.
 *
 * This contains functions that are called also from outside the quizp module
 * Functions that are only called by the quizp module itself are in {@link locallib.php}
 *
 * @package    mod_quizp
 * @copyright  1999 onwards Martin Dougiamas {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->dirroot . '/calendar/lib.php');


/**#@+
 * Option controlling what options are offered on the quizp settings form.
 */
define('QUIZP_MAX_ATTEMPT_OPTION', 10);
define('QUIZP_MAX_QPP_OPTION', 50);
define('QUIZP_MAX_DECIMAL_OPTION', 5);
define('QUIZP_MAX_Q_DECIMAL_OPTION', 7);
/**#@-*/

/**#@+
 * Options determining how the grades from individual attempts are combined to give
 * the overall grade for a user
 */
define('QUIZP_GRADEHIGHEST', '1');
define('QUIZP_GRADEAVERAGE', '2');
define('QUIZP_ATTEMPTFIRST', '3');
define('QUIZP_ATTEMPTLAST',  '4');
/**#@-*/

/**
 * @var int If start and end date for the quizp are more than this many seconds apart
 * they will be represented by two separate events in the calendar
 */
define('QUIZP_MAX_EVENT_LENGTH', 5*24*60*60); // 5 days.

/**#@+
 * Options for navigation method within quizpzes.
 */
define('QUIZP_NAVMETHOD_FREE', 'free');
define('QUIZP_NAVMETHOD_SEQ',  'sequential');
/**#@-*/

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will create a new instance and return the id number
 * of the new instance.
 *
 * @param object $quizp the data that came from the form.
 * @return mixed the id of the new instance on success,
 *          false or a string error message on failure.
 */
function quizp_add_instance($quizp) {
    global $DB;
    $cmid = $quizp->coursemodule;

    // Process the options from the form.
    $quizp->created = time();
    $result = quizp_process_options($quizp);
    if ($result && is_string($result)) {
        return $result;
    }

    // Try to store it in the database.
    $quizp->id = $DB->insert_record('quizp', $quizp);

    // Create the first section for this quizp.
    $DB->insert_record('quizp_sections', array('quizpid' => $quizp->id,
            'firstslot' => 1, 'heading' => '', 'shufflequestions' => 0));

    // Do the processing required after an add or an update.
    quizp_after_add_or_update($quizp);

    return $quizp->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod_form.php) this function
 * will update an existing instance with new data.
 *
 * @param object $quizp the data that came from the form.
 * @return mixed true on success, false or a string error message on failure.
 */
function quizp_update_instance($quizp, $mform) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');

    // Process the options from the form.
    $result = quizp_process_options($quizp);
    if ($result && is_string($result)) {
        return $result;
    }

    // Get the current value, so we can see what changed.
    $oldquizp = $DB->get_record('quizp', array('id' => $quizp->instance));

    // We need two values from the existing DB record that are not in the form,
    // in some of the function calls below.
    $quizp->sumgrades = $oldquizp->sumgrades;
    $quizp->grade     = $oldquizp->grade;

    // Update the database.
    $quizp->id = $quizp->instance;
    $DB->update_record('quizp', $quizp);

    // Do the processing required after an add or an update.
    quizp_after_add_or_update($quizp);

    if ($oldquizp->grademethod != $quizp->grademethod) {
        quizp_update_all_final_grades($quizp);
        quizp_update_grades($quizp);
    }

    $quizpdateschanged = $oldquizp->timelimit   != $quizp->timelimit
                     || $oldquizp->timeclose   != $quizp->timeclose
                     || $oldquizp->graceperiod != $quizp->graceperiod;
    if ($quizpdateschanged) {
        quizp_update_open_attempts(array('quizpid' => $quizp->id));
    }

    // Delete any previous preview attempts.
    quizp_delete_previews($quizp);

    // Repaginate, if asked to.
    if (!empty($quizp->repaginatenow)) {
        quizp_repaginate_questions($quizp->id, $quizp->questionsperpage);
    }

    return true;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param int $id the id of the quizp to delete.
 * @return bool success or failure.
 */
function quizp_delete_instance($id) {
    global $DB;

    $quizp = $DB->get_record('quizp', array('id' => $id), '*', MUST_EXIST);

    quizp_delete_all_attempts($quizp);
    quizp_delete_all_overrides($quizp);

    // Look for random questions that may no longer be used when this quizp is gone.
    $sql = "SELECT q.id
              FROM {quizp_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.quizpid = ? AND q.qtype = ?";
    $questionids = $DB->get_fieldset_sql($sql, array($quizp->id, 'random'));

    // We need to do this before we try and delete randoms, otherwise they would still be 'in use'.
    $DB->delete_records('quizp_slots', array('quizpid' => $quizp->id));
    $DB->delete_records('quizp_sections', array('quizpid' => $quizp->id));

    foreach ($questionids as $questionid) {
        question_delete_question($questionid);
    }

    $DB->delete_records('quizp_feedback', array('quizpid' => $quizp->id));

    quizp_access_manager::delete_settings($quizp);

    $events = $DB->get_records('event', array('modulename' => 'quizp', 'instance' => $quizp->id));
    foreach ($events as $event) {
        $event = calendar_event::load($event);
        $event->delete();
    }

    quizp_grade_item_delete($quizp);
    $DB->delete_records('quizp', array('id' => $quizp->id));

    return true;
}

/**
 * Deletes a quizp override from the database and clears any corresponding calendar events
 *
 * @param object $quizp The quizp object.
 * @param int $overrideid The id of the override being deleted
 * @return bool true on success
 */
function quizp_delete_override($quizp, $overrideid) {
    global $DB;

    if (!isset($quizp->cmid)) {
        $cm = get_coursemodule_from_instance('quizp', $quizp->id, $quizp->course);
        $quizp->cmid = $cm->id;
    }

    $override = $DB->get_record('quizp_overrides', array('id' => $overrideid), '*', MUST_EXIST);

    // Delete the events.
    $events = $DB->get_records('event', array('modulename' => 'quizp',
            'instance' => $quizp->id, 'groupid' => (int)$override->groupid,
            'userid' => (int)$override->userid));
    foreach ($events as $event) {
        $eventold = calendar_event::load($event);
        $eventold->delete();
    }

    $DB->delete_records('quizp_overrides', array('id' => $overrideid));

    // Set the common parameters for one of the events we will be triggering.
    $params = array(
        'objectid' => $override->id,
        'context' => context_module::instance($quizp->cmid),
        'other' => array(
            'quizpid' => $override->quizp
        )
    );
    // Determine which override deleted event to fire.
    if (!empty($override->userid)) {
        $params['relateduserid'] = $override->userid;
        $event = \mod_quizp\event\user_override_deleted::create($params);
    } else {
        $params['other']['groupid'] = $override->groupid;
        $event = \mod_quizp\event\group_override_deleted::create($params);
    }

    // Trigger the override deleted event.
    $event->add_record_snapshot('quizp_overrides', $override);
    $event->trigger();

    return true;
}

/**
 * Deletes all quizp overrides from the database and clears any corresponding calendar events
 *
 * @param object $quizp The quizp object.
 */
function quizp_delete_all_overrides($quizp) {
    global $DB;

    $overrides = $DB->get_records('quizp_overrides', array('quizp' => $quizp->id), 'id');
    foreach ($overrides as $override) {
        quizp_delete_override($quizp, $override->id);
    }
}

/**
 * Updates a quizp object with override information for a user.
 *
 * Algorithm:  For each quizp setting, if there is a matching user-specific override,
 *   then use that otherwise, if there are group-specific overrides, return the most
 *   lenient combination of them.  If neither applies, leave the quizp setting unchanged.
 *
 *   Special case: if there is more than one password that applies to the user, then
 *   quizp->extrapasswords will contain an array of strings giving the remaining
 *   passwords.
 *
 * @param object $quizp The quizp object.
 * @param int $userid The userid.
 * @return object $quizp The updated quizp object.
 */
function quizp_update_effective_access($quizp, $userid) {
    global $DB;

    // Check for user override.
    $override = $DB->get_record('quizp_overrides', array('quizp' => $quizp->id, 'userid' => $userid));

    if (!$override) {
        $override = new stdClass();
        $override->timeopen = null;
        $override->timeclose = null;
        $override->timelimit = null;
        $override->attempts = null;
        $override->password = null;
    }

    // Check for group overrides.
    $groupings = groups_get_user_groups($quizp->course, $userid);

    if (!empty($groupings[0])) {
        // Select all overrides that apply to the User's groups.
        list($extra, $params) = $DB->get_in_or_equal(array_values($groupings[0]));
        $sql = "SELECT * FROM {quizp_overrides}
                WHERE groupid $extra AND quizp = ?";
        $params[] = $quizp->id;
        $records = $DB->get_records_sql($sql, $params);

        // Combine the overrides.
        $opens = array();
        $closes = array();
        $limits = array();
        $attempts = array();
        $passwords = array();

        foreach ($records as $gpoverride) {
            if (isset($gpoverride->timeopen)) {
                $opens[] = $gpoverride->timeopen;
            }
            if (isset($gpoverride->timeclose)) {
                $closes[] = $gpoverride->timeclose;
            }
            if (isset($gpoverride->timelimit)) {
                $limits[] = $gpoverride->timelimit;
            }
            if (isset($gpoverride->attempts)) {
                $attempts[] = $gpoverride->attempts;
            }
            if (isset($gpoverride->password)) {
                $passwords[] = $gpoverride->password;
            }
        }
        // If there is a user override for a setting, ignore the group override.
        if (is_null($override->timeopen) && count($opens)) {
            $override->timeopen = min($opens);
        }
        if (is_null($override->timeclose) && count($closes)) {
            if (in_array(0, $closes)) {
                $override->timeclose = 0;
            } else {
                $override->timeclose = max($closes);
            }
        }
        if (is_null($override->timelimit) && count($limits)) {
            if (in_array(0, $limits)) {
                $override->timelimit = 0;
            } else {
                $override->timelimit = max($limits);
            }
        }
        if (is_null($override->attempts) && count($attempts)) {
            if (in_array(0, $attempts)) {
                $override->attempts = 0;
            } else {
                $override->attempts = max($attempts);
            }
        }
        if (is_null($override->password) && count($passwords)) {
            $override->password = array_shift($passwords);
            if (count($passwords)) {
                $override->extrapasswords = $passwords;
            }
        }

    }

    // Merge with quizp defaults.
    $keys = array('timeopen', 'timeclose', 'timelimit', 'attempts', 'password', 'extrapasswords');
    foreach ($keys as $key) {
        if (isset($override->{$key})) {
            $quizp->{$key} = $override->{$key};
        }
    }

    return $quizp;
}

/**
 * Delete all the attempts belonging to a quizp.
 *
 * @param object $quizp The quizp object.
 */
function quizp_delete_all_attempts($quizp) {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');
    question_engine::delete_questions_usage_by_activities(new qubaids_for_quizp($quizp->id));
    $DB->delete_records('quizp_attempts', array('quizp' => $quizp->id));
    $DB->delete_records('quizp_grades', array('quizp' => $quizp->id));
}

/**
 * Get the best current grade for a particular user in a quizp.
 *
 * @param object $quizp the quizp settings.
 * @param int $userid the id of the user.
 * @return float the user's current grade for this quizp, or null if this user does
 * not have a grade on this quizp.
 */
function quizp_get_best_grade($quizp, $userid) {
    global $DB;
    $grade = $DB->get_field('quizp_grades', 'grade',
            array('quizp' => $quizp->id, 'userid' => $userid));

    // Need to detect errors/no result, without catching 0 grades.
    if ($grade === false) {
        return null;
    }

    return $grade + 0; // Convert to number.
}

/**
 * Is this a graded quizp? If this method returns true, you can assume that
 * $quizp->grade and $quizp->sumgrades are non-zero (for example, if you want to
 * divide by them).
 *
 * @param object $quizp a row from the quizp table.
 * @return bool whether this is a graded quizp.
 */
function quizp_has_grades($quizp) {
    return $quizp->grade >= 0.000005 && $quizp->sumgrades >= 0.000005;
}

/**
 * Does this quizp allow multiple tries?
 *
 * @return bool
 */
function quizp_allows_multiple_tries($quizp) {
    $bt = question_engine::get_behaviour_type($quizp->preferredbehaviour);
    return $bt->allows_multiple_submitted_responses();
}

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $quizp
 * @return object|null
 */
function quizp_user_outline($course, $user, $mod, $quizp) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    $grades = grade_get_grades($course->id, 'mod', 'quizp', $quizp->id, $user->id);

    if (empty($grades->items[0]->grades)) {
        return null;
    } else {
        $grade = reset($grades->items[0]->grades);
    }

    $result = new stdClass();
    $result->info = get_string('grade') . ': ' . $grade->str_long_grade;

    // Datesubmitted == time created. dategraded == time modified or time overridden
    // if grade was last modified by the user themselves use date graded. Otherwise use
    // date submitted.
    // TODO: move this copied & pasted code somewhere in the grades API. See MDL-26704.
    if ($grade->usermodified == $user->id || empty($grade->datesubmitted)) {
        $result->time = $grade->dategraded;
    } else {
        $result->time = $grade->datesubmitted;
    }

    return $result;
}

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param object $course
 * @param object $user
 * @param object $mod
 * @param object $quizp
 * @return bool
 */
function quizp_user_complete($course, $user, $mod, $quizp) {
    global $DB, $CFG, $OUTPUT;
    require_once($CFG->libdir . '/gradelib.php');
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');

    $grades = grade_get_grades($course->id, 'mod', 'quizp', $quizp->id, $user->id);
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        echo $OUTPUT->container(get_string('grade').': '.$grade->str_long_grade);
        if ($grade->str_feedback) {
            echo $OUTPUT->container(get_string('feedback').': '.$grade->str_feedback);
        }
    }

    if ($attempts = $DB->get_records('quizp_attempts',
            array('userid' => $user->id, 'quizp' => $quizp->id), 'attempt')) {
        foreach ($attempts as $attempt) {
            echo get_string('attempt', 'quizp', $attempt->attempt) . ': ';
            if ($attempt->state != quizp_attempt::FINISHED) {
                echo quizp_attempt_state_name($attempt->state);
            } else {
                echo quizp_format_grade($quizp, $attempt->sumgrades) . '/' .
                        quizp_format_grade($quizp, $quizp->sumgrades);
            }
            echo ' - '.userdate($attempt->timemodified).'<br />';
        }
    } else {
        print_string('noattempts', 'quizp');
    }

    return true;
}

/**
 * Quiz periodic clean-up tasks.
 */
function quizp_cron() {
    global $CFG;

    require_once($CFG->dirroot . '/mod/quizp/cronlib.php');
    mtrace('');

    $timenow = time();
    $overduehander = new mod_quizp_overdue_attempt_updater();

    $processto = $timenow - get_config('quizp', 'graceperiodmin');

    mtrace('  Looking for quizp overdue quizp attempts...');

    list($count, $quizpcount) = $overduehander->update_overdue_attempts($timenow, $processto);

    mtrace('  Considered ' . $count . ' attempts in ' . $quizpcount . ' quizpzes.');

    // Run cron for our sub-plugin types.
    cron_execute_plugin_type('quizp', 'quizp reports');
    cron_execute_plugin_type('quizpaccess', 'quizp access rules');

    return true;
}

/**
 * @param int $quizpid the quizp id.
 * @param int $userid the userid.
 * @param string $status 'all', 'finished' or 'unfinished' to control
 * @param bool $includepreviews
 * @return an array of all the user's attempts at this quizp. Returns an empty
 *      array if there are none.
 */
function quizp_get_user_attempts($quizpid, $userid, $status = 'finished', $includepreviews = false) {
    global $DB, $CFG;
    // TODO MDL-33071 it is very annoying to have to included all of locallib.php
    // just to get the quizp_attempt::FINISHED constants, but I will try to sort
    // that out properly for Moodle 2.4. For now, I will just do a quick fix for
    // MDL-33048.
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');

    $params = array();
    switch ($status) {
        case 'all':
            $statuscondition = '';
            break;

        case 'finished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = quizp_attempt::FINISHED;
            $params['state2'] = quizp_attempt::ABANDONED;
            break;

        case 'unfinished':
            $statuscondition = ' AND state IN (:state1, :state2)';
            $params['state1'] = quizp_attempt::IN_PROGRESS;
            $params['state2'] = quizp_attempt::OVERDUE;
            break;
    }

    $previewclause = '';
    if (!$includepreviews) {
        $previewclause = ' AND preview = 0';
    }

    $params['quizpid'] = $quizpid;
    $params['userid'] = $userid;
    return $DB->get_records_select('quizp_attempts',
            'quizp = :quizpid AND userid = :userid' . $previewclause . $statuscondition,
            $params, 'attempt ASC');
}

/**
 * Return grade for given user or all users.
 *
 * @param int $quizpid id of quizp
 * @param int $userid optional user id, 0 means all users
 * @return array array of grades, false if none. These are raw grades. They should
 * be processed with quizp_format_grade for display.
 */
function quizp_get_user_grades($quizp, $userid = 0) {
    global $CFG, $DB;

    $params = array($quizp->id);
    $usertest = '';
    if ($userid) {
        $params[] = $userid;
        $usertest = 'AND u.id = ?';
    }
    return $DB->get_records_sql("
            SELECT
                u.id,
                u.id AS userid,
                qg.grade AS rawgrade,
                qg.timemodified AS dategraded,
                MAX(qa.timefinish) AS datesubmitted

            FROM {user} u
            JOIN {quizp_grades} qg ON u.id = qg.userid
            JOIN {quizp_attempts} qa ON qa.quizp = qg.quizp AND qa.userid = u.id

            WHERE qg.quizp = ?
            $usertest
            GROUP BY u.id, qg.grade, qg.timemodified", $params);
}

/**
 * Round a grade to to the correct number of decimal places, and format it for display.
 *
 * @param object $quizp The quizp table row, only $quizp->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function quizp_format_grade($quizp, $grade) {
    if (is_null($grade)) {
        return get_string('notyetgraded', 'quizp');
    }
    return format_float($grade, $quizp->decimalpoints);
}

/**
 * Determine the correct number of decimal places required to format a grade.
 *
 * @param object $quizp The quizp table row, only $quizp->decimalpoints is used.
 * @return integer
 */
function quizp_get_grade_format($quizp) {
    if (empty($quizp->questiondecimalpoints)) {
        $quizp->questiondecimalpoints = -1;
    }

    if ($quizp->questiondecimalpoints == -1) {
        return $quizp->decimalpoints;
    }

    return $quizp->questiondecimalpoints;
}

/**
 * Round a grade to the correct number of decimal places, and format it for display.
 *
 * @param object $quizp The quizp table row, only $quizp->decimalpoints is used.
 * @param float $grade The grade to round.
 * @return float
 */
function quizp_format_question_grade($quizp, $grade) {
    return format_float($grade, quizp_get_grade_format($quizp));
}

/**
 * Update grades in central gradebook
 *
 * @category grade
 * @param object $quizp the quizp settings.
 * @param int $userid specific user only, 0 means all users.
 * @param bool $nullifnone If a single user is specified and $nullifnone is true a grade item with a null rawgrade will be inserted
 */
function quizp_update_grades($quizp, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    if ($quizp->grade == 0) {
        quizp_grade_item_update($quizp);

    } else if ($grades = quizp_get_user_grades($quizp, $userid)) {
        quizp_grade_item_update($quizp, $grades);

    } else if ($userid && $nullifnone) {
        $grade = new stdClass();
        $grade->userid = $userid;
        $grade->rawgrade = null;
        quizp_grade_item_update($quizp, $grade);

    } else {
        quizp_grade_item_update($quizp);
    }
}

/**
 * Create or update the grade item for given quizp
 *
 * @category grade
 * @param object $quizp object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function quizp_grade_item_update($quizp, $grades = null) {
    global $CFG, $OUTPUT;
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (array_key_exists('cmidnumber', $quizp)) { // May not be always present.
        $params = array('itemname' => $quizp->name, 'idnumber' => $quizp->cmidnumber);
    } else {
        $params = array('itemname' => $quizp->name);
    }

    if ($quizp->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $quizp->grade;
        $params['grademin']  = 0;

    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    // What this is trying to do:
    // 1. If the quizp is set to not show grades while the quizp is still open,
    //    and is set to show grades after the quizp is closed, then create the
    //    grade_item with a show-after date that is the quizp close date.
    // 2. If the quizp is set to not show grades at either of those times,
    //    create the grade_item as hidden.
    // 3. If the quizp is set to show grades, create the grade_item visible.
    $openreviewoptions = mod_quizp_display_options::make_from_quizp($quizp,
            mod_quizp_display_options::LATER_WHILE_OPEN);
    $closedreviewoptions = mod_quizp_display_options::make_from_quizp($quizp,
            mod_quizp_display_options::AFTER_CLOSE);
    if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks < question_display_options::MARK_AND_MAX) {
        $params['hidden'] = 1;

    } else if ($openreviewoptions->marks < question_display_options::MARK_AND_MAX &&
            $closedreviewoptions->marks >= question_display_options::MARK_AND_MAX) {
        if ($quizp->timeclose) {
            $params['hidden'] = $quizp->timeclose;
        } else {
            $params['hidden'] = 1;
        }

    } else {
        // Either
        // a) both open and closed enabled
        // b) open enabled, closed disabled - we can not "hide after",
        //    grades are kept visible even after closing.
        $params['hidden'] = 0;
    }

    if (!$params['hidden']) {
        // If the grade item is not hidden by the quizp logic, then we need to
        // hide it if the quizp is hidden from students.
        if (property_exists($quizp, 'visible')) {
            // Saving the quizp form, and cm not yet updated in the database.
            $params['hidden'] = !$quizp->visible;
        } else {
            $cm = get_coursemodule_from_instance('quizp', $quizp->id);
            $params['hidden'] = !$cm->visible;
        }
    }

    if ($grades  === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    $gradebook_grades = grade_get_grades($quizp->course, 'mod', 'quizp', $quizp->id);
    if (!empty($gradebook_grades->items)) {
        $grade_item = $gradebook_grades->items[0];
        if ($grade_item->locked) {
            // NOTE: this is an extremely nasty hack! It is not a bug if this confirmation fails badly. --skodak.
            $confirm_regrade = optional_param('confirm_regrade', 0, PARAM_INT);
            if (!$confirm_regrade) {
                if (!AJAX_SCRIPT) {
                    $message = get_string('gradeitemislocked', 'grades');
                    $back_link = $CFG->wwwroot . '/mod/quizp/report.php?q=' . $quizp->id .
                            '&amp;mode=overview';
                    $regrade_link = qualified_me() . '&amp;confirm_regrade=1';
                    echo $OUTPUT->box_start('generalbox', 'notice');
                    echo '<p>'. $message .'</p>';
                    echo $OUTPUT->container_start('buttons');
                    echo $OUTPUT->single_button($regrade_link, get_string('regradeanyway', 'grades'));
                    echo $OUTPUT->single_button($back_link,  get_string('cancel'));
                    echo $OUTPUT->container_end();
                    echo $OUTPUT->box_end();
                }
                return GRADE_UPDATE_ITEM_LOCKED;
            }
        }
    }

    return grade_update('mod/quizp', $quizp->course, 'mod', 'quizp', $quizp->id, 0, $grades, $params);
}

/**
 * Delete grade item for given quizp
 *
 * @category grade
 * @param object $quizp object
 * @return object quizp
 */
function quizp_grade_item_delete($quizp) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update('mod/quizp', $quizp->course, 'mod', 'quizp', $quizp->id, 0,
            null, array('deleted' => 1));
}

/**
 * This standard function will check all instances of this module
 * and make sure there are up-to-date events created for each of them.
 * If courseid = 0, then every quizp event in the site is checked, else
 * only quizp events belonging to the course specified are checked.
 * This function is used, in its new format, by restore_refresh_events()
 *
 * @param int $courseid
 * @return bool
 */
function quizp_refresh_events($courseid = 0) {
    global $DB;

    if ($courseid == 0) {
        if (!$quizpzes = $DB->get_records('quizp')) {
            return true;
        }
    } else {
        if (!$quizpzes = $DB->get_records('quizp', array('course' => $courseid))) {
            return true;
        }
    }

    foreach ($quizpzes as $quizp) {
        quizp_update_events($quizp);
    }

    return true;
}

/**
 * Returns all quizp graded users since a given time for specified quizp
 */
function quizp_get_recent_mod_activity(&$activities, &$index, $timestart,
        $courseid, $cmid, $userid = 0, $groupid = 0) {
    global $CFG, $USER, $DB;
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');

    $course = get_course($courseid);
    $modinfo = get_fast_modinfo($course);

    $cm = $modinfo->cms[$cmid];
    $quizp = $DB->get_record('quizp', array('id' => $cm->instance));

    if ($userid) {
        $userselect = "AND u.id = :userid";
        $params['userid'] = $userid;
    } else {
        $userselect = '';
    }

    if ($groupid) {
        $groupselect = 'AND gm.groupid = :groupid';
        $groupjoin   = 'JOIN {groups_members} gm ON  gm.userid=u.id';
        $params['groupid'] = $groupid;
    } else {
        $groupselect = '';
        $groupjoin   = '';
    }

    $params['timestart'] = $timestart;
    $params['quizpid'] = $quizp->id;

    $ufields = user_picture::fields('u', null, 'useridagain');
    if (!$attempts = $DB->get_records_sql("
              SELECT qa.*,
                     {$ufields}
                FROM {quizp_attempts} qa
                     JOIN {user} u ON u.id = qa.userid
                     $groupjoin
               WHERE qa.timefinish > :timestart
                 AND qa.quizp = :quizpid
                 AND qa.preview = 0
                     $userselect
                     $groupselect
            ORDER BY qa.timefinish ASC", $params)) {
        return;
    }

    $context         = context_module::instance($cm->id);
    $accessallgroups = has_capability('moodle/site:accessallgroups', $context);
    $viewfullnames   = has_capability('moodle/site:viewfullnames', $context);
    $grader          = has_capability('mod/quizp:viewreports', $context);
    $groupmode       = groups_get_activity_groupmode($cm, $course);

    $usersgroups = null;
    $aname = format_string($cm->name, true);
    foreach ($attempts as $attempt) {
        if ($attempt->userid != $USER->id) {
            if (!$grader) {
                // Grade permission required.
                continue;
            }

            if ($groupmode == SEPARATEGROUPS and !$accessallgroups) {
                $usersgroups = groups_get_all_groups($course->id,
                        $attempt->userid, $cm->groupingid);
                $usersgroups = array_keys($usersgroups);
                if (!array_intersect($usersgroups, $modinfo->get_groups($cm->groupingid))) {
                    continue;
                }
            }
        }

        $options = quizp_get_review_options($quizp, $attempt, $context);

        $tmpactivity = new stdClass();

        $tmpactivity->type       = 'quizp';
        $tmpactivity->cmid       = $cm->id;
        $tmpactivity->name       = $aname;
        $tmpactivity->sectionnum = $cm->sectionnum;
        $tmpactivity->timestamp  = $attempt->timefinish;

        $tmpactivity->content = new stdClass();
        $tmpactivity->content->attemptid = $attempt->id;
        $tmpactivity->content->attempt   = $attempt->attempt;
        if (quizp_has_grades($quizp) && $options->marks >= question_display_options::MARK_AND_MAX) {
            $tmpactivity->content->sumgrades = quizp_format_grade($quizp, $attempt->sumgrades);
            $tmpactivity->content->maxgrade  = quizp_format_grade($quizp, $quizp->sumgrades);
        } else {
            $tmpactivity->content->sumgrades = null;
            $tmpactivity->content->maxgrade  = null;
        }

        $tmpactivity->user = user_picture::unalias($attempt, null, 'useridagain');
        $tmpactivity->user->fullname  = fullname($tmpactivity->user, $viewfullnames);

        $activities[$index++] = $tmpactivity;
    }
}

function quizp_print_recent_mod_activity($activity, $courseid, $detail, $modnames) {
    global $CFG, $OUTPUT;

    echo '<table border="0" cellpadding="3" cellspacing="0" class="forum-recent">';

    echo '<tr><td class="userpicture" valign="top">';
    echo $OUTPUT->user_picture($activity->user, array('courseid' => $courseid));
    echo '</td><td>';

    if ($detail) {
        $modname = $modnames[$activity->type];
        echo '<div class="title">';
        echo '<img src="' . $OUTPUT->pix_url('icon', $activity->type) . '" ' .
                'class="icon" alt="' . $modname . '" />';
        echo '<a href="' . $CFG->wwwroot . '/mod/quizp/view.php?id=' .
                $activity->cmid . '">' . $activity->name . '</a>';
        echo '</div>';
    }

    echo '<div class="grade">';
    echo  get_string('attempt', 'quizp', $activity->content->attempt);
    if (isset($activity->content->maxgrade)) {
        $grades = $activity->content->sumgrades . ' / ' . $activity->content->maxgrade;
        echo ': (<a href="' . $CFG->wwwroot . '/mod/quizp/review.php?attempt=' .
                $activity->content->attemptid . '">' . $grades . '</a>)';
    }
    echo '</div>';

    echo '<div class="user">';
    echo '<a href="' . $CFG->wwwroot . '/user/view.php?id=' . $activity->user->id .
            '&amp;course=' . $courseid . '">' . $activity->user->fullname .
            '</a> - ' . userdate($activity->timestamp);
    echo '</div>';

    echo '</td></tr></table>';

    return;
}

/**
 * Pre-process the quizp options form data, making any necessary adjustments.
 * Called by add/update instance in this file.
 *
 * @param object $quizp The variables set on the form.
 */
function quizp_process_options($quizp) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');
    require_once($CFG->libdir . '/questionlib.php');

    $quizp->timemodified = time();

    // Quiz name.
    if (!empty($quizp->name)) {
        $quizp->name = trim($quizp->name);
    }

    // Password field - different in form to stop browsers that remember passwords
    // getting confused.
    $quizp->password = $quizp->quizppassword;
    unset($quizp->quizppassword);

    // Quiz feedback.
    if (isset($quizp->feedbacktext)) {
        // Clean up the boundary text.
        for ($i = 0; $i < count($quizp->feedbacktext); $i += 1) {
            if (empty($quizp->feedbacktext[$i]['text'])) {
                $quizp->feedbacktext[$i]['text'] = '';
            } else {
                $quizp->feedbacktext[$i]['text'] = trim($quizp->feedbacktext[$i]['text']);
            }
        }

        // Check the boundary value is a number or a percentage, and in range.
        $i = 0;
        while (!empty($quizp->feedbackboundaries[$i])) {
            $boundary = trim($quizp->feedbackboundaries[$i]);
            if (!is_numeric($boundary)) {
                if (strlen($boundary) > 0 && $boundary[strlen($boundary) - 1] == '%') {
                    $boundary = trim(substr($boundary, 0, -1));
                    if (is_numeric($boundary)) {
                        $boundary = $boundary * $quizp->grade / 100.0;
                    } else {
                        return get_string('feedbackerrorboundaryformat', 'quizp', $i + 1);
                    }
                }
            }
            if ($boundary <= 0 || $boundary >= $quizp->grade) {
                return get_string('feedbackerrorboundaryoutofrange', 'quizp', $i + 1);
            }
            if ($i > 0 && $boundary >= $quizp->feedbackboundaries[$i - 1]) {
                return get_string('feedbackerrororder', 'quizp', $i + 1);
            }
            $quizp->feedbackboundaries[$i] = $boundary;
            $i += 1;
        }
        $numboundaries = $i;

        // Check there is nothing in the remaining unused fields.
        if (!empty($quizp->feedbackboundaries)) {
            for ($i = $numboundaries; $i < count($quizp->feedbackboundaries); $i += 1) {
                if (!empty($quizp->feedbackboundaries[$i]) &&
                        trim($quizp->feedbackboundaries[$i]) != '') {
                    return get_string('feedbackerrorjunkinboundary', 'quizp', $i + 1);
                }
            }
        }
        for ($i = $numboundaries + 1; $i < count($quizp->feedbacktext); $i += 1) {
            if (!empty($quizp->feedbacktext[$i]['text']) &&
                    trim($quizp->feedbacktext[$i]['text']) != '') {
                return get_string('feedbackerrorjunkinfeedback', 'quizp', $i + 1);
            }
        }
        // Needs to be bigger than $quizp->grade because of '<' test in quizp_feedback_for_grade().
        $quizp->feedbackboundaries[-1] = $quizp->grade + 1;
        $quizp->feedbackboundaries[$numboundaries] = 0;
        $quizp->feedbackboundarycount = $numboundaries;
    } else {
        $quizp->feedbackboundarycount = -1;
    }

    // Combing the individual settings into the review columns.
    $quizp->reviewattempt = quizp_review_option_form_to_db($quizp, 'attempt');
    $quizp->reviewcorrectness = quizp_review_option_form_to_db($quizp, 'correctness');
    $quizp->reviewmarks = quizp_review_option_form_to_db($quizp, 'marks');
    $quizp->reviewspecificfeedback = quizp_review_option_form_to_db($quizp, 'specificfeedback');
    $quizp->reviewgeneralfeedback = quizp_review_option_form_to_db($quizp, 'generalfeedback');
    $quizp->reviewrightanswer = quizp_review_option_form_to_db($quizp, 'rightanswer');
    $quizp->reviewoverallfeedback = quizp_review_option_form_to_db($quizp, 'overallfeedback');
    $quizp->reviewattempt |= mod_quizp_display_options::DURING;
    $quizp->reviewoverallfeedback &= ~mod_quizp_display_options::DURING;
}

/**
 * Helper function for {@link quizp_process_options()}.
 * @param object $fromform the sumbitted form date.
 * @param string $field one of the review option field names.
 */
function quizp_review_option_form_to_db($fromform, $field) {
    static $times = array(
        'during' => mod_quizp_display_options::DURING,
        'immediately' => mod_quizp_display_options::IMMEDIATELY_AFTER,
        'open' => mod_quizp_display_options::LATER_WHILE_OPEN,
        'closed' => mod_quizp_display_options::AFTER_CLOSE,
    );

    $review = 0;
    foreach ($times as $whenname => $when) {
        $fieldname = $field . $whenname;
        if (isset($fromform->$fieldname)) {
            $review |= $when;
            unset($fromform->$fieldname);
        }
    }

    return $review;
}

/**
 * This function is called at the end of quizp_add_instance
 * and quizp_update_instance, to do the common processing.
 *
 * @param object $quizp the quizp object.
 */
function quizp_after_add_or_update($quizp) {
    global $DB;
    $cmid = $quizp->coursemodule;

    // We need to use context now, so we need to make sure all needed info is already in db.
    $DB->set_field('course_modules', 'instance', $quizp->id, array('id'=>$cmid));
    $context = context_module::instance($cmid);

    // Save the feedback.
    $DB->delete_records('quizp_feedback', array('quizpid' => $quizp->id));

    for ($i = 0; $i <= $quizp->feedbackboundarycount; $i++) {
        $feedback = new stdClass();
        $feedback->quizpid = $quizp->id;
        $feedback->feedbacktext = $quizp->feedbacktext[$i]['text'];
        $feedback->feedbacktextformat = $quizp->feedbacktext[$i]['format'];
        $feedback->mingrade = $quizp->feedbackboundaries[$i];
        $feedback->maxgrade = $quizp->feedbackboundaries[$i - 1];
        $feedback->id = $DB->insert_record('quizp_feedback', $feedback);
        $feedbacktext = file_save_draft_area_files((int)$quizp->feedbacktext[$i]['itemid'],
                $context->id, 'mod_quizp', 'feedback', $feedback->id,
                array('subdirs' => false, 'maxfiles' => -1, 'maxbytes' => 0),
                $quizp->feedbacktext[$i]['text']);
        $DB->set_field('quizp_feedback', 'feedbacktext', $feedbacktext,
                array('id' => $feedback->id));
    }

    // Store any settings belonging to the access rules.
    quizp_access_manager::save_settings($quizp);

    // Update the events relating to this quizp.
    quizp_update_events($quizp);

    // Update related grade item.
    quizp_grade_item_update($quizp);
}

/**
 * This function updates the events associated to the quizp.
 * If $override is non-zero, then it updates only the events
 * associated with the specified override.
 *
 * @uses QUIZP_MAX_EVENT_LENGTH
 * @param object $quizp the quizp object.
 * @param object optional $override limit to a specific override
 */
function quizp_update_events($quizp, $override = null) {
    global $DB;

    // Load the old events relating to this quizp.
    $conds = array('modulename'=>'quizp',
                   'instance'=>$quizp->id);
    if (!empty($override)) {
        // Only load events for this override.
        $conds['groupid'] = isset($override->groupid)?  $override->groupid : 0;
        $conds['userid'] = isset($override->userid)?  $override->userid : 0;
    }
    $oldevents = $DB->get_records('event', $conds);

    // Now make a todo list of all that needs to be updated.
    if (empty($override)) {
        // We are updating the primary settings for the quizp, so we
        // need to add all the overrides.
        $overrides = $DB->get_records('quizp_overrides', array('quizp' => $quizp->id));
        // As well as the original quizp (empty override).
        $overrides[] = new stdClass();
    } else {
        // Just do the one override.
        $overrides = array($override);
    }

    foreach ($overrides as $current) {
        $groupid   = isset($current->groupid)?  $current->groupid : 0;
        $userid    = isset($current->userid)? $current->userid : 0;
        $timeopen  = isset($current->timeopen)?  $current->timeopen : $quizp->timeopen;
        $timeclose = isset($current->timeclose)? $current->timeclose : $quizp->timeclose;

        // Only add open/close events for an override if they differ from the quizp default.
        $addopen  = empty($current->id) || !empty($current->timeopen);
        $addclose = empty($current->id) || !empty($current->timeclose);

        if (!empty($quizp->coursemodule)) {
            $cmid = $quizp->coursemodule;
        } else {
            $cmid = get_coursemodule_from_instance('quizp', $quizp->id, $quizp->course)->id;
        }

        $event = new stdClass();
        $event->description = format_module_intro('quizp', $quizp, $cmid);
        // Events module won't show user events when the courseid is nonzero.
        $event->courseid    = ($userid) ? 0 : $quizp->course;
        $event->groupid     = $groupid;
        $event->userid      = $userid;
        $event->modulename  = 'quizp';
        $event->instance    = $quizp->id;
        $event->timestart   = $timeopen;
        $event->timeduration = max($timeclose - $timeopen, 0);
        $event->visible     = instance_is_visible('quizp', $quizp);
        $event->eventtype   = 'open';

        // Determine the event name.
        if ($groupid) {
            $params = new stdClass();
            $params->quizp = $quizp->name;
            $params->group = groups_get_group_name($groupid);
            if ($params->group === false) {
                // Group doesn't exist, just skip it.
                continue;
            }
            $eventname = get_string('overridegroupeventname', 'quizp', $params);
        } else if ($userid) {
            $params = new stdClass();
            $params->quizp = $quizp->name;
            $eventname = get_string('overrideusereventname', 'quizp', $params);
        } else {
            $eventname = $quizp->name;
        }
        if ($addopen or $addclose) {
            if ($timeclose and $timeopen and $event->timeduration <= QUIZP_MAX_EVENT_LENGTH) {
                // Single event for the whole quizp.
                if ($oldevent = array_shift($oldevents)) {
                    $event->id = $oldevent->id;
                } else {
                    unset($event->id);
                }
                $event->name = $eventname;
                // The method calendar_event::create will reuse a db record if the id field is set.
                calendar_event::create($event);
            } else {
                // Separate start and end events.
                $event->timeduration  = 0;
                if ($timeopen && $addopen) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name = $eventname.' ('.get_string('quizpopens', 'quizp').')';
                    // The method calendar_event::create will reuse a db record if the id field is set.
                    calendar_event::create($event);
                }
                if ($timeclose && $addclose) {
                    if ($oldevent = array_shift($oldevents)) {
                        $event->id = $oldevent->id;
                    } else {
                        unset($event->id);
                    }
                    $event->name      = $eventname.' ('.get_string('quizpcloses', 'quizp').')';
                    $event->timestart = $timeclose;
                    $event->eventtype = 'close';
                    calendar_event::create($event);
                }
            }
        }
    }

    // Delete any leftover events.
    foreach ($oldevents as $badevent) {
        $badevent = calendar_event::load($badevent);
        $badevent->delete();
    }
}

/**
 * List the actions that correspond to a view of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = 'r' and edulevel = LEVEL_PARTICIPATING will
 *       be considered as view action.
 *
 * @return array
 */
function quizp_get_view_actions() {
    return array('view', 'view all', 'report', 'review');
}

/**
 * List the actions that correspond to a post of this module.
 * This is used by the participation report.
 *
 * Note: This is not used by new logging system. Event with
 *       crud = ('c' || 'u' || 'd') and edulevel = LEVEL_PARTICIPATING
 *       will be considered as post action.
 *
 * @return array
 */
function quizp_get_post_actions() {
    return array('attempt', 'close attempt', 'preview', 'editquestions',
            'delete attempt', 'manualgrade');
}

/**
 * @param array $questionids of question ids.
 * @return bool whether any of these questions are used by any instance of this module.
 */
function quizp_questions_in_use($questionids) {
    global $DB, $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    list($test, $params) = $DB->get_in_or_equal($questionids);
    return $DB->record_exists_select('quizp_slots',
            'questionid ' . $test, $params) || question_engine::questions_in_use(
            $questionids, new qubaid_join('{quizp_attempts} quizpa',
            'quizpa.uniqueid', 'quizpa.preview = 0'));
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the quizp.
 *
 * @param $mform the course reset form that is being built.
 */
function quizp_reset_course_form_definition($mform) {
    $mform->addElement('header', 'quizpheader', get_string('modulenameplural', 'quizp'));
    $mform->addElement('advcheckbox', 'reset_quizp_attempts',
            get_string('removeallquizpattempts', 'quizp'));
    $mform->addElement('advcheckbox', 'reset_quizp_user_overrides',
            get_string('removealluseroverrides', 'quizp'));
    $mform->addElement('advcheckbox', 'reset_quizp_group_overrides',
            get_string('removeallgroupoverrides', 'quizp'));
}

/**
 * Course reset form defaults.
 * @return array the defaults.
 */
function quizp_reset_course_form_defaults($course) {
    return array('reset_quizp_attempts' => 1,
                 'reset_quizp_group_overrides' => 1,
                 'reset_quizp_user_overrides' => 1);
}

/**
 * Removes all grades from gradebook
 *
 * @param int $courseid
 * @param string optional type
 */
function quizp_reset_gradebook($courseid, $type='') {
    global $CFG, $DB;

    $quizpzes = $DB->get_records_sql("
            SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
            FROM {modules} m
            JOIN {course_modules} cm ON m.id = cm.module
            JOIN {quizp} q ON cm.instance = q.id
            WHERE m.name = 'quizp' AND cm.course = ?", array($courseid));

    foreach ($quizpzes as $quizp) {
        quizp_grade_item_update($quizp, 'reset');
    }
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * quizp attempts for course $data->courseid, if $data->reset_quizp_attempts is
 * set and true.
 *
 * Also, move the quizp open and close dates, if the course start date is changing.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function quizp_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');

    $componentstr = get_string('modulenameplural', 'quizp');
    $status = array();

    // Delete attempts.
    if (!empty($data->reset_quizp_attempts)) {
        question_engine::delete_questions_usage_by_activities(new qubaid_join(
                '{quizp_attempts} quizpa JOIN {quizp} quizp ON quizpa.quizp = quizp.id',
                'quizpa.uniqueid', 'quizp.course = :quizpcourseid',
                array('quizpcourseid' => $data->courseid)));

        $DB->delete_records_select('quizp_attempts',
                'quizp IN (SELECT id FROM {quizp} WHERE course = ?)', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('attemptsdeleted', 'quizp'),
            'error' => false);

        // Remove all grades from gradebook.
        $DB->delete_records_select('quizp_grades',
                'quizp IN (SELECT id FROM {quizp} WHERE course = ?)', array($data->courseid));
        if (empty($data->reset_gradebook_grades)) {
            quizp_reset_gradebook($data->courseid);
        }
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'quizp'),
            'error' => false);
    }

    // Remove user overrides.
    if (!empty($data->reset_quizp_user_overrides)) {
        $DB->delete_records_select('quizp_overrides',
                'quizp IN (SELECT id FROM {quizp} WHERE course = ?) AND userid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('useroverridesdeleted', 'quizp'),
            'error' => false);
    }
    // Remove group overrides.
    if (!empty($data->reset_quizp_group_overrides)) {
        $DB->delete_records_select('quizp_overrides',
                'quizp IN (SELECT id FROM {quizp} WHERE course = ?) AND groupid IS NOT NULL', array($data->courseid));
        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('groupoverridesdeleted', 'quizp'),
            'error' => false);
    }

    // Updating dates - shift may be negative too.
    if ($data->timeshift) {
        $DB->execute("UPDATE {quizp_overrides}
                         SET timeopen = timeopen + ?
                       WHERE quizp IN (SELECT id FROM {quizp} WHERE course = ?)
                         AND timeopen <> 0", array($data->timeshift, $data->courseid));
        $DB->execute("UPDATE {quizp_overrides}
                         SET timeclose = timeclose + ?
                       WHERE quizp IN (SELECT id FROM {quizp} WHERE course = ?)
                         AND timeclose <> 0", array($data->timeshift, $data->courseid));

        shift_course_mod_dates('quizp', array('timeopen', 'timeclose'),
                $data->timeshift, $data->courseid);

        $status[] = array(
            'component' => $componentstr,
            'item' => get_string('openclosedatesupdated', 'quizp'),
            'error' => false);
    }

    return $status;
}

/**
 * Prints quizp summaries on MyMoodle Page
 * @param arry $courses
 * @param array $htmlarray
 */
function quizp_print_overview($courses, &$htmlarray) {
    global $USER, $CFG;
    // These next 6 Lines are constant in all modules (just change module name).
    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$quizpzes = get_all_instances_in_courses('quizp', $courses)) {
        return;
    }

    // Fetch some language strings outside the main loop.
    $strquizp = get_string('modulename', 'quizp');
    $strnoattempts = get_string('noattempts', 'quizp');

    // We want to list quizpzes that are currently available, and which have a close date.
    // This is the same as what the lesson does, and the dabate is in MDL-10568.
    $now = time();
    foreach ($quizpzes as $quizp) {
        if ($quizp->timeclose >= $now && $quizp->timeopen < $now) {
            // Give a link to the quizp, and the deadline.
            $str = '<div class="quizp overview">' .
                    '<div class="name">' . $strquizp . ': <a ' .
                    ($quizp->visible ? '' : ' class="dimmed"') .
                    ' href="' . $CFG->wwwroot . '/mod/quizp/view.php?id=' .
                    $quizp->coursemodule . '">' .
                    $quizp->name . '</a></div>';
            $str .= '<div class="info">' . get_string('quizpcloseson', 'quizp',
                    userdate($quizp->timeclose)) . '</div>';

            // Now provide more information depending on the uers's role.
            $context = context_module::instance($quizp->coursemodule);
            if (has_capability('mod/quizp:viewreports', $context)) {
                // For teacher-like people, show a summary of the number of student attempts.
                // The $quizp objects returned by get_all_instances_in_course have the necessary $cm
                // fields set to make the following call work.
                $str .= '<div class="info">' .
                        quizp_num_attempt_summary($quizp, $quizp, true) . '</div>';
            } else if (has_any_capability(array('mod/quizp:reviewmyattempts', 'mod/quizp:attempt'),
                    $context)) { // Student
                // For student-like people, tell them how many attempts they have made.
                if (isset($USER->id) &&
                        ($attempts = quizp_get_user_attempts($quizp->id, $USER->id))) {
                    $numattempts = count($attempts);
                    $str .= '<div class="info">' .
                            get_string('numattemptsmade', 'quizp', $numattempts) . '</div>';
                } else {
                    $str .= '<div class="info">' . $strnoattempts . '</div>';
                }
            } else {
                // For ayone else, there is no point listing this quizp, so stop processing.
                continue;
            }

            // Add the output for this quizp to the rest.
            $str .= '</div>';
            if (empty($htmlarray[$quizp->course]['quizp'])) {
                $htmlarray[$quizp->course]['quizp'] = $str;
            } else {
                $htmlarray[$quizp->course]['quizp'] .= $str;
            }
        }
    }
}

/**
 * Return a textual summary of the number of attempts that have been made at a particular quizp,
 * returns '' if no attempts have been made yet, unless $returnzero is passed as true.
 *
 * @param object $quizp the quizp object. Only $quizp->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param bool $returnzero if false (default), when no attempts have been
 *      made '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string a string like "Attempts: 123", "Attemtps 123 (45 from your groups)" or
 *          "Attemtps 123 (45 from this group)".
 */
function quizp_num_attempt_summary($quizp, $cm, $returnzero = false, $currentgroup = 0) {
    global $DB, $USER;
    $numattempts = $DB->count_records('quizp_attempts', array('quizp'=> $quizp->id, 'preview'=>0));
    if ($numattempts || $returnzero) {
        if (groups_get_activity_groupmode($cm)) {
            $a = new stdClass();
            $a->total = $numattempts;
            if ($currentgroup) {
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{quizp_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quizp = ? AND preview = 0 AND groupid = ?',
                        array($quizp->id, $currentgroup));
                return get_string('attemptsnumthisgroup', 'quizp', $a);
            } else if ($groups = groups_get_all_groups($cm->course, $USER->id, $cm->groupingid)) {
                list($usql, $params) = $DB->get_in_or_equal(array_keys($groups));
                $a->group = $DB->count_records_sql('SELECT COUNT(DISTINCT qa.id) FROM ' .
                        '{quizp_attempts} qa JOIN ' .
                        '{groups_members} gm ON qa.userid = gm.userid ' .
                        'WHERE quizp = ? AND preview = 0 AND ' .
                        "groupid $usql", array_merge(array($quizp->id), $params));
                return get_string('attemptsnumyourgroups', 'quizp', $a);
            }
        }
        return get_string('attemptsnum', 'quizp', $numattempts);
    }
    return '';
}

/**
 * Returns the same as {@link quizp_num_attempt_summary()} but wrapped in a link
 * to the quizp reports.
 *
 * @param object $quizp the quizp object. Only $quizp->id is used at the moment.
 * @param object $cm the cm object. Only $cm->course, $cm->groupmode and
 *      $cm->groupingid fields are used at the moment.
 * @param object $context the quizp context.
 * @param bool $returnzero if false (default), when no attempts have been made
 *      '' is returned instead of 'Attempts: 0'.
 * @param int $currentgroup if there is a concept of current group where this method is being called
 *         (e.g. a report) pass it in here. Default 0 which means no current group.
 * @return string HTML fragment for the link.
 */
function quizp_attempt_summary_link_to_reports($quizp, $cm, $context, $returnzero = false,
        $currentgroup = 0) {
    global $CFG;
    $summary = quizp_num_attempt_summary($quizp, $cm, $returnzero, $currentgroup);
    if (!$summary) {
        return '';
    }

    require_once($CFG->dirroot . '/mod/quizp/report/reportlib.php');
    $url = new moodle_url('/mod/quizp/report.php', array(
            'id' => $cm->id, 'mode' => quizp_report_default_report($context)));
    return html_writer::link($url, $summary);
}

/**
 * @param string $feature FEATURE_xx constant for requested feature
 * @return bool True if quizp supports feature
 */
function quizp_supports($feature) {
    switch($feature) {
        case FEATURE_GROUPS:                    return true;
        case FEATURE_GROUPINGS:                 return true;
        case FEATURE_MOD_INTRO:                 return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:   return true;
        case FEATURE_COMPLETION_HAS_RULES:      return true;
        case FEATURE_GRADE_HAS_GRADE:           return true;
        case FEATURE_GRADE_OUTCOMES:            return true;
        case FEATURE_BACKUP_MOODLE2:            return true;
        case FEATURE_SHOW_DESCRIPTION:          return true;
        case FEATURE_CONTROLS_GRADE_VISIBILITY: return true;
        case FEATURE_USES_QUESTIONS:            return true;

        default: return null;
    }
}

/**
 * @return array all other caps used in module
 */
function quizp_get_extra_capabilities() {
    global $CFG;
    require_once($CFG->libdir . '/questionlib.php');
    $caps = question_get_all_capabilities();
    $caps[] = 'moodle/site:accessallgroups';
    return $caps;
}

/**
 * This function extends the settings navigation block for the site.
 *
 * It is safe to rely on PAGE here as we will only ever be within the module
 * context when this is called
 *
 * @param settings_navigation $settings
 * @param navigation_node $quizpnode
 * @return void
 */
function quizp_extend_settings_navigation($settings, $quizpnode) {
    global $PAGE, $CFG;

    // Require {@link questionlib.php}
    // Included here as we only ever want to include this file if we really need to.
    require_once($CFG->libdir . '/questionlib.php');

    // We want to add these new nodes after the Edit settings node, and before the
    // Locally assigned roles node. Of course, both of those are controlled by capabilities.
    $keys = $quizpnode->get_children_key_list();
    $beforekey = null;
    $i = array_search('modedit', $keys);
    if ($i === false and array_key_exists(0, $keys)) {
        $beforekey = $keys[0];
    } else if (array_key_exists($i + 1, $keys)) {
        $beforekey = $keys[$i + 1];
    }

    if (has_capability('mod/quizp:manageoverrides', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/quizp/overrides.php', array('cmid'=>$PAGE->cm->id));
        $node = navigation_node::create(get_string('groupoverrides', 'quizp'),
                new moodle_url($url, array('mode'=>'group')),
                navigation_node::TYPE_SETTING, null, 'mod_quizp_groupoverrides');
        $quizpnode->add_node($node, $beforekey);

        $node = navigation_node::create(get_string('useroverrides', 'quizp'),
                new moodle_url($url, array('mode'=>'user')),
                navigation_node::TYPE_SETTING, null, 'mod_quizp_useroverrides');
        $quizpnode->add_node($node, $beforekey);
    }

    if (has_capability('mod/quizp:manage', $PAGE->cm->context)) {
        $node = navigation_node::create(get_string('editquizp', 'quizp'),
                new moodle_url('/mod/quizp/edit.php', array('cmid'=>$PAGE->cm->id)),
                navigation_node::TYPE_SETTING, null, 'mod_quizp_edit',
                new pix_icon('t/edit', ''));
        $quizpnode->add_node($node, $beforekey);
    }

    if (has_capability('mod/quizp:preview', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/quizp/startattempt.php',
                array('cmid'=>$PAGE->cm->id, 'sesskey'=>sesskey()));
        $node = navigation_node::create(get_string('preview', 'quizp'), $url,
                navigation_node::TYPE_SETTING, null, 'mod_quizp_preview',
                new pix_icon('i/preview', ''));
        $quizpnode->add_node($node, $beforekey);
    }

    if (has_any_capability(array('mod/quizp:viewreports', 'mod/quizp:grade'), $PAGE->cm->context)) {
        require_once($CFG->dirroot . '/mod/quizp/report/reportlib.php');
        $reportlist = quizp_report_list($PAGE->cm->context);

        $url = new moodle_url('/mod/quizp/report.php',
                array('id' => $PAGE->cm->id, 'mode' => reset($reportlist)));
        $reportnode = $quizpnode->add_node(navigation_node::create(get_string('results', 'quizp'), $url,
                navigation_node::TYPE_SETTING,
                null, null, new pix_icon('i/report', '')), $beforekey);

        foreach ($reportlist as $report) {
            $url = new moodle_url('/mod/quizp/report.php',
                    array('id' => $PAGE->cm->id, 'mode' => $report));
            $reportnode->add_node(navigation_node::create(get_string($report, 'quizp_'.$report), $url,
                    navigation_node::TYPE_SETTING,
                    null, 'quizp_report_' . $report, new pix_icon('i/item', '')));
        }
    }

    question_extend_settings_navigation($quizpnode, $PAGE->cm->context)->trim_if_empty();
}

/**
 * Serves the quizp files.
 *
 * @package  mod_quizp
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function quizp_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $CFG, $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$quizp = $DB->get_record('quizp', array('id'=>$cm->instance))) {
        return false;
    }

    // The 'intro' area is served by pluginfile.php.
    $fileareas = array('feedback');
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $feedbackid = (int)array_shift($args);
    if (!$feedback = $DB->get_record('quizp_feedback', array('id'=>$feedbackid))) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_quizp/$filearea/$feedbackid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Called via pluginfile.php -> question_pluginfile to serve files belonging to
 * a question in a question_attempt when that attempt is a quizp attempt.
 *
 * @package  mod_quizp
 * @category files
 * @param stdClass $course course settings object
 * @param stdClass $context context object
 * @param string $component the name of the component we are serving files for.
 * @param string $filearea the name of the file area.
 * @param int $qubaid the attempt usage id.
 * @param int $slot the id of a question in this quizp attempt.
 * @param array $args the remaining bits of the file path.
 * @param bool $forcedownload whether the user must be forced to download the file.
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - justsend the file
 */
function quizp_question_pluginfile($course, $context, $component,
        $filearea, $qubaid, $slot, $args, $forcedownload, array $options=array()) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/quizp/locallib.php');

    $attemptobj = quizp_attempt::create_from_usage_id($qubaid);
    require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

    if ($attemptobj->is_own_attempt() && !$attemptobj->is_finished()) {
        // In the middle of an attempt.
        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/quizp:attempt');
        }
        $isreviewing = false;

    } else {
        // Reviewing an attempt.
        $attemptobj->check_review_capability();
        $isreviewing = true;
    }

    if (!$attemptobj->check_file_access($slot, $isreviewing, $context->id,
            $component, $filearea, $args, $forcedownload)) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/$component/$filearea/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) or $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Return a list of page types
 * @param string $pagetype current page type
 * @param stdClass $parentcontext Block's parent context
 * @param stdClass $currentcontext Current context of block
 */
function quizp_page_type_list($pagetype, $parentcontext, $currentcontext) {
    $module_pagetype = array(
        'mod-quizp-*'       => get_string('page-mod-quizp-x', 'quizp'),
        'mod-quizp-view'    => get_string('page-mod-quizp-view', 'quizp'),
        'mod-quizp-attempt' => get_string('page-mod-quizp-attempt', 'quizp'),
        'mod-quizp-summary' => get_string('page-mod-quizp-summary', 'quizp'),
        'mod-quizp-review'  => get_string('page-mod-quizp-review', 'quizp'),
        'mod-quizp-edit'    => get_string('page-mod-quizp-edit', 'quizp'),
        'mod-quizp-report'  => get_string('page-mod-quizp-report', 'quizp'),
    );
    return $module_pagetype;
}

/**
 * @return the options for quizp navigation.
 */
function quizp_get_navigation_options() {
    return array(
        QUIZP_NAVMETHOD_FREE => get_string('navmethod_free', 'quizp'),
        QUIZP_NAVMETHOD_SEQ  => get_string('navmethod_seq', 'quizp')
    );
}

/**
 * Obtains the automatic completion state for this quizp on any conditions
 * in quizp settings, such as if all attempts are used or a certain grade is achieved.
 *
 * @param object $course Course
 * @param object $cm Course-module
 * @param int $userid User ID
 * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not. (If no conditions, then return
 *   value depends on comparison type)
 */
function quizp_get_completion_state($course, $cm, $userid, $type) {
    global $DB;
    global $CFG;

    $quizp = $DB->get_record('quizp', array('id' => $cm->instance), '*', MUST_EXIST);
    if (!$quizp->completionattemptsexhausted && !$quizp->completionpass) {
        return $type;
    }

    // Check if the user has used up all attempts.
    if ($quizp->completionattemptsexhausted) {
        $attempts = quizp_get_user_attempts($quizp->id, $userid, 'finished', true);
        if ($attempts) {
            $lastfinishedattempt = end($attempts);
            $context = context_module::instance($cm->id);
            $quizpobj = quizp::create($quizp->id, $userid);
            $accessmanager = new quizp_access_manager($quizpobj, time(),
                    has_capability('mod/quizp:ignoretimelimits', $context, $userid, false));
            if ($accessmanager->is_finished(count($attempts), $lastfinishedattempt)) {
                return true;
            }
        }
    }

    // Check for passing grade.
    if ($quizp->completionpass) {
        require_once($CFG->libdir . '/gradelib.php');
        $item = grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod',
                'itemmodule' => 'quizp', 'iteminstance' => $cm->instance, 'outcomeid' => null));
        if ($item) {
            $grades = grade_grade::fetch_users_grades($item, array($userid), false);
            if (!empty($grades[$userid])) {
                return $grades[$userid]->is_passed($item);
            }
        }
    }
    return false;
}
