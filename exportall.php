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
 * Download every report of the Reports manager in one file, to import them all in another site.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportium\local\report_roles;
use local_reportium\local\report_transfer;

require_once('../../config.php');
require_once($CFG->libdir . '/filelib.php');

$courseid = optional_param('courseid', SITEID, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
if ((int) $course->id === (int) SITEID) {
    require_login();
    $context = context_system::instance();
} else {
    require_login($course);
    $context = context_course::instance($course->id);
}
require_sesskey();
require_capability('local/reportium:transfer', $context);
$canmanageall = has_capability('local/reportium:manage', $context);

$PAGE->set_url('/local/reportium/exportall.php', ['courseid' => $course->id]);
$PAGE->set_context($context);

// The reports of the Reports manager the user can edit: every report of the context for those who manage them (on the
// site: of every course), otherwise the user's own reports.
if ($canmanageall && (int) $course->id === (int) SITEID) {
    $reports = $DB->get_records('local_reportium', null, 'name ASC');
} else {
    $reports = $DB->get_records('local_reportium', ['courseid' => $course->id], 'name ASC');
}
$reports = array_filter($reports, fn($r) => report_roles::can_edit($r));

send_file(report_transfer::export_all($reports), report_transfer::filename_all(), 0, 0, true, true);
