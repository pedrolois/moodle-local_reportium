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
 * Download a report file (settings, levels and roles) to import the report on another site.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

$id = required_param('id', PARAM_INT);

$report = $DB->get_record('local_reportium', ['id' => $id], '*', MUST_EXIST);
$context = \local_reportium\local\report_roles::report_context($report);
if ($context->contextlevel == CONTEXT_COURSE) {
    require_login($context->instanceid);
} else {
    require_login();
}
$PAGE->set_context($context);
$PAGE->set_url('/local/reportium/export.php', ['id' => $id]);
require_sesskey();
require_capability('local/reportium:transfer', \local_reportium\local\report_roles::report_context($report));
if (!\local_reportium\local\report_roles::can_edit($report)) {
    throw new moodle_exception('badpermissions', 'local_reportium');
}

send_file(
    \local_reportium\local\report_transfer::export($report),
    \local_reportium\local\report_transfer::filename($report),
    0,
    0,
    true,
    true,
    'application/json'
);
