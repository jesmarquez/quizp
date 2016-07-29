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
 * @package    mod_quizp
 * @subpackage backup-moodle2
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quizp/backup/moodle2/restore_quizp_stepslib.php');


/**
 * quizp restore task that provides all the settings and steps to perform one
 * complete restore of the activity
 *
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_quizp_activity_task extends restore_activity_task {

    /**
     * Define (add) particular settings this activity can have
     */
    protected function define_my_settings() {
        // No particular settings for this activity.
    }

    /**
     * Define (add) particular steps this activity can have
     */
    protected function define_my_steps() {
        // Quiz only has one structure step.
        $this->add_step(new restore_quizp_activity_structure_step('quizp_structure', 'quizp.xml'));
    }

    /**
     * Define the contents in the activity that must be
     * processed by the link decoder
     */
    public static function define_decode_contents() {
        $contents = array();

        $contents[] = new restore_decode_content('quizp', array('intro'), 'quizp');
        $contents[] = new restore_decode_content('quizp_feedback',
                array('feedbacktext'), 'quizp_feedback');

        return $contents;
    }

    /**
     * Define the decoding rules for links belonging
     * to the activity to be executed by the link decoder
     */
    public static function define_decode_rules() {
        $rules = array();

        $rules[] = new restore_decode_rule('QUIZPVIEWBYID',
                '/mod/quizp/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('QUIZPVIEWBYQ',
                '/mod/quizp/view.php?q=$1', 'quizp');
        $rules[] = new restore_decode_rule('QUIZPINDEX',
                '/mod/quizp/index.php?id=$1', 'course');

        return $rules;

    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * quizp logs. It must return one array
     * of {@link restore_log_rule} objects
     */
    public static function define_restore_log_rules() {
        $rules = array();

        $rules[] = new restore_log_rule('quizp', 'add',
                'view.php?id={course_module}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'update',
                'view.php?id={course_module}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'view',
                'view.php?id={course_module}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'preview',
                'view.php?id={course_module}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'report',
                'report.php?id={course_module}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'editquestions',
                'view.php?id={course_module}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'delete attempt',
                'report.php?id={course_module}', '[oldattempt]');
        $rules[] = new restore_log_rule('quizp', 'edit override',
                'overrideedit.php?id={quizp_override}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'delete override',
                'overrides.php.php?cmid={course_module}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'addcategory',
                'view.php?id={course_module}', '{question_category}');
        $rules[] = new restore_log_rule('quizp', 'view summary',
                'summary.php?attempt={quizp_attempt}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'manualgrade',
                'comment.php?attempt={quizp_attempt}&question={question}', '{quizp}');
        $rules[] = new restore_log_rule('quizp', 'manualgrading',
                'report.php?mode=grading&q={quizp}', '{quizp}');
        // All the ones calling to review.php have two rules to handle both old and new urls
        // in any case they are always converted to new urls on restore.
        // TODO: In Moodle 2.x (x >= 5) kill the old rules.
        // Note we are using the 'quizp_attempt' mapping because that is the
        // one containing the quizp_attempt->ids old an new for quizp-attempt.
        $rules[] = new restore_log_rule('quizp', 'attempt',
                'review.php?id={course_module}&attempt={quizp_attempt}', '{quizp}',
                null, null, 'review.php?attempt={quizp_attempt}');
        $rules[] = new restore_log_rule('quizp', 'attempt',
                'review.php?attempt={quizp_attempt}', '{quizp}',
                null, null, 'review.php?attempt={quizp_attempt}');
        // Old an new for quizp-submit.
        $rules[] = new restore_log_rule('quizp', 'submit',
                'review.php?id={course_module}&attempt={quizp_attempt}', '{quizp}',
                null, null, 'review.php?attempt={quizp_attempt}');
        $rules[] = new restore_log_rule('quizp', 'submit',
                'review.php?attempt={quizp_attempt}', '{quizp}');
        // Old an new for quizp-review.
        $rules[] = new restore_log_rule('quizp', 'review',
                'review.php?id={course_module}&attempt={quizp_attempt}', '{quizp}',
                null, null, 'review.php?attempt={quizp_attempt}');
        $rules[] = new restore_log_rule('quizp', 'review',
                'review.php?attempt={quizp_attempt}', '{quizp}');
        // Old an new for quizp-start attemp.
        $rules[] = new restore_log_rule('quizp', 'start attempt',
                'review.php?id={course_module}&attempt={quizp_attempt}', '{quizp}',
                null, null, 'review.php?attempt={quizp_attempt}');
        $rules[] = new restore_log_rule('quizp', 'start attempt',
                'review.php?attempt={quizp_attempt}', '{quizp}');
        // Old an new for quizp-close attemp.
        $rules[] = new restore_log_rule('quizp', 'close attempt',
                'review.php?id={course_module}&attempt={quizp_attempt}', '{quizp}',
                null, null, 'review.php?attempt={quizp_attempt}');
        $rules[] = new restore_log_rule('quizp', 'close attempt',
                'review.php?attempt={quizp_attempt}', '{quizp}');
        // Old an new for quizp-continue attempt.
        $rules[] = new restore_log_rule('quizp', 'continue attempt',
                'review.php?id={course_module}&attempt={quizp_attempt}', '{quizp}',
                null, null, 'review.php?attempt={quizp_attempt}');
        $rules[] = new restore_log_rule('quizp', 'continue attempt',
                'review.php?attempt={quizp_attempt}', '{quizp}');
        // Old an new for quizp-continue attemp.
        $rules[] = new restore_log_rule('quizp', 'continue attemp',
                'review.php?id={course_module}&attempt={quizp_attempt}', '{quizp}',
                null, 'continue attempt', 'review.php?attempt={quizp_attempt}');
        $rules[] = new restore_log_rule('quizp', 'continue attemp',
                'review.php?attempt={quizp_attempt}', '{quizp}',
                null, 'continue attempt');

        return $rules;
    }

    /**
     * Define the restore log rules that will be applied
     * by the {@link restore_logs_processor} when restoring
     * course logs. It must return one array
     * of {@link restore_log_rule} objects
     *
     * Note this rules are applied when restoring course logs
     * by the restore final task, but are defined here at
     * activity level. All them are rules not linked to any module instance (cmid = 0)
     */
    public static function define_restore_log_rules_for_course() {
        $rules = array();

        $rules[] = new restore_log_rule('quizp', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
