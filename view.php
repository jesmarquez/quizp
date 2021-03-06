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
 * This page is the entry page into the quizp UI. Displays information about the
 * quizp to students and teachers, and lets students see their previous attempts.
 *
 * @package   mod_quizp
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->libdir.'/gradelib.php');
require_once($CFG->dirroot.'/mod/quizp/locallib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/course/format/lib.php');

$id = optional_param('id', 0, PARAM_INT); // Course Module ID, or ...
$q = optional_param('q',  0, PARAM_INT);  // Quiz ID.

if ($id) {
    if (!$cm = get_coursemodule_from_id('quizp', $id)) {
        print_error('invalidcoursemodule');
    }
    if (!$course = $DB->get_record('course', array('id' => $cm->course))) {
        print_error('coursemisconf');
    }
} else {
    if (!$quizp = $DB->get_record('quizp', array('id' => $q))) {
        print_error('invalidquizpid', 'quizp');
    }
    if (!$course = $DB->get_record('course', array('id' => $quizp->course))) {
        print_error('invalidcourseid');
    }
    if (!$cm = get_coursemodule_from_instance("quizp", $quizp->id, $course->id)) {
        print_error('invalidcoursemodule');
    }
}

// Check login and get context.
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/quizp:view', $context);

// Cache some other capabilities we use several times.
$canattempt = has_capability('mod/quizp:attempt', $context);
$canreviewmine = has_capability('mod/quizp:reviewmyattempts', $context);
$canpreview = has_capability('mod/quizp:preview', $context);

// Create an object to manage all the other (non-roles) access rules.
$timenow = time();
$quizpobj = quizp::create($cm->instance, $USER->id);
$accessmanager = new quizp_access_manager($quizpobj, $timenow,
        has_capability('mod/quizp:ignoretimelimits', $context, null, false));
$quizp = $quizpobj->get_quizp();

// Log this request.
$params = array(
    'objectid' => $quizp->id,
    'context' => $context
);
$event = \mod_quizp\event\course_module_viewed::create($params);
$event->add_record_snapshot('quizp', $quizp);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Initialize $PAGE, compute blocks.
$PAGE->set_url('/mod/quizp/view.php', array('id' => $cm->id));

// Create view object which collects all the information the renderer will need.
$viewobj = new mod_quizp_view_object();
$viewobj->accessmanager = $accessmanager;
$viewobj->canreviewmine = $canreviewmine;

// Get this user's attempts.
$attempts = quizp_get_user_attempts($quizp->id, $USER->id, 'finished', true);
$lastfinishedattempt = end($attempts);
$unfinished = false;
if ($unfinishedattempt = quizp_get_user_attempt_unfinished($quizp->id, $USER->id)) {
    $attempts[] = $unfinishedattempt;

    // If the attempt is now overdue, deal with that - and pass isonline = false.
    // We want the student notified in this case.
    $quizpobj->create_attempt_object($unfinishedattempt)->handle_if_time_expired(time(), false);

    $unfinished = $unfinishedattempt->state == quizp_attempt::IN_PROGRESS ||
            $unfinishedattempt->state == quizp_attempt::OVERDUE;
    if (!$unfinished) {
        $lastfinishedattempt = $unfinishedattempt;
    }
    $unfinishedattempt = null; // To make it clear we do not use this again.
}
$numattempts = count($attempts);

$viewobj->attempts = $attempts;
$viewobj->attemptobjs = array();
foreach ($attempts as $attempt) {
    $viewobj->attemptobjs[] = new quizp_attempt($attempt, $quizp, $cm, $course, false);
}

// Work out the final grade, checking whether it was overridden in the gradebook.
if (!$canpreview) {
    $mygrade = quizp_get_best_grade($quizp, $USER->id);
} else if ($lastfinishedattempt) {
    // Users who can preview the quizp don't get a proper grade, so work out a
    // plausible value to display instead, so the page looks right.
    $mygrade = quizp_rescale_grade($lastfinishedattempt->sumgrades, $quizp, false);
} else {
    $mygrade = null;
}

$mygradeoverridden = false;
$gradebookfeedback = '';

$grading_info = grade_get_grades($course->id, 'mod', 'quizp', $quizp->id, $USER->id);
if (!empty($grading_info->items)) {
    $item = $grading_info->items[0];
    if (isset($item->grades[$USER->id])) {
        $grade = $item->grades[$USER->id];

        if ($grade->overridden) {
            $mygrade = $grade->grade + 0; // Convert to number.
            $mygradeoverridden = true;
        }
        if (!empty($grade->str_feedback)) {
            $gradebookfeedback = $grade->str_feedback;
        }
    }
}

