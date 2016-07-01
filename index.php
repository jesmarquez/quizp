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
 * This script lists all the instances of quizp in a particular course
 *
 * @package    mod_quizp
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


require_once("../../config.php");
require_once("locallib.php");

$id = required_param('id', PARAM_INT);
$PAGE->set_url('/mod/quizp/index.php', array('id'=>$id));
if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}
$coursecontext = context_course::instance($id);
require_login($course);
$PAGE->set_pagelayout('incourse');

$params = array(
    'context' => $coursecontext
);
$event = \mod_quizp\event\course_module_instance_list_viewed::create($params);
$event->trigger();

// Print the header.
$strquizpzes = get_string("modulenameplural", "quizp");
$streditquestions = '';
$editqcontexts = new question_edit_contexts($coursecontext);
if ($editqcontexts->have_one_edit_tab_cap('questions')) {
    $streditquestions =
            "<form target=\"_parent\" method=\"get\" action=\"$CFG->wwwroot/question/edit.php\">
               <div>
               <input type=\"hidden\" name=\"courseid\" value=\"$course->id\" />
               <input type=\"submit\" value=\"".get_string("editquestions", "quizp")."\" />
               </div>
             </form>";
}
$PAGE->navbar->add($strquizpzes);
$PAGE->set_title($strquizpzes);
$PAGE->set_button($streditquestions);
$PAGE->set_heading($course->fullname);
echo $OUTPUT->header();
echo $OUTPUT->heading($strquizpzes, 2);

// Get all the appropriate data.
if (!$quizpzes = get_all_instances_in_course("quizp", $course)) {
    notice(get_string('thereareno', 'moodle', $strquizpzes), "../../course/view.php?id=$course->id");
    die;
}

// Check if we need the closing date header.
$showclosingheader = false;
$showfeedback = false;
foreach ($quizpzes as $quizp) {
    if ($quizp->timeclose!=0) {
        $showclosingheader=true;
    }
    if (quizp_has_feedback($quizp)) {
        $showfeedback=true;
    }
    if ($showclosingheader && $showfeedback) {
        break;
    }
}

// Configure table for displaying the list of instances.
$headings = array(get_string('name'));
$align = array('left');

if ($showclosingheader) {
    array_push($headings, get_string('quizpcloses', 'quizp'));
    array_push($align, 'left');
}

if (course_format_uses_sections($course->format)) {
    array_unshift($headings, get_string('sectionname', 'format_'.$course->format));
} else {
    array_unshift($headings, '');
}
array_unshift($align, 'center');

$showing = '';

if (has_capability('mod/quizp:viewreports', $coursecontext)) {
    array_push($headings, get_string('attempts', 'quizp'));
    array_push($align, 'left');
    $showing = 'stats';

} else if (has_any_capability(array('mod/quizp:reviewmyattempts', 'mod/quizp:attempt'),
        $coursecontext)) {
    array_push($headings, get_string('grade', 'quizp'));
    array_push($align, 'left');
    if ($showfeedback) {
        array_push($headings, get_string('feedback', 'quizp'));
        array_push($align, 'left');
    }
    $showing = 'grades';

    $grades = $DB->get_records_sql_menu('
            SELECT qg.quizp, qg.grade
            FROM {quizp_grades} qg
            JOIN {quizp} q ON q.id = qg.quizp
            WHERE q.course = ? AND qg.userid = ?',
            array($course->id, $USER->id));
}

$table = new html_table();
$table->head = $headings;
$table->align = $align;

// Populate the table with the list of instances.
$currentsection = '';
foreach ($quizpzes as $quizp) {
    $cm = get_coursemodule_from_instance('quizp', $quizp->id);
    $context = context_module::instance($cm->id);
    $data = array();

    // Section number if necessary.
    $strsection = '';
    if ($quizp->section != $currentsection) {
        if ($quizp->section) {
            $strsection = $quizp->section;
            $strsection = get_section_name($course, $quizp->section);
        }
        if ($currentsection) {
            $learningtable->data[] = 'hr';
        }
        $currentsection = $quizp->section;
    }
    $data[] = $strsection;

    // Link to the instance.
    $class = '';
    if (!$quizp->visible) {
        $class = ' class="dimmed"';
    }
    $data[] = "<a$class href=\"view.php?id=$quizp->coursemodule\">" .
            format_string($quizp->name, true) . '</a>';

    // Close date.
    if ($quizp->timeclose) {
        $data[] = userdate($quizp->timeclose);
    } else if ($showclosingheader) {
        $data[] = '';
    }

    if ($showing == 'stats') {
        // The $quizp objects returned by get_all_instances_in_course have the necessary $cm
        // fields set to make the following call work.
        $data[] = quizp_attempt_summary_link_to_reports($quizp, $cm, $context);

    } else if ($showing == 'grades') {
        // Grade and feedback.
        $attempts = quizp_get_user_attempts($quizp->id, $USER->id, 'all');
        list($someoptions, $alloptions) = quizp_get_combined_reviewoptions(
                $quizp, $attempts);

        $grade = '';
        $feedback = '';
        if ($quizp->grade && array_key_exists($quizp->id, $grades)) {
            if ($alloptions->marks >= question_display_options::MARK_AND_MAX) {
                $a = new stdClass();
                $a->grade = quizp_format_grade($quizp, $grades[$quizp->id]);
                $a->maxgrade = quizp_format_grade($quizp, $quizp->grade);
                $grade = get_string('outofshort', 'quizp', $a);
            }
            if ($alloptions->overallfeedback) {
                $feedback = quizp_feedback_for_grade($grades[$quizp->id], $quizp, $context);
            }
        }
        $data[] = $grade;
        if ($showfeedback) {
            $data[] = $feedback;
        }
    }

    $table->data[] = $data;
} // End of loop over quizp instances.

// Display the table.
echo html_writer::table($table);

// Finish the page.
echo $OUTPUT->footer();
