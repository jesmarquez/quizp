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
 * Library of functions used by the quizp module.
 *
 * This contains functions that are called from within the quizp module only
 * Functions that are also called by core Moodle are in {@link lib.php}
 * This script also loads the code in {@link questionlib.php} which holds
 * the module-indpendent code for handling questions and which in turn
 * initialises all the questiontype classes.
 *
 * @package    mod_quizp
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quizp/lib.php');
require_once($CFG->dirroot . '/mod/quizp/accessmanager.php');
require_once($CFG->dirroot . '/mod/quizp/accessmanager_form.php');
require_once($CFG->dirroot . '/mod/quizp/renderer.php');
require_once($CFG->dirroot . '/mod/quizp/attemptlib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/eventslib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/questionlib.php');


/**
 * @var int We show the countdown timer if there is less than this amount of time left before the
 * the quizp close date. (1 hour)
 */
define('QUIZP_SHOW_TIME_BEFORE_DEADLINE', '3600');

/**
 * @var int If there are fewer than this many seconds left when the student submits
 * a page of the quizp, then do not take them to the next page of the quizp. Instead
 * close the quizp immediately.
 */
define('QUIZP_MIN_TIME_TO_CONTINUE', '2');

/**
 * @var int We show no image when user selects No image from dropdown menu in quizp settings.
 */
define('QUIZP_SHOWIMAGE_NONE', 0);

/**
 * @var int We show small image when user selects small image from dropdown menu in quizp settings.
 */
define('QUIZP_SHOWIMAGE_SMALL', 1);

/**
 * @var int We show Large image when user selects Large image from dropdown menu in quizp settings.
 */
define('QUIZP_SHOWIMAGE_LARGE', 2);


// Functions related to attempts ///////////////////////////////////////////////

/**
 * Creates an object to represent a new attempt at a quizp
 *
 * Creates an attempt object to represent an attempt at the quizp by the current
 * user starting at the current time. The ->id field is not set. The object is
 * NOT written to the database.
 *
 * @param object $quizpobj the quizp object to create an attempt for.
 * @param int $attemptnumber the sequence number for the attempt.
 * @param object $lastattempt the previous attempt by this user, if any. Only needed
 *         if $attemptnumber > 1 and $quizp->attemptonlast is true.
 * @param int $timenow the time the attempt was started at.
 * @param bool $ispreview whether this new attempt is a preview.
 * @param int $userid  the id of the user attempting this quizp.
 *
 * @return object the newly created attempt object.
 */
function quizp_create_attempt(quizp $quizpobj, $attemptnumber, $lastattempt, $timenow, $ispreview = false, $userid = null) {
    global $USER;

    if ($userid === null) {
        $userid = $USER->id;
    }

    $quizp = $quizpobj->get_quizp();
    if ($quizp->sumgrades < 0.000005 && $quizp->grade > 0.000005) {
        throw new moodle_exception('cannotstartgradesmismatch', 'quizp',
                new moodle_url('/mod/quizp/view.php', array('q' => $quizp->id)),
                    array('grade' => quizp_format_grade($quizp, $quizp->grade)));
    }

    if ($attemptnumber == 1 || !$quizp->attemptonlast) {
        // We are not building on last attempt so create a new attempt.
        $attempt = new stdClass();
        $attempt->quizp = $quizp->id;
        $attempt->userid = $userid;
        $attempt->preview = 0;
        $attempt->layout = '';
    } else {
        // Build on last attempt.
        if (empty($lastattempt)) {
            print_error('cannotfindprevattempt', 'quizp');
        }
        $attempt = $lastattempt;
    }

    $attempt->attempt = $attemptnumber;
    $attempt->timestart = $timenow;
    $attempt->timefinish = 0;
    $attempt->timemodified = $timenow;
    $attempt->state = quizp_attempt::IN_PROGRESS;
    $attempt->currentpage = 0;
    $attempt->sumgrades = null;

    // If this is a preview, mark it as such.
    if ($ispreview) {
        $attempt->preview = 1;
    }

    $timeclose = $quizpobj->get_access_manager($timenow)->get_end_time($attempt);
    if ($timeclose === false || $ispreview) {
        $attempt->timecheckstate = null;
    } else {
        $attempt->timecheckstate = $timeclose;
    }

    return $attempt;
}
/**
 * Start a normal, new, quizp attempt.
 *
 * @param quizp      $quizpobj            the quizp object to start an attempt for.
 * @param question_usage_by_activity $quba
 * @param object    $attempt
 * @param integer   $attemptnumber      starting from 1
 * @param integer   $timenow            the attempt start time
 * @param array     $questionids        slot number => question id. Used for random questions, to force the choice
 *                                        of a particular actual question. Intended for testing purposes only.
 * @param array     $forcedvariantsbyslot slot number => variant. Used for questions with variants,
 *                                          to force the choice of a particular variant. Intended for testing
 *                                          purposes only.
 * @throws moodle_exception
 * @return object   modified attempt object
 */
function quizp_start_new_attempt($quizpobj, $quba, $attempt, $attemptnumber, $timenow,
                                $questionids = array(), $forcedvariantsbyslot = array()) {

    // Usages for this user's previous quizp attempts.
    $qubaids = new \mod_quizp\question\qubaids_for_users_attempts(
            $quizpobj->get_quizpid(), $attempt->userid);

    // Fully load all the questions in this quizp.
    $quizpobj->preload_questions();
    $quizpobj->load_questions();

    // First load all the non-random questions.
    $randomfound = false;
    $slot = 0;
    $questions = array();
    $maxmark = array();
    $page = array();
    foreach ($quizpobj->get_questions() as $questiondata) {
        $slot += 1;
        $maxmark[$slot] = $questiondata->maxmark;
        $page[$slot] = $questiondata->page;
        if ($questiondata->qtype == 'random') {
            $randomfound = true;
            continue;
        }
        if (!$quizpobj->get_quizp()->shuffleanswers) {
            $questiondata->options->shuffleanswers = false;
        }
        $questions[$slot] = question_bank::make_question($questiondata);
    }

    // Then find a question to go in place of each random question.
    if ($randomfound) {
        $slot = 0;
        $usedquestionids = array();
        foreach ($questions as $question) {
            if (isset($usedquestions[$question->id])) {
                $usedquestionids[$question->id] += 1;
            } else {
                $usedquestionids[$question->id] = 1;
            }
        }
        $randomloader = new \core_question\bank\random_question_loader($qubaids, $usedquestionids);

        foreach ($quizpobj->get_questions() as $questiondata) {
            $slot += 1;
            if ($questiondata->qtype != 'random') {
                continue;
            }

            // Deal with fixed random choices for testing.
            if (isset($questionids[$quba->next_slot_number()])) {
                if ($randomloader->is_question_available($questiondata->category,
                        (bool) $questiondata->questiontext, $questionids[$quba->next_slot_number()])) {
                    $questions[$slot] = question_bank::load_question(
                            $questionids[$quba->next_slot_number()], $quizpobj->get_quizp()->shuffleanswers);
                    continue;
                } else {
                    throw new coding_exception('Forced question id not available.');
                }
            }

            // Normal case, pick one at random.
            $questionid = $randomloader->get_next_question_id($questiondata->category,
                        (bool) $questiondata->questiontext);
            if ($questionid === null) {
                throw new moodle_exception('notenoughrandomquestions', 'quizp',
                                           $quizpobj->view_url(), $questiondata);
            }

            $questions[$slot] = question_bank::load_question($questionid,
                    $quizpobj->get_quizp()->shuffleanswers);
        }
    }

    // Finally add them all to the usage.
    ksort($questions);
    foreach ($questions as $slot => $question) {
        $newslot = $quba->add_question($question, $maxmark[$slot]);
        if ($newslot != $slot) {
            throw new coding_exception('Slot numbers have got confused.');
        }
    }

    // Start all the questions.
    $variantstrategy = new core_question\engine\variants\least_used_strategy($quba, $qubaids);

    if (!empty($forcedvariantsbyslot)) {
        $forcedvariantsbyseed = question_variant_forced_choices_selection_strategy::prepare_forced_choices_array(
            $forcedvariantsbyslot, $quba);
        $variantstrategy = new question_variant_forced_choices_selection_strategy(
            $forcedvariantsbyseed, $variantstrategy);
    }

    $quba->start_all_questions($variantstrategy, $timenow);

    // Work out the attempt layout.
    $sections = $quizpobj->get_sections();
    foreach ($sections as $i => $section) {
        if (isset($sections[$i + 1])) {
            $sections[$i]->lastslot = $sections[$i + 1]->firstslot - 1;
        } else {
            $sections[$i]->lastslot = count($questions);
        }
    }

    $layout = array();
    foreach ($sections as $section) {
        if ($section->shufflequestions) {
            $questionsinthissection = array();
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                $questionsinthissection[] = $slot;
            }
            shuffle($questionsinthissection);
            $questionsonthispage = 0;
            foreach ($questionsinthissection as $slot) {
                if ($questionsonthispage && $questionsonthispage == $quizpobj->get_quizp()->questionsperpage) {
                    $layout[] = 0;
                    $questionsonthispage = 0;
                }
                $layout[] = $slot;
                $questionsonthispage += 1;
            }

        } else {
            $currentpage = $page[$section->firstslot];
            for ($slot = $section->firstslot; $slot <= $section->lastslot; $slot += 1) {
                if ($currentpage !== null && $page[$slot] != $currentpage) {
                    $layout[] = 0;
                }
                $layout[] = $slot;
                $currentpage = $page[$slot];
            }
        }

        // Each section ends with a page break.
        $layout[] = 0;
    }
    $attempt->layout = implode(',', $layout);

    return $attempt;
}

