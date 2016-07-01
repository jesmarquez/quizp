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
 * Definition of log events for the quizp module.
 *
 * @package    mod_quizp
 * @category   log
 * @copyright  2010 Petr Skoda (http://skodak.org)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$logs = array(
    array('module'=>'quizp', 'action'=>'add', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'update', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'view', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'report', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'attempt', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'submit', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'review', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'editquestions', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'preview', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'start attempt', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'close attempt', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'continue attempt', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'edit override', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'delete override', 'mtable'=>'quizp', 'field'=>'name'),
    array('module'=>'quizp', 'action'=>'view summary', 'mtable'=>'quizp', 'field'=>'name'),
);