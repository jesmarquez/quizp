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
 * Administration settings definitions for the quizp module.
 *
 * @package   mod_quizp
 * @copyright 2010 Petr Skoda
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quizp/lib.php');

// First get a list of quizp reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$reports = core_component::get_plugin_list_with_file('quizp', 'settings.php', false);
$reportsbyname = array();
foreach ($reports as $report => $reportdir) {
    $strreportname = get_string($report . 'report', 'quizp_'.$report);
    $reportsbyname[$strreportname] = $report;
}
core_collator::ksort($reportsbyname);

// First get a list of quizp reports with there own settings pages. If there none,
// we use a simpler overall menu structure.
$rules = core_component::get_plugin_list_with_file('quizpaccess', 'settings.php', false);
$rulesbyname = array();
foreach ($rules as $rule => $ruledir) {
    $strrulename = get_string('pluginname', 'quizpaccess_' . $rule);
    $rulesbyname[$strrulename] = $rule;
}
core_collator::ksort($rulesbyname);

// Create the quizp settings page.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $pagetitle = get_string('modulename', 'quizp');
} else {
    $pagetitle = get_string('generalsettings', 'admin');
}
$quizpsettings = new admin_settingpage('modsettingquizp', $pagetitle, 'moodle/site:config');

if ($ADMIN->fulltree) {
    // Introductory explanation that all the settings are defaults for the add quizp form.
    $quizpsettings->add(new admin_setting_heading('quizpintro', '', get_string('configintro', 'quizp')));

    // Time limit.
    $quizpsettings->add(new admin_setting_configduration_with_advanced('quizp/timelimit',
            get_string('timelimit', 'quizp'), get_string('configtimelimitsec', 'quizp'),
            array('value' => '0', 'adv' => false), 60));

    // What to do with overdue attempts.
    $quizpsettings->add(new mod_quizp_admin_setting_overduehandling('quizp/overduehandling',
            get_string('overduehandling', 'quizp'), get_string('overduehandling_desc', 'quizp'),
            array('value' => 'autosubmit', 'adv' => false), null));

    // Grace period time.
    $quizpsettings->add(new admin_setting_configduration_with_advanced('quizp/graceperiod',
            get_string('graceperiod', 'quizp'), get_string('graceperiod_desc', 'quizp'),
            array('value' => '86400', 'adv' => false)));

    // Minimum grace period used behind the scenes.
    $quizpsettings->add(new admin_setting_configduration('quizp/graceperiodmin',
            get_string('graceperiodmin', 'quizp'), get_string('graceperiodmin_desc', 'quizp'),
            60, 1));

    // Number of attempts.
    $options = array(get_string('unlimited'));
    for ($i = 1; $i <= QUIZP_MAX_ATTEMPT_OPTION; $i++) {
        $options[$i] = $i;
    }
    $quizpsettings->add(new admin_setting_configselect_with_advanced('quizp/attempts',
            get_string('attemptsallowed', 'quizp'), get_string('configattemptsallowed', 'quizp'),
            array('value' => 0, 'adv' => false), $options));

    // Grading method.
    $quizpsettings->add(new mod_quizp_admin_setting_grademethod('quizp/grademethod',
            get_string('grademethod', 'quizp'), get_string('configgrademethod', 'quizp'),
            array('value' => QUIZP_GRADEHIGHEST, 'adv' => false), null));

    // Maximum grade.
    $quizpsettings->add(new admin_setting_configtext('quizp/maximumgrade',
            get_string('maximumgrade'), get_string('configmaximumgrade', 'quizp'), 10, PARAM_INT));

    // Questions per page.
    $perpage = array();
    $perpage[0] = get_string('never');
    $perpage[1] = get_string('aftereachquestion', 'quizp');
    for ($i = 2; $i <= QUIZP_MAX_QPP_OPTION; ++$i) {
        $perpage[$i] = get_string('afternquestions', 'quizp', $i);
    }
    $quizpsettings->add(new admin_setting_configselect_with_advanced('quizp/questionsperpage',
            get_string('newpageevery', 'quizp'), get_string('confignewpageevery', 'quizp'),
            array('value' => 1, 'adv' => false), $perpage));

    // Navigation method.
    $quizpsettings->add(new admin_setting_configselect_with_advanced('quizp/navmethod',
            get_string('navmethod', 'quizp'), get_string('confignavmethod', 'quizp'),
            array('value' => QUIZP_NAVMETHOD_FREE, 'adv' => true), quizp_get_navigation_options()));

    // Shuffle within questions.
    $quizpsettings->add(new admin_setting_configcheckbox_with_advanced('quizp/shuffleanswers',
            get_string('shufflewithin', 'quizp'), get_string('configshufflewithin', 'quizp'),
            array('value' => 1, 'adv' => false)));

    // Preferred behaviour.
    $quizpsettings->add(new admin_setting_question_behaviour('quizp/preferredbehaviour',
            get_string('howquestionsbehave', 'question'), get_string('howquestionsbehave_desc', 'quizp'),
            'deferredfeedback'));

    // Can redo completed questions.
    $quizpsettings->add(new admin_setting_configselect_with_advanced('quizp/canredoquestions',
            get_string('canredoquestions', 'quizp'), get_string('canredoquestions_desc', 'quizp'),
            array('value' => 0, 'adv' => true),
            array(0 => get_string('no'), 1 => get_string('canredoquestionsyes', 'quizp'))));

    // Each attempt builds on last.
    $quizpsettings->add(new admin_setting_configcheckbox_with_advanced('quizp/attemptonlast',
            get_string('eachattemptbuildsonthelast', 'quizp'),
            get_string('configeachattemptbuildsonthelast', 'quizp'),
            array('value' => 0, 'adv' => true)));

    // Review options.
    $quizpsettings->add(new admin_setting_heading('reviewheading',
            get_string('reviewoptionsheading', 'quizp'), ''));
    foreach (mod_quizp_admin_review_setting::fields() as $field => $name) {
        $default = mod_quizp_admin_review_setting::all_on();
        $forceduring = null;
        if ($field == 'attempt') {
            $forceduring = true;
        } else if ($field == 'overallfeedback') {
            $default = $default ^ mod_quizp_admin_review_setting::DURING;
            $forceduring = false;
        }
        $quizpsettings->add(new mod_quizp_admin_review_setting('quizp/review' . $field,
                $name, '', $default, $forceduring));
    }

    // Show the user's picture.
    $quizpsettings->add(new mod_quizp_admin_setting_user_image('quizp/showuserpicture',
            get_string('showuserpicture', 'quizp'), get_string('configshowuserpicture', 'quizp'),
            array('value' => 0, 'adv' => false), null));

    // Decimal places for overall grades.
    $options = array();
    for ($i = 0; $i <= QUIZP_MAX_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $quizpsettings->add(new admin_setting_configselect_with_advanced('quizp/decimalpoints',
            get_string('decimalplaces', 'quizp'), get_string('configdecimalplaces', 'quizp'),
            array('value' => 2, 'adv' => false), $options));

    // Decimal places for question grades.
    $options = array(-1 => get_string('sameasoverall', 'quizp'));
    for ($i = 0; $i <= QUIZP_MAX_Q_DECIMAL_OPTION; $i++) {
        $options[$i] = $i;
    }
    $quizpsettings->add(new admin_setting_configselect_with_advanced('quizp/questiondecimalpoints',
            get_string('decimalplacesquestion', 'quizp'),
            get_string('configdecimalplacesquestion', 'quizp'),
            array('value' => -1, 'adv' => true), $options));

    // Show blocks during quizp attempts.
    $quizpsettings->add(new admin_setting_configcheckbox_with_advanced('quizp/showblocks',
            get_string('showblocks', 'quizp'), get_string('configshowblocks', 'quizp'),
            array('value' => 0, 'adv' => true)));

    // Password.
    $quizpsettings->add(new admin_setting_configtext_with_advanced('quizp/password',
            get_string('requirepassword', 'quizp'), get_string('configrequirepassword', 'quizp'),
            array('value' => '', 'adv' => false), PARAM_TEXT));

    // IP restrictions.
    $quizpsettings->add(new admin_setting_configtext_with_advanced('quizp/subnet',
            get_string('requiresubnet', 'quizp'), get_string('configrequiresubnet', 'quizp'),
            array('value' => '', 'adv' => true), PARAM_TEXT));

    // Enforced delay between attempts.
    $quizpsettings->add(new admin_setting_configduration_with_advanced('quizp/delay1',
            get_string('delay1st2nd', 'quizp'), get_string('configdelay1st2nd', 'quizp'),
            array('value' => 0, 'adv' => true), 60));
    $quizpsettings->add(new admin_setting_configduration_with_advanced('quizp/delay2',
            get_string('delaylater', 'quizp'), get_string('configdelaylater', 'quizp'),
            array('value' => 0, 'adv' => true), 60));

    // Browser security.
    $quizpsettings->add(new mod_quizp_admin_setting_browsersecurity('quizp/browsersecurity',
            get_string('showinsecurepopup', 'quizp'), get_string('configpopup', 'quizp'),
            array('value' => '-', 'adv' => true), null));

    $quizpsettings->add(new admin_setting_configtext('quizp/initialnumfeedbacks',
            get_string('initialnumfeedbacks', 'quizp'), get_string('initialnumfeedbacks_desc', 'quizp'),
            2, PARAM_INT, 5));

    // Allow user to specify if setting outcomes is an advanced setting.
    if (!empty($CFG->enableoutcomes)) {
        $quizpsettings->add(new admin_setting_configcheckbox('quizp/outcomes_adv',
            get_string('outcomesadvanced', 'quizp'), get_string('configoutcomesadvanced', 'quizp'),
            '0'));
    }

    // Autosave frequency.
    $quizpsettings->add(new admin_setting_configduration('quizp/autosaveperiod',
            get_string('autosaveperiod', 'quizp'), get_string('autosaveperiod_desc', 'quizp'), 60, 1));
}

// Now, depending on whether any reports have their own settings page, add
// the quizp setting page to the appropriate place in the tree.
if (empty($reportsbyname) && empty($rulesbyname)) {
    $ADMIN->add('modsettings', $quizpsettings);
} else {
    $ADMIN->add('modsettings', new admin_category('modsettingsquizpcat',
            get_string('modulename', 'quizp'), $module->is_enabled() === false));
    $ADMIN->add('modsettingsquizpcat', $quizpsettings);

    // Add settings pages for the quizp report subplugins.
    foreach ($reportsbyname as $strreportname => $report) {
        $reportname = $report;

        $settings = new admin_settingpage('modsettingsquizpcat'.$reportname,
                $strreportname, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/quizp/report/$reportname/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsquizpcat', $settings);
        }
    }

    // Add settings pages for the quizp access rule subplugins.
    foreach ($rulesbyname as $strrulename => $rule) {
        $settings = new admin_settingpage('modsettingsquizpcat' . $rule,
                $strrulename, 'moodle/site:config', $module->is_enabled() === false);
        if ($ADMIN->fulltree) {
            include($CFG->dirroot . "/mod/quizp/accessrule/$rule/settings.php");
        }
        if (!empty($settings)) {
            $ADMIN->add('modsettingsquizpcat', $settings);
        }
    }
}

$settings = null; // We do not want standard settings link.
