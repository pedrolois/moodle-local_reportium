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
 * Report insights webservice definitions.
 *
 * @package    local_reportium
 * @copyright  Daniel Neis Araujo <danielneis@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'local_reportium_get_level_data' => [
        'classname' => 'local_reportium\external\levels',
        'methodname' => 'get_level_data',
        'description' => 'Data of one drill-down level (columns with types, rows, chart mapping), shared by every view.',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_reportium_get_levels' => [
        'classname' => 'local_reportium\external\levels',
        'methodname' => 'get_levels',
        'description' => 'Levels of a report (SQL only for users who can manage SQL reports).',
        'type' => 'read',
        'ajax' => true,
    ],
    'local_reportium_save_level' => [
        'classname' => 'local_reportium\external\levels',
        'methodname' => 'save_level',
        'description' => 'Validate and save the SQL of a drill-down level.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reportium:writesql',
    ],
    'local_reportium_delete_level' => [
        'classname' => 'local_reportium\external\levels',
        'methodname' => 'delete_level',
        'description' => 'Delete the deepest drill-down level of a report.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reportium:writesql',
    ],
    'local_reportium_save_level_config' => [
        'classname' => 'local_reportium\external\levels',
        'methodname' => 'save_level_config',
        'description' => 'Save the chart settings (X/Y mapping, view, sort…) of a drill-down level.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reportium:manage, local/reportium:create',
    ],
    'local_reportium_get_level_history' => [
        'classname' => 'local_reportium\external\levels',
        'methodname' => 'get_level_history',
        'description' => 'Versions of the SQL of a drill-down level (history).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reportium:writesql',
    ],
    'local_reportium_restore_level_version' => [
        'classname' => 'local_reportium\external\levels',
        'methodname' => 'restore_level_version',
        'description' => 'Restore an earlier version of the SQL of a drill-down level.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'local/reportium:writesql',
    ],
    'local_reportium_chat_prompt_file' => [
        'classname' => 'local_reportium\external\chat',
        'methodname' => 'prompt_file',
        'description' => 'Instructions file of a report to paste into an AI chat outside Moodle (SQL Inspector).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/reportium:useai, local/reportium:writesql',
    ],
];
