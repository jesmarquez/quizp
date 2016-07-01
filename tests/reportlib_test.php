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
 * Unit tests for (some of) mod/quizp/report/reportlib.php
 *
 * @package   mod_quizp
 * @category  phpunit
 * @copyright 2008 Jamie Pratt me@jamiep.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU Public License
 */


defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quizp/report/reportlib.php');


/**
 * This class contains the test cases for the functions in reportlib.php.
 *
 * @copyright 2008 Jamie Pratt me@jamiep.org
 * @license   http://www.gnu.org/copyleft/gpl.html GNU Public License
 */
class mod_quizp_reportlib_testcase extends advanced_testcase {
    public function test_quizp_report_index_by_keys() {
        $datum = array();
        $object = new stdClass();
        $object->qid = 3;
        $object->aid = 101;
        $object->response = '';
        $object->grade = 3;
        $datum[] = $object;

        $indexed = quizp_report_index_by_keys($datum, array('aid', 'qid'));

        $this->assertEquals($indexed[101][3]->qid, 3);
        $this->assertEquals($indexed[101][3]->aid, 101);
        $this->assertEquals($indexed[101][3]->response, '');
        $this->assertEquals($indexed[101][3]->grade, 3);

        $indexed = quizp_report_index_by_keys($datum, array('aid', 'qid'), false);

        $this->assertEquals($indexed[101][3][0]->qid, 3);
        $this->assertEquals($indexed[101][3][0]->aid, 101);
        $this->assertEquals($indexed[101][3][0]->response, '');
        $this->assertEquals($indexed[101][3][0]->grade, 3);
    }

    public function test_quizp_report_scale_summarks_as_percentage() {
        $quizp = new stdClass();
        $quizp->sumgrades = 10;
        $quizp->decimalpoints = 2;

        $this->assertEquals('12.34567%',
            quizp_report_scale_summarks_as_percentage(1.234567, $quizp, false));
        $this->assertEquals('12.35%',
            quizp_report_scale_summarks_as_percentage(1.234567, $quizp, true));
        $this->assertEquals('-',
            quizp_report_scale_summarks_as_percentage('-', $quizp, true));
    }

    public function test_quizp_report_qm_filter_select_only_one_attempt_allowed() {
        $quizp = new stdClass();
        $quizp->attempts = 1;
        $this->assertSame('', quizp_report_qm_filter_select($quizp));
    }

    public function test_quizp_report_qm_filter_select_average() {
        $quizp = new stdClass();
        $quizp->attempts = 10;
        $quizp->grademethod = QUIZP_GRADEAVERAGE;
        $this->assertSame('', quizp_report_qm_filter_select($quizp));
    }

    public function test_quizp_report_qm_filter_select_first_last_best() {
        global $DB;
        $this->resetAfterTest();

        $fakeattempt = new stdClass();
        $fakeattempt->userid = 123;
        $fakeattempt->quizp = 456;
        $fakeattempt->layout = '1,2,0,3,4,0,5';
        $fakeattempt->state = quizp_attempt::FINISHED;

        // We intentionally insert these in a funny order, to test the SQL better.
        // The test data is:
        // id | quizpid | user | attempt | sumgrades | state
        // ---------------------------------------------------
        // 4  | 456    | 123  | 1       | 30        | finished
        // 2  | 456    | 123  | 2       | 50        | finished
        // 1  | 456    | 123  | 3       | 50        | finished
        // 3  | 456    | 123  | 4       | null      | inprogress
        // 5  | 456    | 1    | 1       | 100       | finished
        // layout is only given because it has a not-null constraint.
        // uniqueid values are meaningless, but that column has a unique constraint.

        $fakeattempt->attempt = 3;
        $fakeattempt->sumgrades = 50;
        $fakeattempt->uniqueid = 13;
        $DB->insert_record('quizp_attempts', $fakeattempt);

        $fakeattempt->attempt = 2;
        $fakeattempt->sumgrades = 50;
        $fakeattempt->uniqueid = 26;
        $DB->insert_record('quizp_attempts', $fakeattempt);

        $fakeattempt->attempt = 4;
        $fakeattempt->sumgrades = null;
        $fakeattempt->uniqueid = 39;
        $fakeattempt->state = quizp_attempt::IN_PROGRESS;
        $DB->insert_record('quizp_attempts', $fakeattempt);

        $fakeattempt->attempt = 1;
        $fakeattempt->sumgrades = 30;
        $fakeattempt->uniqueid = 52;
        $fakeattempt->state = quizp_attempt::FINISHED;
        $DB->insert_record('quizp_attempts', $fakeattempt);

        $fakeattempt->attempt = 1;
        $fakeattempt->userid = 1;
        $fakeattempt->sumgrades = 100;
        $fakeattempt->uniqueid = 65;
        $DB->insert_record('quizp_attempts', $fakeattempt);

        $quizp = new stdClass();
        $quizp->attempts = 10;

        $quizp->grademethod = QUIZP_ATTEMPTFIRST;
        $firstattempt = $DB->get_records_sql("
                SELECT * FROM {quizp_attempts} quizpa WHERE userid = ? AND quizp = ? AND "
                        . quizp_report_qm_filter_select($quizp), array(123, 456));
        $this->assertEquals(1, count($firstattempt));
        $firstattempt = reset($firstattempt);
        $this->assertEquals(1, $firstattempt->attempt);

        $quizp->grademethod = QUIZP_ATTEMPTLAST;
        $lastattempt = $DB->get_records_sql("
                SELECT * FROM {quizp_attempts} quizpa WHERE userid = ? AND quizp = ? AND "
                . quizp_report_qm_filter_select($quizp), array(123, 456));
        $this->assertEquals(1, count($lastattempt));
        $lastattempt = reset($lastattempt);
        $this->assertEquals(3, $lastattempt->attempt);

        $quizp->attempts = 0;
        $quizp->grademethod = QUIZP_GRADEHIGHEST;
        $bestattempt = $DB->get_records_sql("
                SELECT * FROM {quizp_attempts} qa_alias WHERE userid = ? AND quizp = ? AND "
                . quizp_report_qm_filter_select($quizp, 'qa_alias'), array(123, 456));
        $this->assertEquals(1, count($bestattempt));
        $bestattempt = reset($bestattempt);
        $this->assertEquals(2, $bestattempt->attempt);
    }
}
