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
 * This script deals with starting a new attempt at a quizp.
 *
 * Normally, it will end up redirecting to attempt.php - unless a password form is displayed.
 *
 * This code used to be at the top of attempt.php, if you are looking for CVS history.
 *
 * @package   mod_quizp
 * @copyright 2009 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/quizp/locallib.php');

// Get submitted parameters.
$id = required_param('cmid', PARAM_INT); // Course module id
$forcenew = optional_param('forcenew', false, PARAM_BOOL); // Used to force a new preview
$page = optional_param('page', -1, PARAM_INT); // Page to jump to in the attempt.

if (!$cm = get_coursemodule_from_id('quizp', $id)) {
    print_error('invalidcoursemodule');
}
if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
    print_error("coursemisconf");
}

$quizpobj = quizp::create($cm->instance, $USER->id);
// This script should only ever be posted to, so set page URL to the view page.
$PAGE->set_url($quizpobj->view_url());

// Check login and sesskey.
require_login($quizpobj->get_course(), false, $quizpobj->get_cm());
require_sesskey();
$PAGE->set_heading($quizpobj->get_course()->fullname);

// If no questions have been set up yet redirect to edit.php or display an error.
if (!$quizpobj->has_questions()) {
    if ($quizpobj->has_capability('mod/quizp:manage')) {
        redirect($quizpobj->edit_url());
    } else {
        print_error('cannotstartnoquestions', 'quizp', $quizpobj->view_url());
    }
}

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$accessmanager = $quizpobj->get_access_manager($timenow);
if ($quizpobj->is_preview_user() && $forcenew) {
    $accessmanager->current_attempt_finished();
}

// Check capabilities.
if (!$quizpobj->is_preview_user()) {
    $quizpobj->require_capability('mod/quizp:attempt');
}

// Check to see if a new preview was requested.
if ($quizpobj->is_preview_user() && $forcenew) {
    // To force the creation of a new preview, we mark the current attempt (if any)
    // as finished. It will then automatically be deleted below.
    $DB->set_field('quizp_attempts', 'state', quizp_attempt::FINISHED,
            array('quizp' => $quizpobj->get_quizpid(), 'userid' => $USER->id));
}

// Look for an existing attempt.
$attempts = quizp_get_user_attempts($quizpobj->get_quizpid(), $USER->id, 'all', true);
$lastattempt = end($attempts);

// If an in-progress attempt exists, check password then redirect to it.
if ($lastattempt && ($lastattempt->state == quizp_attempt::IN_PROGRESS ||
        $lastattempt->state == quizp_attempt::OVERDUE)) {
    $currentattemptid = $lastattempt->id;
    $messages = $accessmanager->prevent_access();

    // If the attempt is now overdue, deal with that.
    $quizpobj->create_attempt_object($lastattempt)->handle_if_time_expired($timenow, true);

    // And, if the attempt is now no longer in progress, redirect to the appropriate place.
    if ($lastattempt->state == quizp_attempt::ABANDONED || $lastattempt->state == quizp_attempt::FINISHED) {
        redirect($quizpobj->review_url($lastattempt->id));
    }

    // If the page number was not explicitly in the URL, go to the current page.
    if ($page == -1) {
        $page = $lastattempt->currentpage;
    }

} else {
    while ($lastattempt && $lastattempt->preview) {
        $lastattempt = array_pop($attempts);
    }

    // Get number for the next or unfinished attempt.
    if ($lastattempt) {
        $attemptnumber = $lastattempt->attempt + 1;
    } else {
        $lastattempt = false;
        $attemptnumber = 1;
    }
    $currentattemptid = null;

    $messages = $accessmanager->prevent_access() +
            $accessmanager->prevent_new_attempt(count($attempts), $lastattempt);

    if ($page == -1) {
        $page = 0;
    }
}

// Check access.
$output = $PAGE->get_renderer('mod_quizp');
if (!$quizpobj->is_preview_user() && $messages) {
    print_error('attempterror', 'quizp', $quizpobj->view_url(),
            $output->access_messages($messages));
}

if ($accessmanager->is_preflight_check_required($currentattemptid)) {
    // Need to do some checks before allowing the user to continue.
    $mform = $accessmanager->get_preflight_check_form(
            $quizpobj->start_attempt_url($page), $currentattemptid);

    if ($mform->is_cancelled()) {
        $accessmanager->back_to_view_page($output);

    } else if (!$mform->get_data()) {

        // Form not submitted successfully, re-display it and stop.
        $PAGE->set_url($quizpobj->start_attempt_url($page));
        $PAGE->set_title($quizpobj->get_quizp_name());
        $accessmanager->setup_attempt_page($PAGE);
        if (empty($quizpobj->get_quizp()->showblocks)) {
            $PAGE->blocks->show_only_fake_blocks();
        }

        echo $output->start_attempt_page($quizpobj, $mform);
        die();
    }

    // Pre-flight check passed.
    $accessmanager->notify_preflight_check_passed($currentattemptid);
}
if ($currentattemptid) {
    if ($lastattempt->state == quizp_attempt::OVERDUE) {
        redirect($quizpobj->summary_url($lastattempt->id));
    } else {
        redirect($quizpobj->attempt_url($currentattemptid, $page));
    }
}

// Delete any previous preview attempts belonging to this user.
quizp_delete_previews($quizpobj->get_quizp(), $USER->id);

$quba = question_engine::make_questions_usage_by_activity('mod_quizp', $quizpobj->get_context());
$quba->set_preferred_behaviour($quizpobj->get_quizp()->preferredbehaviour);

// Create the new attempt and initialize the question sessions
$timenow = time(); // Update time now, in case the server is running really slowly.
$attempt = quizp_create_attempt($quizpobj, $attemptnumber, $lastattempt, $timenow, $quizpobj->is_preview_user());

if (!($quizpobj->get_quizp()->attemptonlast && $lastattempt)) {
    $attempt = quizp_start_new_attempt($quizpobj, $quba, $attempt, $attemptnumber, $timenow);
} else {
    $attempt = quizp_start_attempt_built_on_last($quba, $attempt, $lastattempt);
}

$transaction = $DB->start_delegated_transaction();

$attempt = quizp_attempt_save_started($quizpobj, $quba, $attempt);

$transaction->allow_commit();

// Redirect to the attempt page.
redirect($quizpobj->attempt_url($attempt->id, $page));