/**
 * Start a subsequent new attempt, in each attempt builds on last mode.
 *
 * @param question_usage_by_activity    $quba         this question usage
 * @param object                        $attempt      this attempt
 * @param object                        $lastattempt  last attempt
 * @return object                       modified attempt object
 *
 */
function quizp_start_attempt_built_on_last($quba, $attempt, $lastattempt) {
    $oldquba = question_engine::load_questions_usage_by_activity($lastattempt->uniqueid);

    $oldnumberstonew = array();
    foreach ($oldquba->get_attempt_iterator() as $oldslot => $oldqa) {
        $newslot = $quba->add_question($oldqa->get_question(), $oldqa->get_max_mark());

        $quba->start_question_based_on($newslot, $oldqa);

        $oldnumberstonew[$oldslot] = $newslot;
    }

    // Update attempt layout.
    $newlayout = array();
    foreach (explode(',', $lastattempt->layout) as $oldslot) {
        if ($oldslot != 0) {
            $newlayout[] = $oldnumberstonew[$oldslot];
        } else {
            $newlayout[] = 0;
        }
    }
    $attempt->layout = implode(',', $newlayout);
    return $attempt;
}

/**
 * The save started question usage and quizp attempt in db and log the started attempt.
 *
 * @param quizp                       $quizpobj
 * @param question_usage_by_activity $quba
 * @param object                     $attempt
 * @return object                    attempt object with uniqueid and id set.
 */
function quizp_attempt_save_started($quizpobj, $quba, $attempt) {
    global $DB;
    // Save the attempt in the database.
    question_engine::save_questions_usage_by_activity($quba);
    $attempt->uniqueid = $quba->get_id();
    $attempt->id = $DB->insert_record('quizp_attempts', $attempt);

    // Params used by the events below.
    $params = array(
        'objectid' => $attempt->id,
        'relateduserid' => $attempt->userid,
        'courseid' => $quizpobj->get_courseid(),
        'context' => $quizpobj->get_context()
    );
    // Decide which event we are using.
    if ($attempt->preview) {
        $params['other'] = array(
            'quizpid' => $quizpobj->get_quizpid()
        );
        $event = \mod_quizp\event\attempt_preview_started::create($params);
    } else {
        $event = \mod_quizp\event\attempt_started::create($params);

    }

    // Trigger the event.
    $event->add_record_snapshot('quizp', $quizpobj->get_quizp());
    $event->add_record_snapshot('quizp_attempts', $attempt);
    $event->trigger();

    return $attempt;
}

/**
 * Returns an unfinished attempt (if there is one) for the given
 * user on the given quizp. This function does not return preview attempts.
 *
 * @param int $quizpid the id of the quizp.
 * @param int $userid the id of the user.
 *
 * @return mixed the unfinished attempt if there is one, false if not.
 */
function quizp_get_user_attempt_unfinished($quizpid, $userid) {
    $attempts = quizp_get_user_attempts($quizpid, $userid, 'unfinished', true);
    if ($attempts) {
        return array_shift($attempts);
    } else {
        return false;
    }
}

/**
 * Delete a quizp attempt.
 * @param mixed $attempt an integer attempt id or an attempt object
 *      (row of the quizp_attempts table).
 * @param object $quizp the quizp object.
 */
function quizp_delete_attempt($attempt, $quizp) {
    global $DB;
    if (is_numeric($attempt)) {
        if (!$attempt = $DB->get_record('quizp_attempts', array('id' => $attempt))) {
            return;
        }
    }

    if ($attempt->quizp != $quizp->id) {
        debugging("Trying to delete attempt $attempt->id which belongs to quizp $attempt->quizp " .
                "but was passed quizp $quizp->id.");
        return;
    }

    if (!isset($quizp->cmid)) {
        $cm = get_coursemodule_from_instance('quizp', $quizp->id, $quizp->course);
        $quizp->cmid = $cm->id;
    }

    question_engine::delete_questions_usage_by_activity($attempt->uniqueid);
    $DB->delete_records('quizp_attempts', array('id' => $attempt->id));

    // Log the deletion of the attempt if not a preview.
    if (!$attempt->preview) {
        $params = array(
            'objectid' => $attempt->id,
            'relateduserid' => $attempt->userid,
            'context' => context_module::instance($quizp->cmid),
            'other' => array(
                'quizpid' => $quizp->id
            )
        );
        $event = \mod_quizp\event\attempt_deleted::create($params);
        $event->add_record_snapshot('quizp_attempts', $attempt);
        $event->trigger();
    }

    // Search quizp_attempts for other instances by this user.
    // If none, then delete record for this quizp, this user from quizp_grades
    // else recalculate best grade.
    $userid = $attempt->userid;
    if (!$DB->record_exists('quizp_attempts', array('userid' => $userid, 'quizp' => $quizp->id))) {
        $DB->delete_records('quizp_grades', array('userid' => $userid, 'quizp' => $quizp->id));
    } else {
        quizp_save_best_grade($quizp, $userid);
    }

    quizp_update_grades($quizp, $userid);
}

/**
 * Delete all the preview attempts at a quizp, or possibly all the attempts belonging
 * to one user.
 * @param object $quizp the quizp object.
 * @param int $userid (optional) if given, only delete the previews belonging to this user.
 */
function quizp_delete_previews($quizp, $userid = null) {
    global $DB;
    $conditions = array('quizp' => $quizp->id, 'preview' => 1);
    if (!empty($userid)) {
        $conditions['userid'] = $userid;
    }
    $previewattempts = $DB->get_records('quizp_attempts', $conditions);
    foreach ($previewattempts as $attempt) {
        quizp_delete_attempt($attempt, $quizp);
    }
}

/**
 * @param int $quizpid The quizp id.
 * @return bool whether this quizp has any (non-preview) attempts.
 */
function quizp_has_attempts($quizpid) {
    global $DB;
    return $DB->record_exists('quizp_attempts', array('quizp' => $quizpid, 'preview' => 0));
}

// Functions to do with quizp layout and pages //////////////////////////////////

/**
 * Repaginate the questions in a quizp
 * @param int $quizpid the id of the quizp to repaginate.
 * @param int $slotsperpage number of items to put on each page. 0 means unlimited.
 */
function quizp_repaginate_questions($quizpid, $slotsperpage) {
    global $DB;
    $trans = $DB->start_delegated_transaction();

    $sections = $DB->get_records('quizp_sections', array('quizpid' => $quizpid), 'firstslot ASC');
    $firstslots = array();
    foreach ($sections as $section) {
        if ((int)$section->firstslot === 1) {
            continue;
        }
        $firstslots[] = $section->firstslot;
    }

    $slots = $DB->get_records('quizp_slots', array('quizpid' => $quizpid),
            'slot');
    $currentpage = 1;
    $slotsonthispage = 0;
    foreach ($slots as $slot) {
        if (($firstslots && in_array($slot->slot, $firstslots)) ||
            ($slotsonthispage && $slotsonthispage == $slotsperpage)) {
            $currentpage += 1;
            $slotsonthispage = 0;
        }
        if ($slot->page != $currentpage) {
            $DB->set_field('quizp_slots', 'page', $currentpage, array('id' => $slot->id));
        }
        $slotsonthispage += 1;
    }

    $trans->allow_commit();
}

// Functions to do with quizp grades ////////////////////////////////////////////

/**
 * Convert the raw grade stored in $attempt into a grade out of the maximum
 * grade for this quizp.
 *
 * @param float $rawgrade the unadjusted grade, fof example $attempt->sumgrades
 * @param object $quizp the quizp object. Only the fields grade, sumgrades and decimalpoints are used.
 * @param bool|string $format whether to format the results for display
 *      or 'question' to format a question grade (different number of decimal places.
 * @return float|string the rescaled grade, or null/the lang string 'notyetgraded'
 *      if the $grade is null.
 */
function quizp_rescale_grade($rawgrade, $quizp, $format = true) {
    if (is_null($rawgrade)) {
        $grade = null;
    } else if ($quizp->sumgrades >= 0.000005) {
        $grade = $rawgrade * $quizp->grade / $quizp->sumgrades;
    } else {
        $grade = 0;
    }
    if ($format === 'question') {
        $grade = quizp_format_question_grade($quizp, $grade);
    } else if ($format) {
        $grade = quizp_format_grade($quizp, $grade);
    }
    return $grade;
}

/**
 * Get the feedback text that should be show to a student who
 * got this grade on this quizp. The feedback is processed ready for diplay.
 *
 * @param float $grade a grade on this quizp.
 * @param object $quizp the quizp settings.
 * @param object $context the quizp context.
 * @return string the comment that corresponds to this grade (empty string if there is not one.
 */
