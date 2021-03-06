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
 * Steps definitions related to mod_quizp.
 *
 * @package   mod_quizp
 * @category  test
 * @copyright 2014 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');
require_once(__DIR__ . '/../../../../question/tests/behat/behat_question_base.php');

use Behat\Behat\Context\Step\Given as Given,
    Behat\Gherkin\Node\TableNode as TableNode,
    Behat\Mink\Exception\ExpectationException as ExpectationException;

/**
 * Steps definitions related to mod_quizp.
 *
 * @copyright 2014 Marina Glancy
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_quizp extends behat_question_base {

    /**
     * Put the specified questions on the specified pages of a given quizp.
     *
     * The first row should be column names:
     * | question | page | maxmark | requireprevious |
     * The first two of those are required. The others are optional.
     *
     * question        needs to uniquely match a question name.
     * page            is a page number. Must start at 1, and on each following
     *                 row should be the same as the previous, or one more.
     * maxmark         What the question is marked out of. Defaults to question.defaultmark.
     * requireprevious The question can only be attempted after the previous one was completed.
     *
     * Then there should be a number of rows of data, one for each question you want to add.
     *
     * For backwards-compatibility reasons, specifying the column names is optional
     * (but strongly encouraged). If not specified, the columns are asseumed to be
     * | question | page | maxmark |.
     *
     * @param string $quizpname the name of the quizp to add questions to.
     * @param TableNode $data information about the questions to add.
     *
     * @Given /^quizp "([^"]*)" contains the following questions:$/
     */
    public function quizp_contains_the_following_questions($quizpname, TableNode $data) {
        global $DB;

        $quizp = $DB->get_record('quizp', array('name' => $quizpname), '*', MUST_EXIST);

        // Deal with backwards-compatibility, optional first row.
        $firstrow = $data->getRow(0);
        if (!in_array('question', $firstrow) && !in_array('page', $firstrow)) {
            if (count($firstrow) == 2) {
                $headings = array('question', 'page');
            } else if (count($firstrow) == 3) {
                $headings = array('question', 'page', 'maxmark');
            } else {
                throw new ExpectationException('When adding questions to a quizp, you should give 2 or three 3 things: ' .
                        ' the question name, the page number, and optionally the maxiumum mark. ' .
                        count($firstrow) . ' values passed.', $this->getSession());
            }
            $rows = $data->getRows();
            array_unshift($rows, $headings);
            $data->setRows($rows);
        }

        // Add the questions.
        $lastpage = 0;
        foreach ($data->getHash() as $questiondata) {
            if (!array_key_exists('question', $questiondata)) {
                throw new ExpectationException('When adding questions to a quizp, ' .
                        'the question name column is required.', $this->getSession());
            }
            if (!array_key_exists('page', $questiondata)) {
                throw new ExpectationException('When adding questions to a quizp, ' .
                        'the page number column is required.', $this->getSession());
            }

            // Question id.
            $questionid = $DB->get_field('question', 'id',
                    array('name' => $questiondata['question']), MUST_EXIST);

            // Page number.
            $page = clean_param($questiondata['page'], PARAM_INT);
            if ($page <= 0 || (string) $page !== $questiondata['page']) {
                throw new ExpectationException('The page number for question "' .
                         $questiondata['question'] . '" must be a positive integer.',
                        $this->getSession());
            }
            if ($page < $lastpage || $page > $lastpage + 1) {
                throw new ExpectationException('When adding questions to a quizp, ' .
                        'the page number for each question must either be the same, ' .
                        'or one more, then the page number for the previous question.',
                        $this->getSession());
            }
            $lastpage = $page;

            // Max mark.
            if (!array_key_exists('maxmark', $questiondata) || $questiondata['maxmark'] === '') {
                $maxmark = null;
            } else {
                $maxmark = clean_param($questiondata['maxmark'], PARAM_FLOAT);
                if (!is_numeric($questiondata['maxmark']) || $maxmark < 0) {
                    throw new ExpectationException('The max mark for question "' .
                            $questiondata['question'] . '" must be a positive number.',
                            $this->getSession());
                }
            }

            // Add the question.
            quizp_add_quizp_question($questionid, $quizp, $page, $maxmark);

            // Require previous.
            if (array_key_exists('requireprevious', $questiondata)) {
                if ($questiondata['requireprevious'] === '1') {
                    $slot = $DB->get_field('quizp_slots', 'MAX(slot)', array('quizpid' => $quizp->id));
                    $DB->set_field('quizp_slots', 'requireprevious', 1,
                            array('quizpid' => $quizp->id, 'slot' => $slot));
                } else if ($questiondata['requireprevious'] !== '' && $questiondata['requireprevious'] !== '0') {
                    throw new ExpectationException('Require previous for question "' .
                            $questiondata['question'] . '" should be 0, 1 or blank.',
                            $this->getSession());
                }
            }
        }

        quizp_update_sumgrades($quizp);
    }

    /**
     * Put the specified section headings to start at specified pages of a given quizp.
     *
     * The first row should be column names:
     * | heading | firstslot | shufflequestions |
     *
     * heading   is the section heading text
     * firstslot is the slot number where the section starts
     * shuffle   whether this section is shuffled (0 or 1)
     *
     * Then there should be a number of rows of data, one for each section you want to add.
     *
     * @param string $quizpname the name of the quizp to add sections to.
     * @param TableNode $data information about the sections to add.
     *
     * @Given /^quizp "([^"]*)" contains the following sections:$/
     */
    public function quizp_contains_the_following_sections($quizpname, TableNode $data) {
        global $DB;

        $quizp = $DB->get_record('quizp', array('name' => $quizpname), '*', MUST_EXIST);

        // Add the sections.
        $previousfirstslot = 0;
        foreach ($data->getHash() as $rownumber => $sectiondata) {
            if (!array_key_exists('heading', $sectiondata)) {
                throw new ExpectationException('When adding sections to a quizp, ' .
                        'the heading name column is required.', $this->getSession());
            }
            if (!array_key_exists('firstslot', $sectiondata)) {
                throw new ExpectationException('When adding sections to a quizp, ' .
                        'the firstslot name column is required.', $this->getSession());
            }
            if (!array_key_exists('shuffle', $sectiondata)) {
                throw new ExpectationException('When adding sections to a quizp, ' .
                        'the shuffle name column is required.', $this->getSession());
            }

            if ($rownumber == 0) {
                $section = $DB->get_record('quizp_sections', array('quizpid' => $quizp->id), '*', MUST_EXIST);
            } else {
                $section = new stdClass();
                $section->quizpid = $quizp->id;
            }

            // Heading.
            $section->heading = $sectiondata['heading'];

            // First slot.
            $section->firstslot = clean_param($sectiondata['firstslot'], PARAM_INT);
            if ($section->firstslot <= $previousfirstslot ||
                    (string) $section->firstslot !== $sectiondata['firstslot']) {
                throw new ExpectationException('The firstslot number for section "' .
                        $sectiondata['heading'] . '" must an integer greater than the previous section firstslot.',
                        $this->getSession());
            }
            if ($rownumber == 0 && $section->firstslot != 1) {
                throw new ExpectationException('The first section must have firstslot set to 1.',
                        $this->getSession());
            }

            // Shuffle.
            $section->shufflequestions = clean_param($sectiondata['shuffle'], PARAM_INT);
            if ((string) $section->shufflequestions !== $sectiondata['shuffle']) {
                throw new ExpectationException('The shuffle value for section "' .
                        $sectiondata['heading'] . '" must be 0 or 1.',
                        $this->getSession());
            }

            if ($rownumber == 0) {
                $DB->update_record('quizp_sections', $section);
            } else {
                $DB->insert_record('quizp_sections', $section);
            }
        }

        if ($section->firstslot > $DB->count_records('quizp_slots', array('quizpid' => $quizp->id))) {
            throw new ExpectationException('The section firstslot must be less than the total number of slots in the quizp.',
                    $this->getSession());
        }
    }

    /**
     * Adds a question to the existing quizp with filling the form.
     *
     * The form for creating a question should be on one page.
     *
     * @When /^I add a "(?P<question_type_string>(?:[^"]|\\")*)" question to the "(?P<quizp_name_string>(?:[^"]|\\")*)" quizp with:$/
     * @param string $questiontype
     * @param string $quizpname
     * @param TableNode $questiondata with data for filling the add question form
     */
    public function i_add_question_to_the_quizp_with($questiontype, $quizpname, TableNode $questiondata) {
        $quizpname = $this->escape($quizpname);
        $editquizp = $this->escape(get_string('editquizp', 'quizp'));
        $quizpadmin = $this->escape(get_string('pluginadministration', 'quizp'));
        $addaquestion = $this->escape(get_string('addaquestion', 'quizp'));
        $menuxpath = "//div[contains(@class, ' page-add-actions ')][last()]//a[contains(@class, ' textmenu')]";
        $itemxpath = "//div[contains(@class, ' page-add-actions ')][last()]//a[contains(@class, ' addquestion ')]";
        return array_merge(array(
            new Given("I follow \"$quizpname\""),
            new Given("I navigate to \"$editquizp\" node in \"$quizpadmin\""),
            new Given("I click on \"$menuxpath\" \"xpath_element\""),
            new Given("I click on \"$itemxpath\" \"xpath_element\""),
                ), $this->finish_adding_question($questiontype, $questiondata));
    }

    /**
     * Set the max mark for a question on the Edit quizp page.
     *
     * @When /^I set the max mark for question "(?P<question_name_string>(?:[^"]|\\")*)" to "(?P<new_mark_string>(?:[^"]|\\")*)"$/
     * @param string $questionname the name of the question to set the max mark for.
     * @param string $newmark the mark to set
     */
    public function i_set_the_max_mark_for_quizp_question($questionname, $newmark) {
        return array(
            new Given('I follow "' . $this->escape(get_string('editmaxmark', 'quizp')) . '"'),
            new Given('I wait until "li input[name=maxmark]" "css_element" exists'),
            new Given('I should see "' . $this->escape(get_string('edittitleinstructions')) . '"'),
            new Given('I set the field "maxmark" to "' . $this->escape($newmark) . chr(10) . '"'),
        );
    }

    /**
     * Open the add menu on a given page, or at the end of the Edit quizp page.
     * @Given /^I open the "(?P<page_n_or_last_string>(?:[^"]|\\")*)" add to quizp menu$/
     * @param string $pageorlast either "Page n" or "last".
     */
    public function i_open_the_add_to_quizp_menu_for($pageorlast) {

        if (!$this->running_javascript()) {
            throw new DriverException('Activities actions menu not available when Javascript is disabled');
        }

        if ($pageorlast == 'last') {
            $xpath = "//div[@class = 'last-add-menu']//a[contains(@class, 'textmenu') and contains(., 'Add')]";
        } else if (preg_match('~Page (\d+)~', $pageorlast, $matches)) {
            $xpath = "//li[@id = 'page-{$matches[1]}']//a[contains(@class, 'textmenu') and contains(., 'Add')]";
        } else {
            throw new ExpectationException("The I open the add to quizp menu step must specify either 'Page N' or 'last'.");
        }
        $menu = $this->find('xpath', $xpath)->click();
    }

    /**
     * Click on a given link in the moodle-actionmenu that is currently open.
     * @Given /^I follow "(?P<link_string>(?:[^"]|\\")*)" in the open menu$/
     * @param string $linkstring the text (or id, etc.) of the link to click.
     * @return array of steps.
     */
    public function i_follow_in_the_open_menu($linkstring) {
        $openmenuxpath = "//div[contains(@class, 'moodle-actionmenu') and contains(@class, 'show')]";
        return array(
            new Given('I click on "' . $linkstring . '" "link" in the "' . $openmenuxpath . '" "xpath_element"'),
        );
    }

    /**
     * Check whether a particular question is on a particular page of the quizp on the Edit quizp page.
     * @Given /^I should see "(?P<question_name>(?:[^"]|\\")*)" on quizp page "(?P<page_number>\d+)"$/
     * @param string $questionname the name of the question we are looking for.
     * @param number $pagenumber the page it should be found on.
     * @return array of steps.
     */
    public function i_should_see_on_quizp_page($questionname, $pagenumber) {
        $xpath = "//li[contains(., '" . $this->escape($questionname) .
                "')][./preceding-sibling::li[contains(@class, 'pagenumber')][1][contains(., 'Page " .
                $pagenumber . "')]]";
        return array(
            new Given('"' . $xpath . '" "xpath_element" should exist'),
        );
    }

    /**
     * Check whether a particular question is not on a particular page of the quizp on the Edit quizp page.
     * @Given /^I should not see "(?P<question_name>(?:[^"]|\\")*)" on quizp page "(?P<page_number>\d+)"$/
     * @param string $questionname the name of the question we are looking for.
     * @param number $pagenumber the page it should be found on.
     * @return array of steps.
     */
    public function i_should_not_see_on_quizp_page($questionname, $pagenumber) {
        $xpath = "//li[contains(., '" . $this->escape($questionname) .
                "')][./preceding-sibling::li[contains(@class, 'pagenumber')][1][contains(., 'Page " .
                $pagenumber . "')]]";
        return array(
            new Given('"' . $xpath . '" "xpath_element" should not exist'),
        );
    }

    /**
     * Check whether one question comes before another on the Edit quizp page.
     * The two questions must be on the same page.
     * @Given /^I should see "(?P<first_q_name>(?:[^"]|\\")*)" before "(?P<second_q_name>(?:[^"]|\\")*)" on the edit quizp page$/
     * @param string $firstquestionname the name of the question that should come first in order.
     * @param string $secondquestionname the name of the question that should come immediately after it in order.
     * @return array of steps.
     */
    public function i_should_see_before_on_the_edit_quizp_page($firstquestionname, $secondquestionname) {
        $xpath = "//li[contains(@class, ' slot ') and contains(., '" . $this->escape($firstquestionname) .
                "')]/following-sibling::li[contains(@class, ' slot ')][1]" .
                "[contains(., '" . $this->escape($secondquestionname) . "')]";
        return array(
            new Given('"' . $xpath . '" "xpath_element" should exist'),
        );
    }

    /**
     * Check the number displayed alongside a question on the Edit quizp page.
     * @Given /^"(?P<question_name>(?:[^"]|\\")*)" should have number "(?P<number>(?:[^"]|\\")*)" on the edit quizp page$/
     * @param string $questionname the name of the question we are looking for.
     * @param number $number the number (or 'i') that should be displayed beside that question.
     * @return array of steps.
     */
    public function should_have_number_on_the_edit_quizp_page($questionname, $number) {
        $xpath = "//li[contains(@class, 'slot') and contains(., '" . $this->escape($questionname) .
                "')]//span[contains(@class, 'slotnumber') and normalize-space(text()) = '" . $this->escape($number) . "']";
        return array(
            new Given('"' . $xpath . '" "xpath_element" should exist'),
        );
    }

    /**
     * Get the xpath for a partcular add/remove page-break icon.
     * @param string $addorremoves 'Add' or 'Remove'.
     * @param string $questionname the name of the question before the icon.
     * @return string the requried xpath.
     */
    protected function get_xpath_page_break_icon_after_question($addorremoves, $questionname) {
        return "//li[contains(@class, 'slot') and contains(., '" . $this->escape($questionname) .
                "')]//a[contains(@class, 'page_split_join') and @title = '" . $addorremoves . " page break']";
    }

    /**
     * Click the add or remove page-break icon after a particular question.
     * @When /^I click on the "(Add|Remove)" page break icon after question "(?P<question_name>(?:[^"]|\\")*)"$/
     * @param string $addorremoves 'Add' or 'Remove'.
     * @param string $questionname the name of the question before the icon to click.
     * @return array of steps.
     */
    public function i_click_on_the_page_break_icon_after_question($addorremoves, $questionname) {
        $xpath = $this->get_xpath_page_break_icon_after_question($addorremoves, $questionname);
        return array(
            new Given('I click on "' . $xpath . '" "xpath_element"'),
        );
    }

    /**
     * Assert the add or remove page-break icon after a particular question exists.
     * @When /^the "(Add|Remove)" page break icon after question "(?P<question_name>(?:[^"]|\\")*)" should exist$/
     * @param string $addorremoves 'Add' or 'Remove'.
     * @param string $questionname the name of the question before the icon to click.
     * @return array of steps.
     */
    public function the_page_break_icon_after_question_should_exist($addorremoves, $questionname) {
        $xpath = $this->get_xpath_page_break_icon_after_question($addorremoves, $questionname);
        return array(
            new Given('"' . $xpath . '" "xpath_element" should exist'),
        );
    }

    /**
     * Assert the add or remove page-break icon after a particular question does not exist.
     * @When /^the "(Add|Remove)" page break icon after question "(?P<question_name>(?:[^"]|\\")*)" should not exist$/
     * @param string $addorremoves 'Add' or 'Remove'.
     * @param string $questionname the name of the question before the icon to click.
     * @return array of steps.
     */
    public function the_page_break_icon_after_question_should_not_exist($addorremoves, $questionname) {
        $xpath = $this->get_xpath_page_break_icon_after_question($addorremoves, $questionname);
        return array(
            new Given('"' . $xpath . '" "xpath_element" should not exist'),
        );
    }

    /**
     * Check the add or remove page-break link after a particular question contains the given parameters in its url.
     * @When /^the "(Add|Remove)" page break link after question "(?P<question_name>(?:[^"]|\\")*) should contain:"$/
     * @param string $addorremoves 'Add' or 'Remove'.
     * @param string $questionname the name of the question before the icon to click.
     * @param TableNode $paramdata with data for checking the page break url
     * @return array of steps.
     */
    public function the_page_break_link_after_question_should_contain($addorremoves, $questionname, $paramdata) {
        $xpath = $this->get_xpath_page_break_icon_after_question($addorremoves, $questionname);
        return array(
            new Given('I click on "' . $xpath . '" "xpath_element"'),
        );
    }

    /**
     * Set Shuffle for shuffling questions within sections
     *
     * @param string $heading the heading of the section to change shuffle for.
     *
     * @Given /^I click on shuffle for section "([^"]*)" on the quizp edit page$/
     */
    public function i_click_on_shuffle_for_section($heading) {
        $xpath = $this->get_xpath_for_shuffle_checkbox($heading);
        $checkbox = $this->find('xpath', $xpath);
        $this->ensure_node_is_visible($checkbox);
        $checkbox->click();
    }

    /**
     * Check the shuffle checkbox for a particular section.
     *
     * @param string $heading the heading of the section to check shuffle for
     * @param int $value whether the shuffle checkbox should be on or off.
     *
     * @Given /^shuffle for section "([^"]*)" should be "(On|Off)" on the quizp edit page$/
     */
    public function shuffle_for_section_should_be($heading, $value) {
        $xpath = $this->get_xpath_for_shuffle_checkbox($heading);
        $checkbox = $this->find('xpath', $xpath);
        $this->ensure_node_is_visible($checkbox);
        if ($value == 'On' && !$checkbox->isChecked()) {
            $msg = "Shuffle for section '$heading' is not checked, but you are expecting it to be checked ($value). " .
                    "Check the line with: \nshuffle for section \"$heading\" should be \"$value\" on the quizp edit page" .
                    "\nin your behat script";
            throw new ExpectationException($msg, $this->getSession());
        } else if ($value == 'Off' && $checkbox->isChecked()) {
            $msg = "Shuffle for section '$heading' is checked, but you are expecting it not to be ($value). " .
                    "Check the line with: \nshuffle for section \"$heading\" should be \"$value\" on the quizp edit page" .
                    "\nin your behat script";
            throw new ExpectationException($msg, $this->getSession());
        }
    }

    /**
     * Return the xpath for shuffle checkbox in section heading
     * @param strung $heading
     * @return string
     */
    protected function get_xpath_for_shuffle_checkbox($heading) {
         return "//div[contains(@class, 'section-heading') and contains(., '" . $this->escape($heading) .
                "')]//input[@type = 'checkbox']";
    }

    /**
     * Move a question on the Edit quizp page by first clicking on the Move icon,
     * then clicking one of the "After ..." links.
     * @When /^I move "(?P<question_name>(?:[^"]|\\")*)" to "(?P<target>(?:[^"]|\\")*)" in the quizp by clicking the move icon$/
     * @param string $questionname the name of the question we are looking for.
     * @param string $target the target place to move to. One of the links in the pop-up like
     *      "After Page 1" or "After Question N".
     * @return array of steps.
     */
    public function i_move_question_after_item_by_clicking_the_move_icon($questionname, $target) {
        $iconxpath = "//li[contains(@class, ' slot ') and contains(., '" . $this->escape($questionname) .
                "')]//span[contains(@class, 'editing_move')]";
        return array(
            new Given('I click on "' . $iconxpath . '" "xpath_element"'),
            new Given('I click on "' . $this->escape($target) . '" "text"'),
        );
    }

    /**
     * Move a question on the Edit quizp page by dragging a given question on top of another item.
     * @When /^I move "(?P<question_name>(?:[^"]|\\")*)" to "(?P<target>(?:[^"]|\\")*)" in the quizp by dragging$/
     * @param string $questionname the name of the question we are looking for.
     * @param string $target the target place to move to. Ether a question name, or "Page N"
     * @return array of steps.
     */
    public function i_move_question_after_item_by_dragging($questionname, $target) {
        $iconxpath = "//li[contains(@class, ' slot ') and contains(., '" . $this->escape($questionname) .
                "')]//span[contains(@class, 'editing_move')]//img";
        $destinationxpath = "//li[contains(@class, ' slot ') or contains(@class, 'pagenumber ')]" .
                "[contains(., '" . $this->escape($target) . "')]";
        return array(
            new Given('I drag "' . $iconxpath . '" "xpath_element" ' .
                'and I drop it in "' . $destinationxpath . '" "xpath_element"'),
        );
    }

    /**
     * Delete a question on the Edit quizp page by first clicking on the Delete icon,
     * then clicking one of the "After ..." links.
     * @When /^I delete "(?P<question_name>(?:[^"]|\\")*)" in the quizp by clicking the delete icon$/
     * @param string $questionname the name of the question we are looking for.
     * @return array of steps.
     */
    public function i_delete_question_by_clicking_the_delete_icon($questionname) {
        $slotxpath = "//li[contains(@class, ' slot ') and contains(., '" . $this->escape($questionname) .
                "')]";
        $deletexpath = "//a[contains(@class, 'editing_delete')]";
        return array(
            new Given('I click on "' . $slotxpath . $deletexpath . '" "xpath_element"'),
            new Given('I click on "Yes" "button" in the "Confirm" "dialogue"'),
        );
    }

    /**
     * Set the section heading for a given section on the Edit quizp page
     *
     * @When /^I change quizp section heading "(?P<section_name_string>(?:[^"]|\\")*)" to "(?P<new_section_heading_string>(?:[^"]|\\")*)"$/
     * @param string $sectionname the heading to change.
     * @param string $sectionheading the new heading to set.
     */
    public function i_set_the_section_heading_for($sectionname, $sectionheading) {
        return array(
                new Given('I follow "' . $this->escape("Edit heading '{$sectionname}'") . '"'),
                new Given('I should see "' . $this->escape(get_string('edittitleinstructions')) . '"'),
                new Given('I set the field "section" to "' . $this->escape($sectionheading) . chr(10) . '"'),
        );
    }

    /**
     * Check that a given question comes after a given section heading in the
     * quizp navigation block.
     *
     * @Then /^I should see question "(?P<questionnumber>\d+)" in section "(?P<section_heading_string>(?:[^"]|\\")*)" in the quizp navigation$/
     * @param int $questionnumber the number of the question to check.
     * @param string $sectionheading which section heading it should appear after.
     */
    public function i_should_see_question_in_section_in_the_quizp_navigation($questionnumber, $sectionheading) {

        // Using xpath literal to avoid quotes problems.
        $questionnumberliteral = $this->getSession()->getSelectorsHandler()->xpathLiteral('Question ' . $questionnumber);
        $headingliteral = $this->getSession()->getSelectorsHandler()->xpathLiteral($sectionheading);

        // Split in two checkings to give more feedback in case of exception.
        $exception = new ExpectationException('Question "' . $questionnumber . '" is not in section "' .
                $sectionheading . '" in the quizp navigation.', $this->getSession());
        $xpath = "//div[@id = 'mod_quizp_navblock']//*[contains(concat(' ', normalize-space(@class), ' '), ' qnbutton ') and " .
                "contains(., {$questionnumberliteral}) and contains(preceding-sibling::h3[1], {$headingliteral})]";
        $this->find('xpath', $xpath);
    }
}