$title = $course->shortname . ': ' . format_string($quizp->name);
$PAGE->set_title($title);
$PAGE->set_heading($course->fullname);
$output = $PAGE->get_renderer('mod_quizp');

// Print table with existing attempts.
if ($attempts) {
    // Work out which columns we need, taking account what data is available in each attempt.
    list($someoptions, $alloptions) = quizp_get_combined_reviewoptions($quizp, $attempts);

    $viewobj->attemptcolumn  = $quizp->attempts != 1;

    $viewobj->gradecolumn    = $someoptions->marks >= question_display_options::MARK_AND_MAX &&
            quizp_has_grades($quizp);
    $viewobj->markcolumn     = $viewobj->gradecolumn && ($quizp->grade != $quizp->sumgrades);
    $viewobj->overallstats   = $lastfinishedattempt && $alloptions->marks >= question_display_options::MARK_AND_MAX;

    $viewobj->feedbackcolumn = quizp_has_feedback($quizp) && $alloptions->overallfeedback;
}

$viewobj->timenow = $timenow;
$viewobj->numattempts = $numattempts;
$viewobj->mygrade = $mygrade;
$viewobj->moreattempts = $unfinished ||
        !$accessmanager->is_finished($numattempts, $lastfinishedattempt);
$viewobj->mygradeoverridden = $mygradeoverridden;
$viewobj->gradebookfeedback = $gradebookfeedback;
$viewobj->lastfinishedattempt = $lastfinishedattempt;
$viewobj->canedit = has_capability('mod/quizp:manage', $context);
$viewobj->editurl = new moodle_url('/mod/quizp/edit.php', array('cmid' => $cm->id));
$viewobj->backtocourseurl = new moodle_url('/course/view.php', array('id' => $course->id));
$viewobj->startattempturl = $quizpobj->start_attempt_url();
$viewobj->startattemptwarning = $quizpobj->confirm_start_attempt_message($unfinished);
$viewobj->popuprequired = $accessmanager->attempt_must_be_in_popup();
$viewobj->popupoptions = $accessmanager->get_popup_options();

// Display information about this quizp.
$viewobj->infomessages = $viewobj->accessmanager->describe_rules();
if ($quizp->attempts != 1) {
    $viewobj->infomessages[] = get_string('gradingmethod', 'quizp',
            quizp_get_grading_option_name($quizp->grademethod));
}

// Determine wheter a start attempt button should be displayed.
$viewobj->quizphasquestions = $quizpobj->has_questions();
$viewobj->preventmessages = array();
if (!$viewobj->quizphasquestions) {
    $viewobj->buttontext = '';

} else {
    if ($unfinished) {
        if ($canattempt) {
            $viewobj->buttontext = get_string('continueattemptquizp', 'quizp');
        } else if ($canpreview) {
            $viewobj->buttontext = get_string('continuepreview', 'quizp');
        }

    } else {
        if ($canattempt) {
            $viewobj->preventmessages = $viewobj->accessmanager->prevent_new_attempt(
                    $viewobj->numattempts, $viewobj->lastfinishedattempt);
            if ($viewobj->preventmessages) {
                $viewobj->buttontext = '';
            } else if ($viewobj->numattempts == 0) {
                $viewobj->buttontext = get_string('attemptquizpnow', 'quizp');
            } else {
                $viewobj->buttontext = get_string('reattemptquizp', 'quizp');
            }

        } else if ($canpreview) {
            $viewobj->buttontext = get_string('previewquizpnow', 'quizp');
        }
    }

    // If, so far, we think a button should be printed, so check if they will be
    // allowed to access it.
    if ($viewobj->buttontext) {
        if (!$viewobj->moreattempts) {
            $viewobj->buttontext = '';
        } else if ($canattempt
                && $viewobj->preventmessages = $viewobj->accessmanager->prevent_access()) {
            $viewobj->buttontext = '';
        }
    }
}

$viewobj->showbacktocourse = ($viewobj->buttontext === '' &&
        course_get_format($course)->has_view_page());

echo $OUTPUT->header();

if (isguestuser()) {
    // Guests can't do a quizp, so offer them a choice of logging in or going back.
    echo $output->view_page_guest($course, $quizp, $cm, $context, $viewobj->infomessages);
} else if (!isguestuser() && !($canattempt || $canpreview
          || $viewobj->canreviewmine)) {
    // If they are not enrolled in this course in a good enough role, tell them to enrol.
    echo $output->view_page_notenrolled($course, $quizp, $cm, $context, $viewobj->infomessages);
} else {
    echo $output->view_page($course, $quizp, $cm, $context, $viewobj);
}

echo $OUTPUT->footer();