function quizp_feedback_for_grade($grade, $quizp, $context) {
    global $DB;

    if (is_null($grade)) {
        return '';
    }

    // With CBM etc, it is possible to get -ve grades, which would then not match
    // any feedback. Therefore, we replace -ve grades with 0.
    $grade = max($grade, 0);

    $feedback = $DB->get_record_select('quizp_feedback',
            'quizpid = ? AND mingrade <= ? AND ? < maxgrade', array($quizp->id, $grade, $grade));

    if (empty($feedback->feedbacktext)) {
        return '';
    }

    // Clean the text, ready for display.
    $formatoptions = new stdClass();
    $formatoptions->noclean = true;
    $feedbacktext = file_rewrite_pluginfile_urls($feedback->feedbacktext, 'pluginfile.php',
            $context->id, 'mod_quizp', 'feedback', $feedback->id);
    $feedbacktext = format_text($feedbacktext, $feedback->feedbacktextformat, $formatoptions);

    return $feedbacktext;
}

/**
 * @param object $quizp the quizp database row.
 * @return bool Whether this quizp has any non-blank feedback text.
 */
function quizp_has_feedback($quizp) {
    global $DB;
    static $cache = array();
    if (!array_key_exists($quizp->id, $cache)) {
        $cache[$quizp->id] = quizp_has_grades($quizp) &&
                $DB->record_exists_select('quizp_feedback', "quizpid = ? AND " .
                    $DB->sql_isnotempty('quizp_feedback', 'feedbacktext', false, true),
                array($quizp->id));
    }
    return $cache[$quizp->id];
}

/**
 * Update the sumgrades field of the quizp. This needs to be called whenever
 * the grading structure of the quizp is changed. For example if a question is
 * added or removed, or a question weight is changed.
 *
 * You should call {@link quizp_delete_previews()} before you call this function.
 *
 * @param object $quizp a quizp.
 */
function quizp_update_sumgrades($quizp) {
    global $DB;

    $sql = 'UPDATE {quizp}
            SET sumgrades = COALESCE((
                SELECT SUM(maxmark)
                FROM {quizp_slots}
                WHERE quizpid = {quizp}.id
            ), 0)
            WHERE id = ?';
    $DB->execute($sql, array($quizp->id));
    $quizp->sumgrades = $DB->get_field('quizp', 'sumgrades', array('id' => $quizp->id));

    if ($quizp->sumgrades < 0.000005 && quizp_has_attempts($quizp->id)) {
        // If the quizp has been attempted, and the sumgrades has been
        // set to 0, then we must also set the maximum possible grade to 0, or
        // we will get a divide by zero error.
        quizp_set_grade(0, $quizp);
    }
}

/**
 * Update the sumgrades field of the attempts at a quizp.
 *
 * @param object $quizp a quizp.
 */
function quizp_update_all_attempt_sumgrades($quizp) {
    global $DB;
    $dm = new question_engine_data_mapper();
    $timenow = time();

    $sql = "UPDATE {quizp_attempts}
            SET
                timemodified = :timenow,
                sumgrades = (
                    {$dm->sum_usage_marks_subquery('uniqueid')}
                )
            WHERE quizp = :quizpid AND state = :finishedstate";
    $DB->execute($sql, array('timenow' => $timenow, 'quizpid' => $quizp->id,
            'finishedstate' => quizp_attempt::FINISHED));
}

/**
 * The quizp grade is the maximum that student's results are marked out of. When it
 * changes, the corresponding data in quizp_grades and quizp_feedback needs to be
 * rescaled. After calling this function, you probably need to call
 * quizp_update_all_attempt_sumgrades, quizp_update_all_final_grades and
 * quizp_update_grades.
 *
 * @param float $newgrade the new maximum grade for the quizp.
 * @param object $quizp the quizp we are updating. Passed by reference so its
 *      grade field can be updated too.
 * @return bool indicating success or failure.
 */
function quizp_set_grade($newgrade, $quizp) {
    global $DB;
    // This is potentially expensive, so only do it if necessary.
    if (abs($quizp->grade - $newgrade) < 1e-7) {
        // Nothing to do.
        return true;
    }

    $oldgrade = $quizp->grade;
    $quizp->grade = $newgrade;

    // Use a transaction, so that on those databases that support it, this is safer.
    $transaction = $DB->start_delegated_transaction();

    // Update the quizp table.
    $DB->set_field('quizp', 'grade', $newgrade, array('id' => $quizp->instance));

    if ($oldgrade < 1) {
        // If the old grade was zero, we cannot rescale, we have to recompute.
        // We also recompute if the old grade was too small to avoid underflow problems.
        quizp_update_all_final_grades($quizp);

    } else {
        // We can rescale the grades efficiently.
        $timemodified = time();
        $DB->execute("
                UPDATE {quizp_grades}
                SET grade = ? * grade, timemodified = ?
                WHERE quizp = ?
        ", array($newgrade/$oldgrade, $timemodified, $quizp->id));
    }

    if ($oldgrade > 1e-7) {
        // Update the quizp_feedback table.
        $factor = $newgrade/$oldgrade;
        $DB->execute("
                UPDATE {quizp_feedback}
                SET mingrade = ? * mingrade, maxgrade = ? * maxgrade
                WHERE quizpid = ?
        ", array($factor, $factor, $quizp->id));
    }

    // Update grade item and send all grades to gradebook.
    quizp_grade_item_update($quizp);
    quizp_update_grades($quizp);

    $transaction->allow_commit();
    return true;
}

/**
 * Save the overall grade for a user at a quizp in the quizp_grades table
 *
 * @param object $quizp The quizp for which the best grade is to be calculated and then saved.
 * @param int $userid The userid to calculate the grade for. Defaults to the current user.
 * @param array $attempts The attempts of this user. Useful if you are
 * looping through many users. Attempts can be fetched in one master query to
 * avoid repeated querying.
 * @return bool Indicates success or failure.
 */
function quizp_save_best_grade($quizp, $userid = null, $attempts = array()) {
    global $DB, $OUTPUT, $USER;

    if (empty($userid)) {
        $userid = $USER->id;
    }

    if (!$attempts) {
        // Get all the attempts made by the user.
        $attempts = quizp_get_user_attempts($quizp->id, $userid);
    }

    // Calculate the best grade.
    $bestgrade = quizp_calculate_best_grade($quizp, $attempts);
    $bestgrade = quizp_rescale_grade($bestgrade, $quizp, false);

    // Save the best grade in the database.
    if (is_null($bestgrade)) {
        $DB->delete_records('quizp_grades', array('quizp' => $quizp->id, 'userid' => $userid));

    } else if ($grade = $DB->get_record('quizp_grades',
            array('quizp' => $quizp->id, 'userid' => $userid))) {
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->update_record('quizp_grades', $grade);

    } else {
        $grade = new stdClass();
        $grade->quizp = $quizp->id;
        $grade->userid = $userid;
        $grade->grade = $bestgrade;
        $grade->timemodified = time();
        $DB->insert_record('quizp_grades', $grade);
    }

    quizp_update_grades($quizp, $userid);
}

/**
 * Calculate the overall grade for a quizp given a number of attempts by a particular user.
 *
 * @param object $quizp    the quizp settings object.
 * @param array $attempts an array of all the user's attempts at this quizp in order.
 * @return float          the overall grade
 */
function quizp_calculate_best_grade($quizp, $attempts) {

    switch ($quizp->grademethod) {

        case QUIZP_ATTEMPTFIRST:
            $firstattempt = reset($attempts);
            return $firstattempt->sumgrades;

        case QUIZP_ATTEMPTLAST:
            $lastattempt = end($attempts);
            return $lastattempt->sumgrades;

        case QUIZP_GRADEAVERAGE:
            $sum = 0;
            $count = 0;
            foreach ($attempts as $attempt) {
                if (!is_null($attempt->sumgrades)) {
                    $sum += $attempt->sumgrades;
                    $count++;
                }
            }
            if ($count == 0) {
                return null;
            }
            return $sum / $count;

        case QUIZP_GRADEHIGHEST:
        default:
            $max = null;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                }
            }
            return $max;
    }
}

/**
 * Update the final grade at this quizp for all students.
 *
 * This function is equivalent to calling quizp_save_best_grade for all
 * users, but much more efficient.
 *
 * @param object $quizp the quizp settings.
 */
