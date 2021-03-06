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
 * This script displays a particular page of a quizp attempt that is in progress.
 *
 * @package   mod_quizp
 * @copyright 1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(dirname(__FILE__) . '/../../config.php');
require_once($CFG->dirroot . '/mod/quizp/locallib.php');

global $PAGE;
$PAGE->requires->js('/mod/quizp/yui/src/modform/js/modform.js');

// Look for old-style URLs, such as may be in the logs, and redirect them to startattemtp.php.
if ($id = optional_param('id', 0, PARAM_INT)) {
    redirect($CFG->wwwroot . '/mod/quizp/startattempt.php?cmid=' . $id . '&sesskey=' . sesskey());
} else if ($qid = optional_param('q', 0, PARAM_INT)) {
    if (!$cm = get_coursemodule_from_instance('quizp', $qid)) {
        print_error('invalidquizpid', 'quizp');
    }
    redirect(new moodle_url('/mod/quizp/startattempt.php',
            array('cmid' => $cm->id, 'sesskey' => sesskey())));
}

// Get submitted parameters.
$attemptid = required_param('attempt', PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);

$attemptobj = quizp_attempt::create($attemptid);
$page = $attemptobj->force_page_number_into_range($page);
$PAGE->set_url($attemptobj->attempt_url(null, $page));

// Check login.
require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

// Check that this attempt belongs to this user.
if ($attemptobj->get_userid() != $USER->id) {
    if ($attemptobj->has_capability('mod/quizp:viewreports')) {
        redirect($attemptobj->review_url(null, $page));
    } else {
        throw new moodle_quizp_exception($attemptobj->get_quizpobj(), 'notyourattempt');
    }
}

// Check capabilities and block settings.
if (!$attemptobj->is_preview_user()) {
    $attemptobj->require_capability('mod/quizp:attempt');
    if (empty($attemptobj->get_quizp()->showblocks)) {
        $PAGE->blocks->show_only_fake_blocks();
    }

} else {
    navigation_node::override_active_url($attemptobj->start_attempt_url());
}

// If the attempt is already closed, send them to the review page.
if ($attemptobj->is_finished()) {
    redirect($attemptobj->review_url(null, $page));
} else if ($attemptobj->get_state() == quizp_attempt::OVERDUE) {
    redirect($attemptobj->summary_url());
}

// Check the access rules.
$accessmanager = $attemptobj->get_access_manager(time());
$accessmanager->setup_attempt_page($PAGE);
$output = $PAGE->get_renderer('mod_quizp');
$messages = $accessmanager->prevent_access();
if (!$attemptobj->is_preview_user() && $messages) {
    print_error('attempterror', 'quizp', $attemptobj->view_url(),
            $output->access_messages($messages));
}
if ($accessmanager->is_preflight_check_required($attemptobj->get_attemptid())) {
    redirect($attemptobj->start_attempt_url(null, $page));
}

// Set up auto-save if required.
$autosaveperiod = get_config('quizp', 'autosaveperiod');
if ($autosaveperiod) {
    $PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quizp-autosave/moodle-mod_quizp-autosave.js');
    $PAGE->requires->yui_module('moodle-mod_quizp-autosave',
            'M.mod_quizp.autosave.init', array($autosaveperiod));
}

// Log this page view.
$params = array(
    'objectid' => $attemptid,
    'relateduserid' => $attemptobj->get_userid(),
    'courseid' => $attemptobj->get_courseid(),
    'context' => context_module::instance($attemptobj->get_cmid()),
    'other' => array(
        'quizpid' => $attemptobj->get_quizpid()
    )
);
$event = \mod_quizp\event\attempt_viewed::create($params);
$event->add_record_snapshot('quizp_attempts', $attemptobj->get_attempt());
$event->trigger();

// Get the list of questions needed by this page.
$slots = $attemptobj->get_slots($page);

// Check.
if (empty($slots)) {
    throw new moodle_quizp_exception($attemptobj->get_quizpobj(), 'noquestionsfound');
}

// Update attempt page.
if ($attemptobj->get_currentpage() != $page) {
    if ($attemptobj->get_navigation_method() == QUIZP_NAVMETHOD_SEQ && $attemptobj->get_currentpage() > $page) {
        // Prevent out of sequence access.
        redirect($attemptobj->start_attempt_url(null, $attemptobj->get_currentpage()));
    }
    $DB->set_field('quizp_attempts', 'currentpage', $page, array('id' => $attemptid));
}

// Initialise the JavaScript.
$headtags = $attemptobj->get_html_head_contributions($page);
$PAGE->requires->js_init_call('M.mod_quizp.init_attempt_form', null, false, quizp_get_js_module());

// Arrange for the navigation to be displayed in the first region on the page.
$navbc = $attemptobj->get_navigation_panel($output, 'quizp_attempt_nav_panel', $page);
$regions = $PAGE->blocks->get_regions();
//$PAGE->blocks->add_fake_block($navbc, reset($regions));

$title = get_string('attempt', 'quizp', $attemptobj->get_attempt_number());
$headtags = $attemptobj->get_html_head_contributions($page);
$PAGE->set_title($attemptobj->get_quizp_name());
$PAGE->set_heading($attemptobj->get_course()->fullname);

if ($attemptobj->is_last_page($page)) {
    $nextpage = -1;
} else {
    $nextpage = $page + 1;
}

$res_to_all_q = get_string('res_to_all_q', 'mod_quizp');
if(optional_param('showalert', 0, PARAM_INT)){
    echo "
        <div class='alert alert-warning fade in'>
            {$res_to_all_q}
        </div>
    ";
}
echo $output->attempt_page($attemptobj, $page, $accessmanager, $messages, $slots, $id, $nextpage);
