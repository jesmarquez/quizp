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
 * Page to edit quizpzes
 *
 * This page generally has two columns:
 * The right column lists all available questions in a chosen category and
 * allows them to be edited or more to be added. This column is only there if
 * the quizp does not already have student attempts
 * The left column lists all questions that have been added to the current quizp.
 * The lecturer can add questions from the right hand list to the quizp or remove them
 *
 * The script also processes a number of actions:
 * Actions affecting a quizp:
 * up and down  Changes the order of questions and page breaks
 * addquestion  Adds a single question to the quizp
 * add          Adds several selected questions to the quizp
 * addrandom    Adds a certain number of random questions to the quizp
 * repaginate   Re-paginates the quizp
 * delete       Removes a question from the quizp
 * savechanges  Saves the order and grades for questions in the quizp
 *
 * @package    mod_quizp
 * @copyright  1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quizp/locallib.php');
require_once($CFG->dirroot . '/mod/quizp/addrandomform.php');
require_once($CFG->dirroot . '/question/editlib.php');
require_once($CFG->dirroot . '/question/category_class.php');

// These params are only passed from page request to request while we stay on
// this page otherwise they would go in question_edit_setup.
$scrollpos = optional_param('scrollpos', '', PARAM_INT);

list($thispageurl, $contexts, $cmid, $cm, $quizp, $pagevars) =
        question_edit_setup('editq', '/mod/quizp/edit.php', true);

$defaultcategoryobj = question_make_default_categories($contexts->all());
$defaultcategory = $defaultcategoryobj->id . ',' . $defaultcategoryobj->contextid;

$quizphasattempts = quizp_has_attempts($quizp->id);

$PAGE->set_url($thispageurl);

// Get the course object and related bits.
$course = $DB->get_record('course', array('id' => $quizp->course), '*', MUST_EXIST);
$quizpobj = new quizp($quizp, $cm, $course);
$structure = $quizpobj->get_structure();

// You need mod/quizp:manage in addition to question capabilities to access this page.
require_capability('mod/quizp:manage', $contexts->lowest());

// Log this visit.
$params = array(
    'courseid' => $course->id,
    'context' => $contexts->lowest(),
    'other' => array(
        'quizpid' => $quizp->id
    )
);
$event = \mod_quizp\event\edit_page_viewed::create($params);
$event->trigger();

// Process commands ============================================================.

// Get the list of question ids had their check-boxes ticked.
$selectedslots = array();
$params = (array) data_submitted();
foreach ($params as $key => $value) {
    if (preg_match('!^s([0-9]+)$!', $key, $matches)) {
        $selectedslots[] = $matches[1];
    }
}

$afteractionurl = new moodle_url($thispageurl);
if ($scrollpos) {
    $afteractionurl->param('scrollpos', $scrollpos);
}

if (optional_param('repaginate', false, PARAM_BOOL) && confirm_sesskey()) {
    // Re-paginate the quizp.
    $structure->check_can_be_edited();
    $questionsperpage = optional_param('questionsperpage', $quizp->questionsperpage, PARAM_INT);
    quizp_repaginate_questions($quizp->id, $questionsperpage );
    quizp_delete_previews($quizp);
    redirect($afteractionurl);
}

if (($addquestion = optional_param('addquestion', 0, PARAM_INT)) && confirm_sesskey()) {
    // Add a single question to the current quizp.
    $structure->check_can_be_edited();
    quizp_require_question_use($addquestion);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    quizp_add_quizp_question($addquestion, $quizp, $addonpage);
    quizp_delete_previews($quizp);
    quizp_update_sumgrades($quizp);
    $thispageurl->param('lastchanged', $addquestion);
    redirect($afteractionurl);
}

if (optional_param('add', false, PARAM_BOOL) && confirm_sesskey()) {
    $structure->check_can_be_edited();
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    // Add selected questions to the current quizp.
    $rawdata = (array) data_submitted();
    foreach ($rawdata as $key => $value) { // Parse input for question ids.
        if (preg_match('!^q([0-9]+)$!', $key, $matches)) {
            $key = $matches[1];
            quizp_require_question_use($key);
            quizp_add_quizp_question($key, $quizp, $addonpage);
        }
    }
    quizp_delete_previews($quizp);
    quizp_update_sumgrades($quizp);
    redirect($afteractionurl);
}

