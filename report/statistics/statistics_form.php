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
 * Quiz statistics settings form definition.
 *
 * @package   quizp_statistics
 * @copyright 2014 Open University
 * @author    James Pratt <me@jamiep.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * This is the settings form for the quizp statistics report.
 *
 * @package   quizp_statistics
 * @copyright 2014 Open University
 * @author    James Pratt <me@jamiep.org>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizp_statistics_settings_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('header', 'preferencespage', get_string('reportsettings', 'quizp_statistics'));

        $options = array();
        foreach (array_keys(quizp_get_grading_options()) as $which) {
            $options[$which] = \quizp_statistics\calculator::using_attempts_lang_string($which);
        }

        $mform->addElement('select', 'whichattempts', get_string('calculatefrom', 'quizp_statistics'), $options);

        if (quizp_allows_multiple_tries($this->_customdata['quizp'])) {
            $mform->addElement('select', 'whichtries', get_string('whichtries', 'quizp_statistics'), array(
                                           question_attempt::FIRST_TRY    => get_string('firsttry', 'question'),
                                           question_attempt::LAST_TRY     => get_string('lasttry', 'question'),
                                           question_attempt::ALL_TRIES    => get_string('alltries', 'question'))
            );
            $mform->setDefault('whichtries', question_attempt::LAST_TRY);
        }
        $mform->addElement('submit', 'submitbutton', get_string('preferencessave', 'quizp_overview'));
    }

}
