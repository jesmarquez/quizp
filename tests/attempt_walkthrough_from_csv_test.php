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
 * Quiz attempt walk through using data from csv file.
 *
 * @package    mod_quizp
 * @category   phpunit
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/quizp/locallib.php');

/**
 * Quiz attempt walk through using data from csv file.
 *
 * @package    mod_quizp
 * @category   phpunit
 * @copyright  2013 The Open University
 * @author     Jamie Pratt <me@jamiep.org>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_quizp_attempt_walkthrough_from_csv_testcase extends advanced_testcase {

    protected $files = array('questions', 'steps', 'results');

    /**
     * @var stdClass the quizp record we create.
     */
    protected $quizp;

    /**
     * @var array with slot no => question name => questionid. Question ids of questions created in the same category as random q.
     */
    protected $randqids;

    /**
     * The only test in this class. This is run multiple times depending on how many sets of files there are in fixtures/
     * directory.
     *
     * @param array $quizpsettings of settings read from csv file quizpzes.csv
     * @param PHPUnit_Extensions_Database_DataSet_ITable[] $csvdata of data read from csv file "questionsXX.csv",
     *                                                                                  "stepsXX.csv" and "resultsXX.csv".
     * @dataProvider get_data_for_walkthrough
     */
    public function test_walkthrough_from_csv($quizpsettings, $csvdata) {

        // CSV data files for these tests were generated using :
        // https://github.com/jamiepratt/moodle-quizp-tools/tree/master/responsegenerator

        $this->create_quizp_simulate_attempts_and_check_results($quizpsettings, $csvdata);
    }

    public function create_quizp($quizpsettings, $qs) {
        global $SITE, $DB;
        $this->setAdminUser();

        $questiongenerator = $this->getDataGenerator()->get_plugin_generator('core_question');
        $slots = array();
        $qidsbycat = array();
        $sumofgrades = 0;
        for ($rowno = 0; $rowno < $qs->getRowCount(); $rowno++) {
            $q = $this->explode_dot_separated_keys_to_make_subindexs($qs->getRow($rowno));

            $catname = array('name' => $q['cat']);
            if (!$cat = $DB->get_record('question_categories', array('name' => $q['cat']))) {
                $cat = $questiongenerator->create_question_category($catname);
            }
            $q['catid'] = $cat->id;
            foreach (array('which' => null, 'overrides' => array()) as $key => $default) {
                if (empty($q[$key])) {
                    $q[$key] = $default;
                }
            }

            if ($q['type'] !== 'random') {
                // Don't actually create random questions here.
                $overrides = array('category' => $cat->id, 'defaultmark' => $q['mark']) + $q['overrides'];
                $question = $questiongenerator->create_question($q['type'], $q['which'], $overrides);
                $q['id'] = $question->id;

                if (!isset($qidsbycat[$q['cat']])) {
                    $qidsbycat[$q['cat']] = array();
                }
                if (!empty($q['which'])) {
                    $name = $q['type'].'_'.$q['which'];
                } else {
                    $name = $q['type'];
                }
                $qidsbycat[$q['catid']][$name] = $q['id'];
            }
            if (!empty($q['slot'])) {
                $slots[$q['slot']] = $q;
                $sumofgrades += $q['mark'];
            }
        }

        ksort($slots);

        // Make a quizp.
        $quizpgenerator = $this->getDataGenerator()->get_plugin_generator('mod_quizp');

        // Settings from param override defaults.
        $aggregratedsettings = $quizpsettings + array('course' => $SITE->id,
                                                     'questionsperpage' => 0,
                                                     'grade' => 100.0,
                                                     'sumgrades' => $sumofgrades);

        $this->quizp = $quizpgenerator->create_instance($aggregratedsettings);

        $this->randqids = array();
        foreach ($slots as $slotno => $slotquestion) {
            if ($slotquestion['type'] !== 'random') {
                quizp_add_quizp_question($slotquestion['id'], $this->quizp, 0, $slotquestion['mark']);
            } else {
                quizp_add_random_questions($this->quizp, 0, $slotquestion['catid'], 1, 0);
                $this->randqids[$slotno] = $qidsbycat[$slotquestion['catid']];
            }
        }
    }

    /**
     * Create quizp, simulate attempts and check results (if resultsXX.csv exists).
     *
     * @param array $quizpsettings Quiz overrides for this quizp.
     * @param PHPUnit_Extensions_Database_DataSet_ITable[] $csvdata Data loaded from csv files for this test.
     */
    protected function create_quizp_simulate_attempts_and_check_results($quizpsettings, $csvdata) {
        $this->resetAfterTest(true);
        question_bank::get_qtype('random')->clear_caches_before_testing();

        $this->create_quizp($quizpsettings, $csvdata['questions']);

        $attemptids = $this->walkthrough_attempts($csvdata['steps']);

        if (isset($csvdata['results'])) {
            $this->check_attempts_results($csvdata['results'], $attemptids);
        }
    }

    /**
     * Get full path of CSV file.
     *
     * @param string $setname
     * @param string $test
     * @return string full path of file.
     */
    protected function get_full_path_of_csv_file($setname, $test) {
        return  __DIR__."/fixtures/{$setname}{$test}.csv";
    }

    /**
     * Load dataset from CSV file "{$setname}{$test}.csv".
     *
     * @param string $setname
     * @param string $test
     * @return \PHPUnit_Extensions_Database_DataSet_ITable
     */
    protected function load_csv_data_file($setname, $test='') {
        $files = array($setname => $this->get_full_path_of_csv_file($setname, $test));
        return $this->createCsvDataSet($files)->getTable($setname);
    }

    /**
     * Break down row of csv data into sub arrays, according to column names.
     *
     * @param array $row from csv file with field names with parts separate by '.'.
     * @return array the row with each part of the field name following a '.' being a separate sub array's index.
     */
    protected function explode_dot_separated_keys_to_make_subindexs(array $row) {
        $parts = array();
        foreach ($row as $columnkey => $value) {
            $newkeys = explode('.', trim($columnkey));
            $placetoputvalue =& $parts;
            foreach ($newkeys as $newkeydepth => $newkey) {
                if ($newkeydepth + 1 === count($newkeys)) {
                    $placetoputvalue[$newkey] = $value;
                } else {
                    // Going deeper down.
                    if (!isset($placetoputvalue[$newkey])) {
                        $placetoputvalue[$newkey] = array();
                    }
                    $placetoputvalue =& $placetoputvalue[$newkey];
                }
            }
        }
        return $parts;
    }

    /**
     * Data provider method for test_walkthrough_from_csv. Called by PHPUnit.
     *
     * @return array One array element for each run of the test. Each element contains an array with the params for
     *                  test_walkthrough_from_csv.
     */
    public function get_data_for_walkthrough() {
        $quizpzes = $this->load_csv_data_file('quizpzes');
        $datasets = array();
        for ($rowno = 0; $rowno < $quizpzes->getRowCount(); $rowno++) {
            $quizpsettings = $quizpzes->getRow($rowno);
            $dataset = array();
            foreach ($this->files as $file) {
                if (file_exists($this->get_full_path_of_csv_file($file, $quizpsettings['testnumber']))) {
                    $dataset[$file] = $this->load_csv_data_file($file, $quizpsettings['testnumber']);
                }
            }
            $datasets[] = array($quizpsettings, $dataset);
        }
        return $datasets;
    }

    /**
     * @param $steps PHPUnit_Extensions_Database_DataSet_ITable the step data from the csv file.
     * @return array attempt no as in csv file => the id of the quizp_attempt as stored in the db.
     */
    protected function walkthrough_attempts($steps) {
        global $DB;
        $attemptids = array();
        for ($rowno = 0; $rowno < $steps->getRowCount(); $rowno++) {

            $step = $this->explode_dot_separated_keys_to_make_subindexs($steps->getRow($rowno));
            // Find existing user or make a new user to do the quizp.
            $username = array('firstname' => $step['firstname'],
                              'lastname'  => $step['lastname']);

            if (!$user = $DB->get_record('user', $username)) {
                $user = $this->getDataGenerator()->create_user($username);
            }

            if (!isset($attemptids[$step['quizpattempt']])) {
                // Start the attempt.
                $quizpobj = quizp::create($this->quizp->id, $user->id);
                $quba = question_engine::make_questions_usage_by_activity('mod_quizp', $quizpobj->get_context());
                $quba->set_preferred_behaviour($quizpobj->get_quizp()->preferredbehaviour);

                $prevattempts = quizp_get_user_attempts($this->quizp->id, $user->id, 'all', true);
                $attemptnumber = count($prevattempts) + 1;
                $timenow = time();
                $attempt = quizp_create_attempt($quizpobj, $attemptnumber, false, $timenow, false, $user->id);
                // Select variant and / or random sub question.
                if (!isset($step['variants'])) {
                    $step['variants'] = array();
                }
                if (isset($step['randqs'])) {
                    // Replace 'names' with ids.
                    foreach ($step['randqs'] as $slotno => $randqname) {
                        $step['randqs'][$slotno] = $this->randqids[$slotno][$randqname];
                    }
                } else {
                    $step['randqs'] = array();
                }

                quizp_start_new_attempt($quizpobj, $quba, $attempt, $attemptnumber, $timenow, $step['randqs'], $step['variants']);
                quizp_attempt_save_started($quizpobj, $quba, $attempt);
                $attemptid = $attemptids[$step['quizpattempt']] = $attempt->id;
            } else {
                $attemptid = $attemptids[$step['quizpattempt']];
            }

            // Process some responses from the student.
            $attemptobj = quizp_attempt::create($attemptid);
            $attemptobj->process_submitted_actions($timenow, false, $step['responses']);

            // Finish the attempt.
            if (!isset($step['finished']) || ($step['finished'] == 1)) {
                $attemptobj = quizp_attempt::create($attemptid);
                $attemptobj->process_finish($timenow, false);
            }
        }
        return $attemptids;
    }

    /**
     * @param $results PHPUnit_Extensions_Database_DataSet_ITable the results data from the csv file.
     * @param $attemptids array attempt no as in csv file => the id of the quizp_attempt as stored in the db.
     */
    protected function check_attempts_results($results, $attemptids) {
        for ($rowno = 0; $rowno < $results->getRowCount(); $rowno++) {
            $result = $this->explode_dot_separated_keys_to_make_subindexs($results->getRow($rowno));
            // Re-load quizp attempt data.
            $attemptobj = quizp_attempt::create($attemptids[$result['quizpattempt']]);
            $this->check_attempt_results($result, $attemptobj);
        }
    }

    /**
     * Check that attempt results are as specified in $result.
     *
     * @param array        $result             row of data read from csv file.
     * @param quizp_attempt $attemptobj         the attempt object loaded from db.
     * @throws coding_exception
     */
    protected function check_attempt_results($result, $attemptobj) {
        foreach ($result as $fieldname => $value) {
            if ($value === '!NULL!') {
                $value = null;
            }
            switch ($fieldname) {
                case 'quizpattempt' :
                    break;
                case 'attemptnumber' :
                    $this->assertEquals($value, $attemptobj->get_attempt_number());
                    break;
                case 'slots' :
                    foreach ($value as $slotno => $slottests) {
                        foreach ($slottests as $slotfieldname => $slotvalue) {
                            switch ($slotfieldname) {
                                case 'mark' :
                                    $this->assertEquals(round($slotvalue, 2), $attemptobj->get_question_mark($slotno),
                                                        "Mark for slot $slotno of attempt {$result['quizpattempt']}.");
                                    break;
                                default :
                                    throw new coding_exception('Unknown slots sub field column in csv file '
                                                               .s($slotfieldname));
                            }
                        }
                    }
                    break;
                case 'finished' :
                    $this->assertEquals((bool)$value, $attemptobj->is_finished());
                    break;
                case 'summarks' :
                    $this->assertEquals($value, $attemptobj->get_sum_marks(), "Sum of marks of attempt {$result['quizpattempt']}.");
                    break;
                case 'quizpgrade' :
                    // Check quizp grades.
                    $grades = quizp_get_user_grades($attemptobj->get_quizp(), $attemptobj->get_userid());
                    $grade = array_shift($grades);
                    $this->assertEquals($value, $grade->rawgrade, "Quiz grade for attempt {$result['quizpattempt']}.");
                    break;
                case 'gradebookgrade' :
                    // Check grade book.
                    $gradebookgrades = grade_get_grades($attemptobj->get_courseid(),
                                                        'mod', 'quizp',
                                                        $attemptobj->get_quizpid(),
                                                        $attemptobj->get_userid());
                    $gradebookitem = array_shift($gradebookgrades->items);
                    $gradebookgrade = array_shift($gradebookitem->grades);
                    $this->assertEquals($value, $gradebookgrade->grade, "Gradebook grade for attempt {$result['quizpattempt']}.");
                    break;
                default :
                    throw new coding_exception('Unknown column in csv file '.s($fieldname));
            }
        }
    }
}
