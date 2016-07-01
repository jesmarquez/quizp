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
 * The mod_quizp attempt started event.
 *
 * @package    mod_quizp
 * @copyright  2013 Adrian Greeve <adrian@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_quizp\event;
defined('MOODLE_INTERNAL') || die();

/**
 * The mod_quizp attempt started event class.
 *
 * @property-read array $other {
 *      Extra information about event.
 *
 *      - int quizpid: (optional) the id of the quizp.
 * }
 *
 * @package    mod_quizp
 * @since      Moodle 2.6
 * @copyright  2013 Adrian Greeve <adrian@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_started extends \core\event\base {

    /**
     * Init method.
     */
    protected function init() {
        $this->data['objecttable'] = 'quizp_attempts';
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
    }

    /**
     * Returns description of what happened.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->relateduserid' has started the attempt with id '$this->objectid' for the " .
            "quizp with course module id '$this->contextinstanceid'.";
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('eventquizpattemptstarted', 'mod_quizp');
    }

    /**
     * Does this event replace a legacy event?
     *
     * @return string legacy event name
     */
    static public function get_legacy_eventname() {
        return 'quizp_attempt_started';
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/quizp/review.php', array('attempt' => $this->objectid));
    }

    /**
     * Legacy event data if get_legacy_eventname() is not empty.
     *
     * @return \stdClass
     */
    protected function get_legacy_eventdata() {
        $attempt = $this->get_record_snapshot('quizp_attempts', $this->objectid);

        $legacyeventdata = new \stdClass();
        $legacyeventdata->component = 'mod_quizp';
        $legacyeventdata->attemptid = $attempt->id;
        $legacyeventdata->timestart = $attempt->timestart;
        $legacyeventdata->timestamp = $attempt->timestart;
        $legacyeventdata->userid = $this->relateduserid;
        $legacyeventdata->quizpid = $attempt->quizp;
        $legacyeventdata->cmid = $this->contextinstanceid;
        $legacyeventdata->courseid = $this->courseid;

        return $legacyeventdata;
    }

    /**
     * Return the legacy event log data.
     *
     * @return array
     */
    protected function get_legacy_logdata() {
        $attempt = $this->get_record_snapshot('quizp_attempts', $this->objectid);

        return array($this->courseid, 'quizp', 'attempt', 'review.php?attempt=' . $this->objectid,
            $attempt->quizp, $this->contextinstanceid);
    }

    /**
     * Custom validation.
     *
     * @throws \coding_exception
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
    }

    public static function get_objectid_mapping() {
        return array('db' => 'quizp_attempts', 'restore' => 'quizp_attempt');
    }

    public static function get_other_mapping() {
        $othermapped = array();
        $othermapped['quizpid'] = array('db' => 'quizp', 'restore' => 'quizp');

        return $othermapped;
    }
}