function quizp_update_all_final_grades($quizp) {
    global $DB;

    if (!$quizp->sumgrades) {
        return;
    }

    $param = array('iquizpid' => $quizp->id, 'istatefinished' => quizp_attempt::FINISHED);
    $firstlastattemptjoin = "JOIN (
            SELECT
                iquizpa.userid,
                MIN(attempt) AS firstattempt,
                MAX(attempt) AS lastattempt

            FROM {quizp_attempts} iquizpa

            WHERE
                iquizpa.state = :istatefinished AND
                iquizpa.preview = 0 AND
                iquizpa.quizp = :iquizpid

            GROUP BY iquizpa.userid
        ) first_last_attempts ON first_last_attempts.userid = quizpa.userid";

    switch ($quizp->grademethod) {
        case QUIZP_ATTEMPTFIRST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quizpa.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quizpa.attempt = first_last_attempts.firstattempt AND';
            break;

        case QUIZP_ATTEMPTLAST:
            // Because of the where clause, there will only be one row, but we
            // must still use an aggregate function.
            $select = 'MAX(quizpa.sumgrades)';
            $join = $firstlastattemptjoin;
            $where = 'quizpa.attempt = first_last_attempts.lastattempt AND';
            break;

        case QUIZP_GRADEAVERAGE:
            $select = 'AVG(quizpa.sumgrades)';
            $join = '';
            $where = '';
            break;

        default:
        case QUIZP_GRADEHIGHEST:
            $select = 'MAX(quizpa.sumgrades)';
            $join = '';
            $where = '';
            break;
    }

    if ($quizp->sumgrades >= 0.000005) {
        $finalgrade = $select . ' * ' . ($quizp->grade / $quizp->sumgrades);
    } else {
        $finalgrade = '0';
    }
    $param['quizpid'] = $quizp->id;
    $param['quizpid2'] = $quizp->id;
    $param['quizpid3'] = $quizp->id;
    $param['quizpid4'] = $quizp->id;
    $param['statefinished'] = quizp_attempt::FINISHED;
    $param['statefinished2'] = quizp_attempt::FINISHED;
    $finalgradesubquery = "
            SELECT quizpa.userid, $finalgrade AS newgrade
            FROM {quizp_attempts} quizpa
            $join
            WHERE
                $where
                quizpa.state = :statefinished AND
                quizpa.preview = 0 AND
                quizpa.quizp = :quizpid3
            GROUP BY quizpa.userid";

    $changedgrades = $DB->get_records_sql("
            SELECT users.userid, qg.id, qg.grade, newgrades.newgrade

            FROM (
                SELECT userid
                FROM {quizp_grades} qg
                WHERE quizp = :quizpid
            UNION
                SELECT DISTINCT userid
                FROM {quizp_attempts} quizpa2
                WHERE
                    quizpa2.state = :statefinished2 AND
                    quizpa2.preview = 0 AND
                    quizpa2.quizp = :quizpid2
            ) users

            LEFT JOIN {quizp_grades} qg ON qg.userid = users.userid AND qg.quizp = :quizpid4

            LEFT JOIN (
                $finalgradesubquery
            ) newgrades ON newgrades.userid = users.userid

            WHERE
                ABS(newgrades.newgrade - qg.grade) > 0.000005 OR
                ((newgrades.newgrade IS NULL OR qg.grade IS NULL) AND NOT
                          (newgrades.newgrade IS NULL AND qg.grade IS NULL))",
                // The mess on the previous line is detecting where the value is
                // NULL in one column, and NOT NULL in the other, but SQL does
                // not have an XOR operator, and MS SQL server can't cope with
                // (newgrades.newgrade IS NULL) <> (qg.grade IS NULL).
            $param);

    $timenow = time();
    $todelete = array();
    foreach ($changedgrades as $changedgrade) {

        if (is_null($changedgrade->newgrade)) {
            $todelete[] = $changedgrade->userid;

        } else if (is_null($changedgrade->grade)) {
            $toinsert = new stdClass();
            $toinsert->quizp = $quizp->id;
            $toinsert->userid = $changedgrade->userid;
            $toinsert->timemodified = $timenow;
            $toinsert->grade = $changedgrade->newgrade;
            $DB->insert_record('quizp_grades', $toinsert);

        } else {
            $toupdate = new stdClass();
            $toupdate->id = $changedgrade->id;
            $toupdate->grade = $changedgrade->newgrade;
            $toupdate->timemodified = $timenow;
            $DB->update_record('quizp_grades', $toupdate);
        }
    }

    if (!empty($todelete)) {
        list($test, $params) = $DB->get_in_or_equal($todelete);
        $DB->delete_records_select('quizp_grades', 'quizp = ? AND userid ' . $test,
                array_merge(array($quizp->id), $params));
    }
}

/**
 * Efficiently update check state time on all open attempts
 *
 * @param array $conditions optional restrictions on which attempts to update
 *                    Allowed conditions:
 *                      courseid => (array|int) attempts in given course(s)
 *                      userid   => (array|int) attempts for given user(s)
 *                      quizpid   => (array|int) attempts in given quizp(s)
 *                      groupid  => (array|int) quizpzes with some override for given group(s)
 *
 */
