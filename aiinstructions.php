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
 * Download the instructions file (AGENTS.md) for any AI chat outside Moodle, for any report: what to do and return,
 * the rules, placeholders, column keywords and states (the SQL Inspector of each report adds its levels).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url('/local/reportium/aiinstructions.php');
// The file is for those who write the SQL of reports.
require_capability('local/reportium:useai', $context);
if (!\local_reportium\local\report_roles::can_manage_sql($context)) {
    throw new moodle_exception('nosqlpermissions', 'local_reportium');
}

send_file(
    \local_reportium\local\ai_instructions::prompt_file(null),
    \local_reportium\local\ai_instructions::prompt_filename(null),
    0,
    0,
    true,
    true,
    'text/markdown'
);
