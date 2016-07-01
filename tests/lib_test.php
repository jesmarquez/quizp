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
 * Unit tests for (some of) mod/quizp/locallib.php.
 *
 * @package    mod_quizp
 * @category   test
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quizp/lib.php');

/**
 * @copyright  2008 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class mod_quizp_lib_testcase extends advanced_testcase {
    public function test_quizp_has_grades() {
        $quizp = new stdClass();
        $quizp->grade = '100.0000';
        $quizp->sumgrades = '100.0000';
        $this->assertTrue(quizp_has_grades($quizp));
        $quizp->sumgrades = '0.0000';
        $this->assertFalse(quizp_has_grades($quizp));
        $quizp->grade = '0.0000';
        $this->assertFalse(quizp_has_grades($quizp));
        $quizp->sumgrades = '100.0000';
        $this->assertFalse(quizp_has_grades($quizp));
    }

    public function test_quizp_format_grade() {
        $quizp = new stdClass();
        $quizp->decimalpoints = 2;
        $this->assertEquals(quizp_format_grade($quizp, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(quizp_format_grade($quizp, 0), format_float(0, 2));
        $this->assertEquals(quizp_format_grade($quizp, 1.000000000000), format_float(1, 2));
        $quizp->decimalpoints = 0;
        $this->assertEquals(quizp_format_grade($quizp, 0.12345678), '0');
    }

    public function test_quizp_get_grade_format() {
        $quizp = new stdClass();
        $quizp->decimalpoints = 2;
        $this->assertEquals(quizp_get_grade_format($quizp), 2);
        $this->assertEquals($quizp->questiondecimalpoints, -1);
        $quizp->questiondecimalpoints = 2;
        $this->assertEquals(quizp_get_grade_format($quizp), 2);
        $quizp->decimalpoints = 3;
        $quizp->questiondecimalpoints = -1;
        $this->assertEquals(quizp_get_grade_format($quizp), 3);
        $quizp->questiondecimalpoints = 4;
        $this->assertEquals(quizp_get_grade_format($quizp), 4);
    }

    public function test_quizp_format_question_grade() {
        $quizp = new stdClass();
        $quizp->decimalpoints = 2;
        $quizp->questiondecimalpoints = 2;
        $this->assertEquals(quizp_format_question_grade($quizp, 0.12345678), format_float(0.12, 2));
        $this->assertEquals(quizp_format_question_grade($quizp, 0), format_float(0, 2));
        $this->assertEquals(quizp_format_question_grade($quizp, 1.000000000000), format_float(1, 2));
        $quizp->decimalpoints = 3;
        $quizp->questiondecimalpoints = -1;
        $this->assertEquals(quizp_format_question_grade($quizp, 0.12345678), format_float(0.123, 3));
        $this->assertEquals(quizp_format_question_grade($quizp, 0), format_float(0, 3));
        $this->assertEquals(quizp_format_question_grade($quizp, 1.000000000000), format_float(1, 3));
        $quizp->questiondecimalpoints = 4;
        $this->assertEquals(quizp_format_question_grade($quizp, 0.12345678), format_float(0.1235, 4));
        $this->assertEquals(quizp_format_question_grade($quizp, 0), format_float(0, 4));
        $this->assertEquals(quizp_format_question_grade($quizp, 1.000000000000), format_float(1, 4));
    }

    /**
     * Test deleting a quizp instance.
     */
    public function test_quizp_delete_instance() {
        global $SITE, $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();

        // Setup a quizp with 1 standard and 1 random question.
        $quizpgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quizp');
        $quizp = $quizpgenerator->create_instance(array('course' => $SITE->id, 'questionsperpage' => 3, 'grade' => 100.0));

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $cat = $questiongenerator->create_question_category();
        $standardq = $questiongenerator->create_question('shortanswer', null, array('category' => $cat->id));

        quizp_add_quizp_question($standardq->id, $quizp);
        quizp_add_random_questions($quizp, 0, $cat->id, 1, false);

        // Get the random question.
        $randomq = $DB->get_record('question', array('qtype' => 'random'));

        quizp_delete_instance($quizp->id);

        // Check that the random question was deleted.
        $count = $DB->count_records('question', array('id' => $randomq->id));
        $this->assertEquals(0, $count);
        // Check that the standard question was not deleted.
        $count = $DB->count_records('question', array('id' => $standardq->id));
        $this->assertEquals(1, $count);

        // Check that all the slots were removed.
        $count = $DB->count_records('quizp_slots', array('quizpid' => $quizp->id));
        $this->assertEquals(0, $count);

        // Check that the quizp was removed.
        $count = $DB->count_records('quizp', array('id' => $quizp->id));
        $this->assertEquals(0, $count);
    }

    /**
     * Test checking the completion state of a quizp.
     */
    public function test_quizp_get_completion_state() {
        global $CFG, $DB;
        $this->resetAfterTest(true);

        // Enable completion before creating modules, otherwise the completion data is not written in DB.
        $CFG->enablecompletion = true;

        // Create a course and student.
        $course = $this->getDataGenerator()->create_course(array('enablecompletion' => true));
        $passstudent = $this->getDataGenerator()->create_user();
        $failstudent = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->assertNotEmpty($studentrole);

        // Enrol students.
        $this->assertTrue($this->getDataGenerator()->enrol_user($passstudent->id, $course->id, $studentrole->id));
        $this->assertTrue($this->getDataGenerator()->enrol_user($failstudent->id, $course->id, $studentrole->id));

        // Make a scale and an outcome.
        $scale = $this->getDataGenerator()->create_scale();
        $data = array('courseid' => $course->id,
                      'fullname' => 'Team work',
                      'shortname' => 'Team work',
                      'scaleid' => $scale->id);
        $outcome = $this->getDataGenerator()->create_grade_outcome($data);

        // Make a quizp with the outcome on.
        $quizpgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quizp');
        $data = array('course' => $course->id,
                      'outcome_'.$outcome->id => 1,
                      'grade' => 100.0,
                      'questionsperpage' => 0,
                      'sumgrades' => 1,
                      'completion' => COMPLETION_TRACKING_AUTOMATIC,
                      'completionpass' => 1);
        $quizp = $quizpgenerator->create_instance($data);
        $cm = get_coursemodule_from_id('quizp', $quizp->cmid);

        // Create a couple of questions.
        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');

        $cat = $questiongenerator->create_question_category();
        $question = $questiongenerator->create_question('numerical', null, array('category' => $cat->id));
        quizp_add_quizp_question($question->id, $quizp);

        $quizpobj = quizp::create($quizp->id, $passstudent->id);

        // Set grade to pass.
        $item = grade_item::fetch(array('courseid' => $course->id, 'itemtype' => 'mod',
                                        'itemmodule' => 'quizp', 'iteminstance' => $quizp->id, 'outcomeid' => null));
        $item->gradepass = 80;
        $item->update();

        // Start the passing attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_quizp', $quizpobj->get_context());
        $quba->set_preferred_behaviour($quizpobj->get_quizp()->preferredbehaviour);

        $timenow = time();
        $attempt = quizp_create_attempt($quizpobj, 1, false, $timenow, false, $passstudent->id);
        quizp_start_new_attempt($quizpobj, $quba, $attempt, 1, $timenow);
        quizp_attempt_save_started($quizpobj, $quba, $attempt);

        // Process some responses from the student.
        $attemptobj = quizp_attempt::create($attempt->id);
        $tosubmit = array(1 => array('answer' => '3.14'));
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = quizp_attempt::create($attempt->id);
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());
        $attemptobj->process_finish($timenow, false);

        // Start the failing attempt.
        $quba = question_engine::make_questions_usage_by_activity('mod_quizp', $quizpobj->get_context());
        $quba->set_preferred_behaviour($quizpobj->get_quizp()->preferredbehaviour);

        $timenow = time();
        $attempt = quizp_create_attempt($quizpobj, 1, false, $timenow, false, $failstudent->id);
        quizp_start_new_attempt($quizpobj, $quba, $attempt, 1, $timenow);
        quizp_attempt_save_started($quizpobj, $quba, $attempt);

        // Process some responses from the student.
        $attemptobj = quizp_attempt::create($attempt->id);
        $tosubmit = array(1 => array('answer' => '0'));
        $attemptobj->process_submitted_actions($timenow, false, $tosubmit);

        // Finish the attempt.
        $attemptobj = quizp_attempt::create($attempt->id);
        $this->assertTrue($attemptobj->has_response_to_at_least_one_graded_question());
        $attemptobj->process_finish($timenow, false);

        // Check the results.
        $this->assertTrue(quizp_get_completion_state($course, $cm, $passstudent->id, 'return'));
        $this->assertFalse(quizp_get_completion_state($course, $cm, $failstudent->id, 'return'));
    }
}