function quizp_update_open_attempts(array $conditions) {
    global $DB;

    foreach ($conditions as &$value) {
        if (!is_array($value)) {
            $value = array($value);
        }
    }

    $params = array();
    $wheres = array("quizpa.state IN ('inprogress', 'overdue')");
    $iwheres = array("iquizpa.state IN ('inprogress', 'overdue')");

    if (isset($conditions['courseid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'cid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quizpa.quizp IN (SELECT q.id FROM {quizp} q WHERE q.course $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['courseid'], SQL_PARAMS_NAMED, 'icid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquizpa.quizp IN (SELECT q.id FROM {quizp} q WHERE q.course $incond)";
    }

    if (isset($conditions['userid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'uid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quizpa.userid $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['userid'], SQL_PARAMS_NAMED, 'iuid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquizpa.userid $incond";
    }

    if (isset($conditions['quizpid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['quizpid'], SQL_PARAMS_NAMED, 'qid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quizpa.quizp $incond";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['quizpid'], SQL_PARAMS_NAMED, 'iqid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquizpa.quizp $incond";
    }

    if (isset($conditions['groupid'])) {
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'gid');
        $params = array_merge($params, $inparams);
        $wheres[] = "quizpa.quizp IN (SELECT qo.quizp FROM {quizp_overrides} qo WHERE qo.groupid $incond)";
        list ($incond, $inparams) = $DB->get_in_or_equal($conditions['groupid'], SQL_PARAMS_NAMED, 'igid');
        $params = array_merge($params, $inparams);
        $iwheres[] = "iquizpa.quizp IN (SELECT qo.quizp FROM {quizp_overrides} qo WHERE qo.groupid $incond)";
    }

    // SQL to compute timeclose and timelimit for each attempt:
    $quizpausersql = quizp_get_attempt_usertime_sql(
            implode("\n                AND ", $iwheres));

    // SQL to compute the new timecheckstate
    $timecheckstatesql = "
          CASE WHEN quizpauser.usertimelimit = 0 AND quizpauser.usertimeclose = 0 THEN NULL
               WHEN quizpauser.usertimelimit = 0 THEN quizpauser.usertimeclose
               WHEN quizpauser.usertimeclose = 0 THEN quizpa.timestart + quizpauser.usertimelimit
               WHEN quizpa.timestart + quizpauser.usertimelimit < quizpauser.usertimeclose THEN quizpa.timestart + quizpauser.usertimelimit
               ELSE quizpauser.usertimeclose END +
          CASE WHEN quizpa.state = 'overdue' THEN quizp.graceperiod ELSE 0 END";

    // SQL to select which attempts to process
    $attemptselect = implode("\n                         AND ", $wheres);

   /*
    * Each database handles updates with inner joins differently:
    *  - mysql does not allow a FROM clause
    *  - postgres and mssql allow FROM but handle table aliases differently
    *  - oracle requires a subquery
    *
    * Different code for each database.
    */

    $dbfamily = $DB->get_dbfamily();
    if ($dbfamily == 'mysql') {
        $updatesql = "UPDATE {quizp_attempts} quizpa
                        JOIN {quizp} quizp ON quizp.id = quizpa.quizp
                        JOIN ( $quizpausersql ) quizpauser ON quizpauser.id = quizpa.id
                         SET quizpa.timecheckstate = $timecheckstatesql
                       WHERE $attemptselect";
    } else if ($dbfamily == 'postgres') {
        $updatesql = "UPDATE {quizp_attempts} quizpa
                         SET timecheckstate = $timecheckstatesql
                        FROM {quizp} quizp, ( $quizpausersql ) quizpauser
                       WHERE quizp.id = quizpa.quizp
                         AND quizpauser.id = quizpa.id
                         AND $attemptselect";
    } else if ($dbfamily == 'mssql') {
        $updatesql = "UPDATE quizpa
                         SET timecheckstate = $timecheckstatesql
                        FROM {quizp_attempts} quizpa
                        JOIN {quizp} quizp ON quizp.id = quizpa.quizp
                        JOIN ( $quizpausersql ) quizpauser ON quizpauser.id = quizpa.id
                       WHERE $attemptselect";
    } else {
        // oracle, sqlite and others
        $updatesql = "UPDATE {quizp_attempts} quizpa
                         SET timecheckstate = (
                           SELECT $timecheckstatesql
                             FROM {quizp} quizp, ( $quizpausersql ) quizpauser
                            WHERE quizp.id = quizpa.quizp
                              AND quizpauser.id = quizpa.id
                         )
                         WHERE $attemptselect";
    }

    $DB->execute($updatesql, $params);
}

/**
 * Returns SQL to compute timeclose and timelimit for every attempt, taking into account user and group overrides.
 *
 * @param string $redundantwhereclauses extra where clauses to add to the subquery
 *      for performance. These can use the table alias iquizpa for the quizp attempts table.
 * @return string SQL select with columns attempt.id, usertimeclose, usertimelimit.
 */
function quizp_get_attempt_usertime_sql($redundantwhereclauses = '') {
    if ($redundantwhereclauses) {
        $redundantwhereclauses = 'WHERE ' . $redundantwhereclauses;
    }
    // The multiple qgo JOINS are necessary because we want timeclose/timelimit = 0 (unlimited) to supercede
    // any other group override
    $quizpausersql = "
          SELECT iquizpa.id,
           COALESCE(MAX(quo.timeclose), MAX(qgo1.timeclose), MAX(qgo2.timeclose), iquizp.timeclose) AS usertimeclose,
           COALESCE(MAX(quo.timelimit), MAX(qgo3.timelimit), MAX(qgo4.timelimit), iquizp.timelimit) AS usertimelimit

           FROM {quizp_attempts} iquizpa
           JOIN {quizp} iquizp ON iquizp.id = iquizpa.quizp
      LEFT JOIN {quizp_overrides} quo ON quo.quizp = iquizpa.quizp AND quo.userid = iquizpa.userid
      LEFT JOIN {groups_members} gm ON gm.userid = iquizpa.userid
      LEFT JOIN {quizp_overrides} qgo1 ON qgo1.quizp = iquizpa.quizp AND qgo1.groupid = gm.groupid AND qgo1.timeclose = 0
      LEFT JOIN {quizp_overrides} qgo2 ON qgo2.quizp = iquizpa.quizp AND qgo2.groupid = gm.groupid AND qgo2.timeclose > 0
      LEFT JOIN {quizp_overrides} qgo3 ON qgo3.quizp = iquizpa.quizp AND qgo3.groupid = gm.groupid AND qgo3.timelimit = 0
      LEFT JOIN {quizp_overrides} qgo4 ON qgo4.quizp = iquizpa.quizp AND qgo4.groupid = gm.groupid AND qgo4.timelimit > 0
          $redundantwhereclauses
       GROUP BY iquizpa.id, iquizp.id, iquizp.timeclose, iquizp.timelimit";
    return $quizpausersql;
}

/**
 * Return the attempt with the best grade for a quizp
 *
 * Which attempt is the best depends on $quizp->grademethod. If the grade
 * method is GRADEAVERAGE then this function simply returns the last attempt.
 * @return object         The attempt with the best grade
 * @param object $quizp    The quizp for which the best grade is to be calculated
 * @param array $attempts An array of all the attempts of the user at the quizp
 */
function quizp_calculate_best_attempt($quizp, $attempts) {

    switch ($quizp->grademethod) {

        case QUIZP_ATTEMPTFIRST:
            foreach ($attempts as $attempt) {
                return $attempt;
            }
            break;

        case QUIZP_GRADEAVERAGE: // We need to do something with it.
        case QUIZP_ATTEMPTLAST:
            foreach ($attempts as $attempt) {
                $final = $attempt;
            }
            return $final;

        default:
        case QUIZP_GRADEHIGHEST:
            $max = -1;
            foreach ($attempts as $attempt) {
                if ($attempt->sumgrades > $max) {
                    $max = $attempt->sumgrades;
                    $maxattempt = $attempt;
                }
            }
            return $maxattempt;
    }
}

/**
 * @return array int => lang string the options for calculating the quizp grade
 *      from the individual attempt grades.
 */
function quizp_get_grading_options() {
    return array(
        QUIZP_GRADEHIGHEST => get_string('gradehighest', 'quizp'),
        QUIZP_GRADEAVERAGE => get_string('gradeaverage', 'quizp'),
        QUIZP_ATTEMPTFIRST => get_string('attemptfirst', 'quizp'),
        QUIZP_ATTEMPTLAST  => get_string('attemptlast', 'quizp')
    );
}

/**
 * @param int $option one of the values QUIZP_GRADEHIGHEST, QUIZP_GRADEAVERAGE,
 *      QUIZP_ATTEMPTFIRST or QUIZP_ATTEMPTLAST.
 * @return the lang string for that option.
 */
function quizp_get_grading_option_name($option) {
    $strings = quizp_get_grading_options();
    return $strings[$option];
}

/**
 * @return array string => lang string the options for handling overdue quizp
 *      attempts.
 */
function quizp_get_overdue_handling_options() {
    return array(
        'autosubmit'  => get_string('overduehandlingautosubmit', 'quizp'),
        'graceperiod' => get_string('overduehandlinggraceperiod', 'quizp'),
        'autoabandon' => get_string('overduehandlingautoabandon', 'quizp'),
    );
}

/**
 * Get the choices for what size user picture to show.
 * @return array string => lang string the options for whether to display the user's picture.
 */
function quizp_get_user_image_options() {
    return array(
        QUIZP_SHOWIMAGE_NONE  => get_string('shownoimage', 'quizp'),
        QUIZP_SHOWIMAGE_SMALL => get_string('showsmallimage', 'quizp'),
        QUIZP_SHOWIMAGE_LARGE => get_string('showlargeimage', 'quizp'),
    );
}

/**
 * Get the choices to offer for the 'Questions per page' option.
 * @return array int => string.
 */
function quizp_questions_per_page_options() {
    $pageoptions = array();
    $pageoptions[0] = get_string('neverallononepage', 'quizp');
    $pageoptions[1] = get_string('everyquestion', 'quizp');
    for ($i = 2; $i <= QUIZP_MAX_QPP_OPTION; ++$i) {
        $pageoptions[$i] = get_string('everynquestions', 'quizp', $i);
    }
    return $pageoptions;
}

/**
 * Get the human-readable name for a quizp attempt state.
 * @param string $state one of the state constants like {@link quizp_attempt::IN_PROGRESS}.
 * @return string The lang string to describe that state.
 */
function quizp_attempt_state_name($state) {
    switch ($state) {
        case quizp_attempt::IN_PROGRESS:
            return get_string('stateinprogress', 'quizp');
        case quizp_attempt::OVERDUE:
            return get_string('stateoverdue', 'quizp');
        case quizp_attempt::FINISHED:
            return get_string('statefinished', 'quizp');
        case quizp_attempt::ABANDONED:
            return get_string('stateabandoned', 'quizp');
        default:
            throw new coding_exception('Unknown quizp attempt state.');
    }
}

// Other quizp functions ////////////////////////////////////////////////////////

/**
 * @param object $quizp the quizp.
 * @param int $cmid the course_module object for this quizp.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param int $variant which question variant to preview (optional).
 * @return string html for a number of icons linked to action pages for a
 * question - preview and edit / view icons depending on user capabilities.
 */
function quizp_question_action_icons($quizp, $cmid, $question, $returnurl, $variant = null) {
    $html = quizp_question_preview_button($quizp, $question, false, $variant) . ' ' .
            quizp_question_edit_button($cmid, $question, $returnurl);
    return $html;
}

/**
 * @param int $cmid the course_module.id for this quizp.
 * @param object $question the question.
 * @param string $returnurl url to return to after action is done.
 * @param string $contentbeforeicon some HTML content to be added inside the link, before the icon.
 * @return the HTML for an edit icon, view icon, or nothing for a question
 *      (depending on permissions).
 */
function quizp_question_edit_button($cmid, $question, $returnurl, $contentaftericon = '') {
    global $CFG, $OUTPUT;

    // Minor efficiency saving. Only get strings once, even if there are a lot of icons on one page.
    static $stredit = null;
    static $strview = null;
    if ($stredit === null) {
        $stredit = get_string('edit');
        $strview = get_string('view');
    }

    // What sort of icon should we show?
    $action = '';
    if (!empty($question->id) &&
            (question_has_capability_on($question, 'edit', $question->category) ||
                    question_has_capability_on($question, 'move', $question->category))) {
        $action = $stredit;
        $icon = '/t/edit';
    } else if (!empty($question->id) &&
            question_has_capability_on($question, 'view', $question->category)) {
        $action = $strview;
        $icon = '/i/info';
    }

    // Build the icon.
    if ($action) {
        if ($returnurl instanceof moodle_url) {
            $returnurl = $returnurl->out_as_local_url(false);
        }
        $questionparams = array('returnurl' => $returnurl, 'cmid' => $cmid, 'id' => $question->id);
        $questionurl = new moodle_url("$CFG->wwwroot/question/question.php", $questionparams);
        return '<a title="' . $action . '" href="' . $questionurl->out() . '" class="questioneditbutton"><img src="' .
                $OUTPUT->pix_url($icon) . '" alt="' . $action . '" />' . $contentaftericon .
                '</a>';
    } else if ($contentaftericon) {
        return '<span class="questioneditbutton">' . $contentaftericon . '</span>';
    } else {
        return '';
    }
}

/**
 * @param object $quizp the quizp settings
 * @param object $question the question
 * @param int $variant which question variant to preview (optional).
 * @return moodle_url to preview this question with the options from this quizp.
 */
function quizp_question_preview_url($quizp, $question, $variant = null) {
    // Get the appropriate display options.
    $displayoptions = mod_quizp_display_options::make_from_quizp($quizp,
            mod_quizp_display_options::DURING);

    $maxmark = null;
    if (isset($question->maxmark)) {
        $maxmark = $question->maxmark;
    }

    // Work out the correcte preview URL.
    return question_preview_url($question->id, $quizp->preferredbehaviour,
            $maxmark, $displayoptions, $variant);
}

/**
 * @param object $quizp the quizp settings
 * @param object $question the question
 * @param bool $label if true, show the preview question label after the icon
 * @param int $variant which question variant to preview (optional).
 * @return the HTML for a preview question icon.
 */
function quizp_question_preview_button($quizp, $question, $label = false, $variant = null) {
    global $PAGE;
    if (!question_has_capability_on($question, 'use', $question->category)) {
        return '';
    }

    return $PAGE->get_renderer('mod_quizp', 'edit')->question_preview_icon($quizp, $question, $label, $variant);
}

/**
 * @param object $attempt the attempt.
 * @param object $context the quizp context.
 * @return int whether flags should be shown/editable to the current user for this attempt.
 */
function quizp_get_flag_option($attempt, $context) {
    global $USER;
    if (!has_capability('moodle/question:flag', $context)) {
        return question_display_options::HIDDEN;
    } else if ($attempt->userid == $USER->id) {
        return question_display_options::EDITABLE;
    } else {
        return question_display_options::VISIBLE;
    }
}

/**
 * Work out what state this quizp attempt is in - in the sense used by
 * quizp_get_review_options, not in the sense of $attempt->state.
 * @param object $quizp the quizp settings
 * @param object $attempt the quizp_attempt database row.
 * @return int one of the mod_quizp_display_options::DURING,
 *      IMMEDIATELY_AFTER, LATER_WHILE_OPEN or AFTER_CLOSE constants.
 */
function quizp_attempt_state($quizp, $attempt) {
    if ($attempt->state == quizp_attempt::IN_PROGRESS) {
        return mod_quizp_display_options::DURING;
    } else if (time() < $attempt->timefinish + 120) {
        return mod_quizp_display_options::IMMEDIATELY_AFTER;
    } else if (!$quizp->timeclose || time() < $quizp->timeclose) {
        return mod_quizp_display_options::LATER_WHILE_OPEN;
    } else {
        return mod_quizp_display_options::AFTER_CLOSE;
    }
}

/**
 * The the appropraite mod_quizp_display_options object for this attempt at this
 * quizp right now.
 *
 * @param object $quizp the quizp instance.
 * @param object $attempt the attempt in question.
 * @param $context the quizp context.
 *
 * @return mod_quizp_display_options
 */
function quizp_get_review_options($quizp, $attempt, $context) {
    $options = mod_quizp_display_options::make_from_quizp($quizp, quizp_attempt_state($quizp, $attempt));

    $options->readonly = true;
    $options->flags = quizp_get_flag_option($attempt, $context);
    if (!empty($attempt->id)) {
        $options->questionreviewlink = new moodle_url('/mod/quizp/reviewquestion.php',
                array('attempt' => $attempt->id));
    }

    // Show a link to the comment box only for closed attempts.
    if (!empty($attempt->id) && $attempt->state == quizp_attempt::FINISHED && !$attempt->preview &&
            !is_null($context) && has_capability('mod/quizp:grade', $context)) {
        $options->manualcomment = question_display_options::VISIBLE;
        $options->manualcommentlink = new moodle_url('/mod/quizp/comment.php',
                array('attempt' => $attempt->id));
    }

    if (!is_null($context) && !$attempt->preview &&
            has_capability('mod/quizp:viewreports', $context) &&
            has_capability('moodle/grade:viewhidden', $context)) {
        // People who can see reports and hidden grades should be shown everything,
        // except during preview when teachers want to see what students see.
        $options->attempt = question_display_options::VISIBLE;
        $options->correctness = question_display_options::VISIBLE;
        $options->marks = question_display_options::MARK_AND_MAX;
        $options->feedback = question_display_options::VISIBLE;
        $options->numpartscorrect = question_display_options::VISIBLE;
        $options->manualcomment = question_display_options::VISIBLE;
        $options->generalfeedback = question_display_options::VISIBLE;
        $options->rightanswer = question_display_options::VISIBLE;
        $options->overallfeedback = question_display_options::VISIBLE;
        $options->history = question_display_options::VISIBLE;

    }

    return $options;
}

/**
 * Combines the review options from a number of different quizp attempts.
 * Returns an array of two ojects, so the suggested way of calling this
 * funciton is:
 * list($someoptions, $alloptions) = quizp_get_combined_reviewoptions(...)
 *
 * @param object $quizp the quizp instance.
 * @param array $attempts an array of attempt objects.
 *
 * @return array of two options objects, one showing which options are true for
 *          at least one of the attempts, the other showing which options are true
 *          for all attempts.
 */
function quizp_get_combined_reviewoptions($quizp, $attempts) {
    $fields = array('feedback', 'generalfeedback', 'rightanswer', 'overallfeedback');
    $someoptions = new stdClass();
    $alloptions = new stdClass();
    foreach ($fields as $field) {
        $someoptions->$field = false;
        $alloptions->$field = true;
    }
    $someoptions->marks = question_display_options::HIDDEN;
    $alloptions->marks = question_display_options::MARK_AND_MAX;

    foreach ($attempts as $attempt) {
        $attemptoptions = mod_quizp_display_options::make_from_quizp($quizp,
                quizp_attempt_state($quizp, $attempt));
        foreach ($fields as $field) {
            $someoptions->$field = $someoptions->$field || $attemptoptions->$field;
            $alloptions->$field = $alloptions->$field && $attemptoptions->$field;
        }
        $someoptions->marks = max($someoptions->marks, $attemptoptions->marks);
        $alloptions->marks = min($alloptions->marks, $attemptoptions->marks);
    }
    return array($someoptions, $alloptions);
}

// Functions for sending notification messages /////////////////////////////////

/**
 * Sends a confirmation message to the student confirming that the attempt was processed.
 *
 * @param object $a lots of useful information that can be used in the message
 *      subject and body.
 *
 * @return int|false as for {@link message_send()}.
 */
function quizp_send_confirmation($recipient, $a) {

    // Add information about the recipient to $a.
    // Don't do idnumber. we want idnumber to be the submitter's idnumber.
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_quizp';
    $eventdata->name              = 'confirmation';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailconfirmsubject', 'quizp', $a);
    $eventdata->fullmessage       = get_string('emailconfirmbody', 'quizp', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailconfirmsmall', 'quizp', $a);
    $eventdata->contexturl        = $a->quizpurl;
    $eventdata->contexturlname    = $a->quizpname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Sends notification messages to the interested parties that assign the role capability
 *
 * @param object $recipient user object of the intended recipient
 * @param object $a associative array of replaceable fields for the templates
 *
 * @return int|false as for {@link message_send()}.
 */
function quizp_send_notification($recipient, $submitter, $a) {

    // Recipient info for template.
    $a->useridnumber = $recipient->idnumber;
    $a->username     = fullname($recipient);
    $a->userusername = $recipient->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_quizp';
    $eventdata->name              = 'submission';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = $submitter;
    $eventdata->userto            = $recipient;
    $eventdata->subject           = get_string('emailnotifysubject', 'quizp', $a);
    $eventdata->fullmessage       = get_string('emailnotifybody', 'quizp', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailnotifysmall', 'quizp', $a);
    $eventdata->contexturl        = $a->quizpreviewurl;
    $eventdata->contexturlname    = $a->quizpname;

    // ... and send it.
    return message_send($eventdata);
}

/**
 * Send all the requried messages when a quizp attempt is submitted.
 *
 * @param object $course the course
 * @param object $quizp the quizp
 * @param object $attempt this attempt just finished
 * @param object $context the quizp context
 * @param object $cm the coursemodule for this quizp
 *
 * @return bool true if all necessary messages were sent successfully, else false.
 */
function quizp_send_notification_messages($course, $quizp, $attempt, $context, $cm) {
    global $CFG, $DB;

    // Do nothing if required objects not present.
    if (empty($course) or empty($quizp) or empty($attempt) or empty($context)) {
        throw new coding_exception('$course, $quizp, $attempt, $context and $cm must all be set.');
    }

    $submitter = $DB->get_record('user', array('id' => $attempt->userid), '*', MUST_EXIST);

    // Check for confirmation required.
    $sendconfirm = false;
    $notifyexcludeusers = '';
    if (has_capability('mod/quizp:emailconfirmsubmission', $context, $submitter, false)) {
        $notifyexcludeusers = $submitter->id;
        $sendconfirm = true;
    }

    // Check for notifications required.
    $notifyfields = 'u.id, u.username, u.idnumber, u.email, u.emailstop, u.lang,
            u.timezone, u.mailformat, u.maildisplay, u.auth, u.suspended, u.deleted, ';
    $notifyfields .= get_all_user_name_fields(true, 'u');
    $groups = groups_get_all_groups($course->id, $submitter->id, $cm->groupingid);
    if (is_array($groups) && count($groups) > 0) {
        $groups = array_keys($groups);
    } else if (groups_get_activity_groupmode($cm, $course) != NOGROUPS) {
        // If the user is not in a group, and the quizp is set to group mode,
        // then set $groups to a non-existant id so that only users with
        // 'moodle/site:accessallgroups' get notified.
        $groups = -1;
    } else {
        $groups = '';
    }
    $userstonotify = get_users_by_capability($context, 'mod/quizp:emailnotifysubmission',
            $notifyfields, '', '', '', $groups, $notifyexcludeusers, false, false, true);

    if (empty($userstonotify) && !$sendconfirm) {
        return true; // Nothing to do.
    }

    $a = new stdClass();
    // Course info.
    $a->coursename      = $course->fullname;
    $a->courseshortname = $course->shortname;
    // Quiz info.
    $a->quizpname        = $quizp->name;
    $a->quizpreporturl   = $CFG->wwwroot . '/mod/quizp/report.php?id=' . $cm->id;
    $a->quizpreportlink  = '<a href="' . $a->quizpreporturl . '">' .
            format_string($quizp->name) . ' report</a>';
    $a->quizpurl         = $CFG->wwwroot . '/mod/quizp/view.php?id=' . $cm->id;
    $a->quizplink        = '<a href="' . $a->quizpurl . '">' . format_string($quizp->name) . '</a>';
    // Attempt info.
    $a->submissiontime  = userdate($attempt->timefinish);
    $a->timetaken       = format_time($attempt->timefinish - $attempt->timestart);
    $a->quizpreviewurl   = $CFG->wwwroot . '/mod/quizp/review.php?attempt=' . $attempt->id;
    $a->quizpreviewlink  = '<a href="' . $a->quizpreviewurl . '">' .
            format_string($quizp->name) . ' review</a>';
    // Student who sat the quizp info.
    $a->studentidnumber = $submitter->idnumber;
    $a->studentname     = fullname($submitter);
    $a->studentusername = $submitter->username;

    $allok = true;

    // Send notifications if required.
    if (!empty($userstonotify)) {
        foreach ($userstonotify as $recipient) {
            $allok = $allok && quizp_send_notification($recipient, $submitter, $a);
        }
    }

    // Send confirmation if required. We send the student confirmation last, so
    // that if message sending is being intermittently buggy, which means we send
    // some but not all messages, and then try again later, then teachers may get
    // duplicate messages, but the student will always get exactly one.
    if ($sendconfirm) {
        $allok = $allok && quizp_send_confirmation($submitter, $a);
    }

    return $allok;
}

/**
 * Send the notification message when a quizp attempt becomes overdue.
 *
 * @param quizp_attempt $attemptobj all the data about the quizp attempt.
 */
function quizp_send_overdue_message($attemptobj) {
    global $CFG, $DB;

    $submitter = $DB->get_record('user', array('id' => $attemptobj->get_userid()), '*', MUST_EXIST);

    if (!$attemptobj->has_capability('mod/quizp:emailwarnoverdue', $submitter->id, false)) {
        return; // Message not required.
    }

    if (!$attemptobj->has_response_to_at_least_one_graded_question()) {
        return; // Message not required.
    }

    // Prepare lots of useful information that admins might want to include in
    // the email message.
    $quizpname = format_string($attemptobj->get_quizp_name());

    $deadlines = array();
    if ($attemptobj->get_quizp()->timelimit) {
        $deadlines[] = $attemptobj->get_attempt()->timestart + $attemptobj->get_quizp()->timelimit;
    }
    if ($attemptobj->get_quizp()->timeclose) {
        $deadlines[] = $attemptobj->get_quizp()->timeclose;
    }
    $duedate = min($deadlines);
    $graceend = $duedate + $attemptobj->get_quizp()->graceperiod;

    $a = new stdClass();
    // Course info.
    $a->coursename         = format_string($attemptobj->get_course()->fullname);
    $a->courseshortname    = format_string($attemptobj->get_course()->shortname);
    // Quiz info.
    $a->quizpname           = $quizpname;
    $a->quizpurl            = $attemptobj->view_url();
    $a->quizplink           = '<a href="' . $a->quizpurl . '">' . $quizpname . '</a>';
    // Attempt info.
    $a->attemptduedate     = userdate($duedate);
    $a->attemptgraceend    = userdate($graceend);
    $a->attemptsummaryurl  = $attemptobj->summary_url()->out(false);
    $a->attemptsummarylink = '<a href="' . $a->attemptsummaryurl . '">' . $quizpname . ' review</a>';
    // Student's info.
    $a->studentidnumber    = $submitter->idnumber;
    $a->studentname        = fullname($submitter);
    $a->studentusername    = $submitter->username;

    // Prepare the message.
    $eventdata = new stdClass();
    $eventdata->component         = 'mod_quizp';
    $eventdata->name              = 'attempt_overdue';
    $eventdata->notification      = 1;

    $eventdata->userfrom          = core_user::get_noreply_user();
    $eventdata->userto            = $submitter;
    $eventdata->subject           = get_string('emailoverduesubject', 'quizp', $a);
    $eventdata->fullmessage       = get_string('emailoverduebody', 'quizp', $a);
    $eventdata->fullmessageformat = FORMAT_PLAIN;
    $eventdata->fullmessagehtml   = '';

    $eventdata->smallmessage      = get_string('emailoverduesmall', 'quizp', $a);
    $eventdata->contexturl        = $a->quizpurl;
    $eventdata->contexturlname    = $a->quizpname;

    // Send the message.
    return message_send($eventdata);
}

/**
 * Handle the quizp_attempt_submitted event.
 *
 * This sends the confirmation and notification messages, if required.
 *
 * @param object $event the event object.
 */
function quizp_attempt_submitted_handler($event) {
    global $DB;

    $course  = $DB->get_record('course', array('id' => $event->courseid));
    $attempt = $event->get_record_snapshot('quizp_attempts', $event->objectid);
    $quizp    = $event->get_record_snapshot('quizp', $attempt->quizp);
    $cm      = get_coursemodule_from_id('quizp', $event->get_context()->instanceid, $event->courseid);

    if (!($course && $quizp && $cm && $attempt)) {
        // Something has been deleted since the event was raised. Therefore, the
        // event is no longer relevant.
        return true;
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) && ($quizp->completionattemptsexhausted || $quizp->completionpass)) {
        $completion->update_state($cm, COMPLETION_COMPLETE, $event->userid);
    }
    return quizp_send_notification_messages($course, $quizp, $attempt,
            context_module::instance($cm->id), $cm);
}

/**
 * Handle groups_member_added event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quizp\group_observers::group_member_added()}.
 */
function quizp_groups_member_added_handler($event) {
    debugging('quizp_groups_member_added_handler() is deprecated, please use ' .
        '\mod_quizp\group_observers::group_member_added() instead.', DEBUG_DEVELOPER);
    quizp_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_member_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quizp\group_observers::group_member_removed()}.
 */
function quizp_groups_member_removed_handler($event) {
    debugging('quizp_groups_member_removed_handler() is deprecated, please use ' .
        '\mod_quizp\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    quizp_update_open_attempts(array('userid'=>$event->userid, 'groupid'=>$event->groupid));
}

/**
 * Handle groups_group_deleted event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quizp\group_observers::group_deleted()}.
 */
function quizp_groups_group_deleted_handler($event) {
    global $DB;
    debugging('quizp_groups_group_deleted_handler() is deprecated, please use ' .
        '\mod_quizp\group_observers::group_deleted() instead.', DEBUG_DEVELOPER);
    quizp_process_group_deleted_in_course($event->courseid);
}

/**
 * Logic to happen when a/some group(s) has/have been deleted in a course.
 *
 * @param int $courseid The course ID.
 * @return void
 */
function quizp_process_group_deleted_in_course($courseid) {
    global $DB;

    // It would be nice if we got the groupid that was deleted.
    // Instead, we just update all quizpzes with orphaned group overrides.
    $sql = "SELECT o.id, o.quizp
              FROM {quizp_overrides} o
              JOIN {quizp} quizp ON quizp.id = o.quizp
         LEFT JOIN {groups} grp ON grp.id = o.groupid
             WHERE quizp.course = :courseid
               AND o.groupid IS NOT NULL
               AND grp.id IS NULL";
    $params = array('courseid' => $courseid);
    $records = $DB->get_records_sql_menu($sql, $params);
    if (!$records) {
        return; // Nothing to do.
    }
    $DB->delete_records_list('quizp_overrides', 'id', array_keys($records));
    quizp_update_open_attempts(array('quizpid' => array_unique(array_values($records))));
}

/**
 * Handle groups_members_removed event
 *
 * @param object $event the event object.
 * @deprecated since 2.6, see {@link \mod_quizp\group_observers::group_member_removed()}.
 */
function quizp_groups_members_removed_handler($event) {
    debugging('quizp_groups_members_removed_handler() is deprecated, please use ' .
        '\mod_quizp\group_observers::group_member_removed() instead.', DEBUG_DEVELOPER);
    if ($event->userid == 0) {
        quizp_update_open_attempts(array('courseid'=>$event->courseid));
    } else {
        quizp_update_open_attempts(array('courseid'=>$event->courseid, 'userid'=>$event->userid));
    }
}

/**
 * Get the information about the standard quizp JavaScript module.
 * @return array a standard jsmodule structure.
 */
function quizp_get_js_module() {
    global $PAGE;

    return array(
        'name' => 'mod_quizp',
        'fullpath' => '/mod/quizp/module.js',
        'requires' => array('base', 'dom', 'event-delegate', 'event-key',
                'core_question_engine', 'moodle-core-formchangechecker'),
        'strings' => array(
            array('cancel', 'moodle'),
            array('flagged', 'question'),
            array('functiondisabledbysecuremode', 'quizp'),
            array('startattempt', 'quizp'),
            array('timesup', 'quizp'),
            array('changesmadereallygoaway', 'moodle'),
        ),
    );
}


/**
 * An extension of question_display_options that includes the extra options used
 * by the quizp.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quizp_display_options extends question_display_options {
    /**#@+
     * @var integer bits used to indicate various times in relation to a
     * quizp attempt.
     */
    const DURING =            0x10000;
    const IMMEDIATELY_AFTER = 0x01000;
    const LATER_WHILE_OPEN =  0x00100;
    const AFTER_CLOSE =       0x00010;
    /**#@-*/

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $attempt = true;

    /**
     * @var boolean if this is false, then the student is not allowed to review
     * anything about the attempt.
     */
    public $overallfeedback = self::VISIBLE;

    /**
     * Set up the various options from the quizp settings, and a time constant.
     * @param object $quizp the quizp settings.
     * @param int $one of the {@link DURING}, {@link IMMEDIATELY_AFTER},
     * {@link LATER_WHILE_OPEN} or {@link AFTER_CLOSE} constants.
     * @return mod_quizp_display_options set up appropriately.
     */
    public static function make_from_quizp($quizp, $when) {
        $options = new self();

        $options->attempt = self::extract($quizp->reviewattempt, $when, true, false);
        $options->correctness = self::extract($quizp->reviewcorrectness, $when);
        $options->marks = self::extract($quizp->reviewmarks, $when,
                self::MARK_AND_MAX, self::MAX_ONLY);
        $options->feedback = self::extract($quizp->reviewspecificfeedback, $when);
        $options->generalfeedback = self::extract($quizp->reviewgeneralfeedback, $when);
        $options->rightanswer = self::extract($quizp->reviewrightanswer, $when);
        $options->overallfeedback = self::extract($quizp->reviewoverallfeedback, $when);

        $options->numpartscorrect = $options->feedback;
        $options->manualcomment = $options->feedback;

        if ($quizp->questiondecimalpoints != -1) {
            $options->markdp = $quizp->questiondecimalpoints;
        } else {
            $options->markdp = $quizp->decimalpoints;
        }

        return $options;
    }

    protected static function extract($bitmask, $bit,
            $whenset = self::VISIBLE, $whennotset = self::HIDDEN) {
        if ($bitmask & $bit) {
            return $whenset;
        } else {
            return $whennotset;
        }
    }
}


/**
 * A {@link qubaid_condition} for finding all the question usages belonging to
 * a particular quizp.
 *
 * @copyright  2010 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qubaids_for_quizp extends qubaid_join {
    public function __construct($quizpid, $includepreviews = true, $onlyfinished = false) {
        $where = 'quizpa.quizp = :quizpaquizp';
        $params = array('quizpaquizp' => $quizpid);

        if (!$includepreviews) {
            $where .= ' AND preview = 0';
        }

        if ($onlyfinished) {
            $where .= ' AND state == :statefinished';
            $params['statefinished'] = quizp_attempt::FINISHED;
        }

        parent::__construct('{quizp_attempts} quizpa', 'quizpa.uniqueid', $where, $params);
    }
}

/**
 * Creates a textual representation of a question for display.
 *
 * @param object $question A question object from the database questions table
 * @param bool $showicon If true, show the question's icon with the question. False by default.
 * @param bool $showquestiontext If true (default), show question text after question name.
 *       If false, show only question name.
 * @return string
 */
function quizp_question_tostring($question, $showicon = false, $showquestiontext = true) {
    $result = '';

    $name = shorten_text(format_string($question->name), 200);
    if ($showicon) {
        $name .= print_question_icon($question) . ' ' . $name;
    }
    $result .= html_writer::span($name, 'questionname');

    if ($showquestiontext) {
        $questiontext = question_utils::to_plain_text($question->questiontext,
                $question->questiontextformat, array('noclean' => true, 'para' => false));
        $questiontext = shorten_text($questiontext, 200);
        if ($questiontext) {
            $result .= ' ' . html_writer::span(s($questiontext), 'questiontext');
        }
    }

    return $result;
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * Does not return. Throws an exception if the question cannot be used.
 * @param int $questionid The id of the question.
 */
function quizp_require_question_use($questionid) {
    global $DB;
    $question = $DB->get_record('question', array('id' => $questionid), '*', MUST_EXIST);
    question_require_capability_on($question, 'use');
}

/**
 * Verify that the question exists, and the user has permission to use it.
 * @param object $quizp the quizp settings.
 * @param int $slot which question in the quizp to test.
 * @return bool whether the user can use this question.
 */
function quizp_has_question_use($quizp, $slot) {
    global $DB;
    $question = $DB->get_record_sql("
            SELECT q.*
              FROM {quizp_slots} slot
              JOIN {question} q ON q.id = slot.questionid
             WHERE slot.quizpid = ? AND slot.slot = ?", array($quizp->id, $slot));
    if (!$question) {
        return false;
    }
    return question_has_capability_on($question, 'use');
}

/**
 * Add a question to a quizp
 *
 * Adds a question to a quizp by updating $quizp as well as the
 * quizp and quizp_slots tables. It also adds a page break if required.
 * @param int $questionid The id of the question to be added
 * @param object $quizp The extended quizp object as used by edit.php
 *      This is updated by this function
 * @param int $page Which page in quizp to add the question on. If 0 (default),
 *      add at the end
 * @param float $maxmark The maximum mark to set for this question. (Optional,
 *      defaults to question.defaultmark.
 * @return bool false if the question was already in the quizp
 */
function quizp_add_quizp_question($questionid, $quizp, $page = 0, $maxmark = null) {
    global $DB;
    $slots = $DB->get_records('quizp_slots', array('quizpid' => $quizp->id),
            'slot', 'questionid, slot, page, id');
    if (array_key_exists($questionid, $slots)) {
        return false;
    }

    $trans = $DB->start_delegated_transaction();

    $maxpage = 1;
    $numonlastpage = 0;
    foreach ($slots as $slot) {
        if ($slot->page > $maxpage) {
            $maxpage = $slot->page;
            $numonlastpage = 1;
        } else {
            $numonlastpage += 1;
        }
    }

    // Add the new question instance.
    $slot = new stdClass();
    $slot->quizpid = $quizp->id;
    $slot->questionid = $questionid;

    if ($maxmark !== null) {
        $slot->maxmark = $maxmark;
    } else {
        $slot->maxmark = $DB->get_field('question', 'defaultmark', array('id' => $questionid));
    }

    if (is_int($page) && $page >= 1) {
        // Adding on a given page.
        $lastslotbefore = 0;
        foreach (array_reverse($slots) as $otherslot) {
            if ($otherslot->page > $page) {
                $DB->set_field('quizp_slots', 'slot', $otherslot->slot + 1, array('id' => $otherslot->id));
            } else {
                $lastslotbefore = $otherslot->slot;
                break;
            }
        }
        $slot->slot = $lastslotbefore + 1;
        $slot->page = min($page, $maxpage + 1);

        $DB->execute("
                UPDATE {quizp_sections}
                   SET firstslot = firstslot + 1
                 WHERE quizpid = ?
                   AND firstslot > ?
                ", array($quizp->id, max($lastslotbefore, 1)));

    } else {
        $lastslot = end($slots);
        if ($lastslot) {
            $slot->slot = $lastslot->slot + 1;
        } else {
            $slot->slot = 1;
        }
        if ($quizp->questionsperpage && $numonlastpage >= $quizp->questionsperpage) {
            $slot->page = $maxpage + 1;
        } else {
            $slot->page = $maxpage;
        }
    }

    $DB->insert_record('quizp_slots', $slot);
    $trans->allow_commit();
}

/**
 * Add a random question to the quizp at a given point.
 * @param object $quizp the quizp settings.
 * @param int $addonpage the page on which to add the question.
 * @param int $categoryid the question category to add the question from.
 * @param int $number the number of random questions to add.
 * @param bool $includesubcategories whether to include questoins from subcategories.
 */
function quizp_add_random_questions($quizp, $addonpage, $categoryid, $number,
        $includesubcategories) {
    global $DB;

    $category = $DB->get_record('question_categories', array('id' => $categoryid));
    if (!$category) {
        print_error('invalidcategoryid', 'error');
    }

    $catcontext = context::instance_by_id($category->contextid);
    require_capability('moodle/question:useall', $catcontext);

    // Find existing random questions in this category that are
    // not used by any quizp.
    if ($existingquestions = $DB->get_records_sql(
            "SELECT q.id, q.qtype FROM {question} q
            WHERE qtype = 'random'
                AND category = ?
                AND " . $DB->sql_compare_text('questiontext') . " = ?
                AND NOT EXISTS (
                        SELECT *
                          FROM {quizp_slots}
                         WHERE questionid = q.id)
            ORDER BY id", array($category->id, ($includesubcategories ? '1' : '0')))) {
            // Take as many of these as needed.
        while (($existingquestion = array_shift($existingquestions)) && $number > 0) {
            quizp_add_quizp_question($existingquestion->id, $quizp, $addonpage);
            $number -= 1;
        }
    }

    if ($number <= 0) {
        return;
    }

    // More random questions are needed, create them.
    for ($i = 0; $i < $number; $i += 1) {
        $form = new stdClass();
        $form->questiontext = array('text' => ($includesubcategories ? '1' : '0'), 'format' => 0);
        $form->category = $category->id . ',' . $category->contextid;
        $form->defaultmark = 1;
        $form->hidden = 1;
        $form->stamp = make_unique_id_code(); // Set the unique code (not to be changed).
        $question = new stdClass();
        $question->qtype = 'random';
        $question = question_bank::get_qtype('random')->save_question($question, $form);
        if (!isset($question->id)) {
            print_error('cannotinsertrandomquestion', 'quizp');
        }
        quizp_add_quizp_question($question->id, $quizp, $addonpage);
    }
}
