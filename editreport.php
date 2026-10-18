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
 * Create a report, edit its settings, and the actions of the Reports manager (show/hide, duplicate, delete).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/reportium/editreport_form.php');

use local_reportium\local\exporter;
use local_reportium\local\level_service;
use local_reportium\local\report_roles;

$id = optional_param('id', 0, PARAM_INT);
$courseid = optional_param('courseid', SITEID, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$show = optional_param('show', 0, PARAM_BOOL);
$hide = optional_param('hide', 0, PARAM_BOOL);
$duplicate = optional_param('duplicate', 0, PARAM_BOOL);

$report = $id ? $DB->get_record('local_reportium', ['id' => $id], '*', MUST_EXIST) : null;
if ($report) {
    $courseid = (int) $report->courseid;
}
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
if ((int) $course->id === (int) SITEID) {
    require_login();
    $context = context_system::instance();
} else {
    require_login($course);
    $context = context_course::instance($course->id);
}

if ($report) {
    // Settings, hide, show and delete: those who can edit the report. A copy is a new report of the user.
    if (!report_roles::can_edit($report) || ($duplicate && !has_capability('local/reportium:create', $context))) {
        throw new moodle_exception('badpermissions', 'local_reportium');
    }
} else {
    // A new report is asked for its SQL straight away.
    require_capability('local/reportium:create', $context);
    if (!report_roles::can_manage_sql($context)) {
        throw new moodle_exception('nosqlpermissions', 'local_reportium');
    }
}

$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_url('/local/reportium/editreport.php', $report ? ['id' => $report->id] : ['courseid' => $courseid]);
$title = $report ? format_string($report->name) : get_string('newreport', 'local_reportium');
$managerurl = new moodle_url('/local/reportium/managereport.php', ['courseid' => $courseid]);
$PAGE->navbar->add(get_string('managereports', 'local_reportium'), $managerurl);
$PAGE->navbar->add($title);

// Reports manager actions.
if ($report && ($show || $hide)) {
    require_sesskey();
    $DB->set_field('local_reportium', 'visible', $show ? 1 : 0, ['id' => $report->id]);
    redirect($managerurl);
}

if ($report && $duplicate) {
    require_sesskey();
    $copy = clone($report);
    unset($copy->id);
    $copy->name = get_string('copyasnoun') . ' ' . $copy->name;
    // A copy is the user's own report ("Reportium custom"), even when the original is a ready-made template.
    $copy->template = null;
    $copy->ownerid = $USER->id;
    $copy->id = $DB->insert_record('local_reportium', $copy);
    report_roles::set((int) $copy->id, report_roles::get((int) $report->id));
    report_roles::set_defaults((int) $copy->id);
    set_config('cachettlminutes_report_' . $copy->id,
        (int) get_config('local_reportium', 'cachettlminutes_report_' . $report->id), 'local_reportium');
    $now = time();
    foreach ($DB->get_records(level_service::TABLE, ['reportid' => $report->id]) as $level) {
        unset($level->id);
        $level->reportid = $copy->id;
        $level->timecreated = $now;
        $level->timemodified = $now;
        $level->usermodified = (int) $USER->id;
        $DB->insert_record(level_service::TABLE, $level);
    }
    redirect($managerurl);
}

if ($report && $delete) {
    require_sesskey();
    if (!$confirm) {
        $PAGE->set_title($title);
        $PAGE->set_heading($title);
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('confirmdeletereport', 'local_reportium'),
            new single_button(new moodle_url('/local/reportium/editreport.php',
                ['id' => $report->id, 'delete' => 1, 'confirm' => 1, 'sesskey' => sesskey()]), get_string('yes')),
            new single_button($managerurl, get_string('no'), 'get'));
        echo $OUTPUT->footer();
        exit;
    }
    $DB->delete_records(level_service::TABLE, ['reportid' => $report->id]);
    $DB->delete_records('local_reportium_role', ['reportid' => $report->id]);
    \local_reportium\local\sql_history::delete_report((int) $report->id);
    \local_reportium\local\scheduler::delete_report((int) $report->id);
    $DB->delete_records('local_reportium', ['id' => $report->id]);
    unset_config('cachettlminutes_report_' . $report->id, 'local_reportium');
    redirect($managerurl);
}

