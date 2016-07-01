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
 * Implementaton of the quizpaccess_openclosedate plugin.
 *
 * @package    quizpaccess
 * @subpackage openclosedate
 * @copyright  2011 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quizp/accessrule/accessrulebase.php');


/**
 * A rule enforcing open and close dates.
 *
 * @copyright  2009 Tim Hunt
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quizpaccess_openclosedate extends quizp_access_rule_base {

    public static function make(quizp $quizpobj, $timenow, $canignoretimelimits) {
        // This rule is always used, even if the quizp has no open or close date.
        return new self($quizpobj, $timenow);
    }

    public function description() {
        $result = array();
        if ($this->timenow < $this->quizp->timeopen) {
            $result[] = get_string('quizpnotavailable', 'quizpaccess_openclosedate',
                    userdate($this->quizp->timeopen));
            if ($this->quizp->timeclose) {
                $result[] = get_string('quizpcloseson', 'quizp', userdate($this->quizp->timeclose));
            }

        } else if ($this->quizp->timeclose && $this->timenow > $this->quizp->timeclose) {
            $result[] = get_string('quizpclosed', 'quizp', userdate($this->quizp->timeclose));

        } else {
            if ($this->quizp->timeopen) {
                $result[] = get_string('quizpopenedon', 'quizp', userdate($this->quizp->timeopen));
            }
            if ($this->quizp->timeclose) {
                $result[] = get_string('quizpcloseson', 'quizp', userdate($this->quizp->timeclose));
            }
        }

        return $result;
    }

    public function prevent_access() {
        $message = get_string('notavailable', 'quizpaccess_openclosedate');

        if ($this->timenow < $this->quizp->timeopen) {
            return $message;
        }

        if (!$this->quizp->timeclose) {
            return false;
        }

        if ($this->timenow <= $this->quizp->timeclose) {
            return false;
        }

        if ($this->quizp->overduehandling != 'graceperiod') {
            return $message;
        }

        if ($this->timenow <= $this->quizp->timeclose + $this->quizp->graceperiod) {
            return false;
        }

        return $message;
    }

    public function is_finished($numprevattempts, $lastattempt) {
        return $this->quizp->timeclose && $this->timenow > $this->quizp->timeclose;
    }

    public function end_time($attempt) {
        if ($this->quizp->timeclose) {
            return $this->quizp->timeclose;
        }
        return false;
    }

    public function time_left_display($attempt, $timenow) {
        // If this is a teacher preview after the close date, do not show
        // the time.
        if ($attempt->preview && $timenow > $this->quizp->timeclose) {
            return false;
        }
        // Otherwise, return to the time left until the close date, providing that is
        // less than QUIZP_SHOW_TIME_BEFORE_DEADLINE.
        $endtime = $this->end_time($attempt);
        if ($endtime !== false && $timenow > $endtime - QUIZP_SHOW_TIME_BEFORE_DEADLINE) {
            return $endtime - $timenow;
        }
        return false;
    }
}