if ($addsectionatpage = optional_param('addsectionatpage', false, PARAM_INT)) {
    // Add a section to the quizp.
    $structure->check_can_be_edited();
    $structure->add_section_heading($addsectionatpage);
    quizp_delete_previews($quizp);
    redirect($afteractionurl);
}

if ((optional_param('addrandom', false, PARAM_BOOL)) && confirm_sesskey()) {
    // Add random questions to the quizp.
    $structure->check_can_be_edited();
    $recurse = optional_param('recurse', 0, PARAM_BOOL);
    $addonpage = optional_param('addonpage', 0, PARAM_INT);
    $categoryid = required_param('categoryid', PARAM_INT);
    $randomcount = required_param('randomcount', PARAM_INT);
    quizp_add_random_questions($quizp, $addonpage, $categoryid, $randomcount, $recurse);

    quizp_delete_previews($quizp);
    quizp_update_sumgrades($quizp);
    redirect($afteractionurl);
}

if (optional_param('savechanges', false, PARAM_BOOL) && confirm_sesskey()) {

    // If rescaling is required save the new maximum.
    $maxgrade = unformat_float(optional_param('maxgrade', -1, PARAM_RAW));
    if ($maxgrade >= 0) {
        quizp_set_grade($maxgrade, $quizp);
        quizp_update_all_final_grades($quizp);
        quizp_update_grades($quizp, 0, true);
    }

    redirect($afteractionurl);
}

// Get the question bank view.
$questionbank = new mod_quizp\question\bank\custom_view($contexts, $thispageurl, $course, $cm, $quizp);
$questionbank->set_quizp_has_attempts($quizphasattempts);
$questionbank->process_actions($thispageurl, $cm);

// End of process commands =====================================================.

$PAGE->set_pagelayout('incourse');
$PAGE->set_pagetype('mod-quizp-edit');

$output = $PAGE->get_renderer('mod_quizp', 'edit');

$PAGE->set_title(get_string('editingquizpx', 'quizp', format_string($quizp->name)));
$PAGE->set_heading($course->fullname);
$node = $PAGE->settingsnav->find('mod_quizp_edit', navigation_node::TYPE_SETTING);
if ($node) {
    $node->make_active();
}
echo $OUTPUT->header();

// Initialise the JavaScript.
$quizpeditconfig = new stdClass();
$quizpeditconfig->url = $thispageurl->out(true, array('qbanktool' => '0'));
$quizpeditconfig->dialoglisteners = array();
$numberoflisteners = $DB->get_field_sql("
    SELECT COALESCE(MAX(page), 1)
      FROM {quizp_slots}
     WHERE quizpid = ?", array($quizp->id));

for ($pageiter = 1; $pageiter <= $numberoflisteners; $pageiter++) {
    $quizpeditconfig->dialoglisteners[] = 'addrandomdialoglaunch_' . $pageiter;
}

$PAGE->requires->data_for_js('quizp_edit_config', $quizpeditconfig);
$PAGE->requires->js('/question/qengine.js');
$PAGE->requires->js('/mod/quizp/module.js');
$PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quiz-repaginate/moodle-mod_quiz-repaginate-debug.js');
$PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quiz-toolboxes/moodle-mod_quiz-toolboxes-debug.js');
$PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quiz-quizbase/moodle-mod_quiz-quizbase-debug.js');
$PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quiz-quizquestionbank/moodle-mod_quiz-quizquestionbank-debug.js');
$PAGE->requires->js('/mod/quizp/yui/src/randomquestion/js/randomquestion.js');
$PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quiz-questionchooser/moodle-mod_quiz-questionchooser-debug.js');
$PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quiz-dragdrop/moodle-mod_quiz-dragdrop-debug.js');
$PAGE->requires->js('/mod/quizp/yui/src/util/js/slot.js');
$PAGE->requires->js('/mod/quizp/yui/build/moodle-mod_quiz-util-page/moodle-mod_quiz-util-page.js');


// Questions wrapper start.
echo html_writer::start_tag('div', array('class' => 'mod-quizp-edit-content'));

echo $output->edit_page($quizpobj, $structure, $contexts, $thispageurl, $pagevars);

// Questions wrapper end.
echo html_writer::end_tag('div');

echo $OUTPUT->footer();