$form = new report_edit_form(null, ['report' => $report, 'courseid' => $courseid]);

if ($report) {
    $formdata = clone($report);
    // Every format: on when the report offers it, off otherwise (the defaults of new reports do not apply).
    $offered = array_keys(exporter::offered($report));
    $formdata->export_png = exporter::offers_png($report) ? 1 : 0;
    foreach (array_keys(exporter::available()) as $format) {
        $formdata->{'export_' . $format} = in_array($format, $offered, true) ? 1 : 0;
    }
    $formdata->cachettlminutes = (int) get_config('local_reportium', 'cachettlminutes_report_' . $report->id);
    // Chart types offered: empty = all.
    $formdata->views = !empty($report->views) ? explode(',', $report->views) : ['bar', 'pie', 'line', 'table'];
    $form->set_data($formdata);
}

if ($form->is_cancelled()) {
    redirect($report ? new moodle_url('/local/reportium/viewreport.php', ['id' => $report->id]) : $managerurl);
} else if ($data = $form->get_data()) {
    $record = (object) [
        'name' => $data->name,
        'summary' => $data->summary,
        'summaryformat' => $data->summaryformat,
        'global' => (int) $data->global,
        'displaytotalrecords' => (int) $data->displaytotalrecords,
        'displayprintbutton' => (int) $data->displayprintbutton,
        'kpicards' => (int) $data->kpicards,
        'kpirows' => (int) $data->kpirows,
        'totalsrow' => (int) $data->totalsrow,
        'daterange' => \local_reportium\local\date_range::valid((string) $data->daterange) && $data->daterange !== 'custom'
            ? ($data->daterange ?: null) : null,
        'export' => '',
    ];
    if (!empty($data->export_png)) {
        $record->export .= exporter::PNG . ',';
    }
    foreach (array_keys(exporter::available()) as $format) {
        if (!empty($data->{'export_' . $format})) {
            $record->export .= $format . ',';
        }
    }
    // Chart types offered, in the toolbar order; none or all chosen = all (stored as empty).
    $views = array_values(array_intersect(['bar', 'pie', 'line', 'table'], (array) ($data->views ?? [])));
    $record->views = $views && count($views) < 4 ? implode(',', $views) : null;
    $cachettlminutes = max(0, (int) $data->cachettlminutes);

    if ($report) {
        $record->id = $report->id;
        $DB->update_record('local_reportium', $record);
        set_config('cachettlminutes_report_' . $report->id, $cachettlminutes, 'local_reportium');
        redirect(new moodle_url('/local/reportium/viewreport.php', ['id' => $report->id, 'courseid' => $courseid]));
    }

    $record->courseid = $courseid;
    $record->ownerid = $USER->id;
    $record->visible = 1;
    $record->id = $DB->insert_record('local_reportium', $record);
    // New reports are visible to managers first; who else sees them is chosen in the Reports manager.
    report_roles::set_defaults((int) $record->id);
    set_config('cachettlminutes_report_' . $record->id, $cachettlminutes, 'local_reportium');
    // Straight to the report with the SQL Inspector open, asking for the SQL of level 1.
    redirect(new moodle_url('/local/reportium/viewreport.php', ['id' => $record->id, 'courseid' => $courseid, 'rsql' => 1]));
}

$PAGE->set_title($title);
$PAGE->set_heading($title);
echo $OUTPUT->header();
if ($report) {
    $currenttab = 'report';
    include($CFG->dirroot . '/local/reportium/tabs.php');
}
$form->display();
echo $OUTPUT->footer();
