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
 * A report: the interactive view of its levels (charts, table, drill-down), and its downloads.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');

use local_reportium\local\exporter;
use local_reportium\local\instructions;
use local_reportium\local\report_roles;

$id = required_param('id', PARAM_INT);
$download = optional_param('download', false, PARAM_BOOL);
$format = optional_param('format', '', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);
$embed = optional_param('embed', false, PARAM_BOOL);

$report = $DB->get_record('local_reportium', ['id' => $id], '*', MUST_EXIST);
// A global report can be viewed in any course; the others in their own course.
if ($courseid && $report->global) {
    $report->courseid = $courseid;
} else {
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
if (!report_roles::can_view($report, (int) $USER->id, $context)) {
    throw new moodle_exception('badpermissions', 'local_reportium');
}

$PAGE->set_context($context);
$PAGE->set_pagelayout($embed ? 'embedded' : 'incourse');
$PAGE->set_url('/local/reportium/viewreport.php', ['id' => $id]);

if ($download && $format && isset(exporter::offered($report)[$format])) {
    require_capability('local/reportium:download', $context);
    // PDF like the print: the page sends its charts (PNG) and KPI cards with a form post.
    if ($format === 'pdf' && optional_param('rcharts', null, PARAM_RAW) !== null) {
        require_sesskey();
        $steps = json_decode(optional_param('rpath', '[]', PARAM_RAW), true);
        $filters = json_decode(optional_param('rf', '{}', PARAM_RAW), true);
        $override = json_decode(optional_param('rmap', '{}', PARAM_RAW), true);
        $charts = json_decode(optional_param('rcharts', '[]', PARAM_RAW), true);
        $kpis = json_decode(optional_param('rkpis', '[]', PARAM_RAW), true);
        exporter::download_pdf($report, $context, (int) $USER->id, max(0, optional_param('rlevel', 0, PARAM_INT)),
            is_array($steps) ? array_values(array_filter($steps, 'is_array')) : [],
            optional_param('rq', '', PARAM_RAW_TRIMMED), is_array($filters) ? $filters : [],
            is_array($override) ? $override : [], optional_param('rsort', '', PARAM_RAW_TRIMMED),
            \local_reportium\external\levels::range(optional_param('rrange', '', PARAM_ALPHANUMEXT),
                optional_param('rfrom', 0, PARAM_INT), optional_param('rto', 0, PARAM_INT)),
            is_array($charts) ? $charts : [], is_array($kpis) ? $kpis : [],
            (array) json_decode(optional_param('rkpi', '[]', PARAM_RAW), true), (bool) optional_param('rtotals', 0, PARAM_BOOL));
    }
    // The file holds what is on screen: level, search, filters and column order (sent by the interactive view).
    $steps = json_decode(optional_param('rpath', '[]', PARAM_RAW), true);
    $filters = json_decode(optional_param('rf', '{}', PARAM_RAW), true);
    $override = json_decode(optional_param('rmap', '{}', PARAM_RAW), true);
    exporter::download($report, $context, (int) $USER->id, $format,
        max(0, optional_param('rlevel', 0, PARAM_INT)),
        is_array($steps) ? array_values(array_filter($steps, 'is_array')) : [],
        optional_param('rq', '', PARAM_RAW_TRIMMED), is_array($filters) ? $filters : [],
        is_array($override) ? $override : [], optional_param('rsort', '', PARAM_RAW_TRIMMED),
        // Period on screen, as the interactive view sends it.
        \local_reportium\external\levels::range(optional_param('rrange', '', PARAM_ALPHANUMEXT),
            optional_param('rfrom', 0, PARAM_INT), optional_param('rto', 0, PARAM_INT)),
        // KPI cards and totals row on screen (recalculated over the same rows).
        (array) json_decode(optional_param('rkpi', '[]', PARAM_RAW), true), (bool) optional_param('rtotals', 0, PARAM_BOOL));
}

$canmanage = report_roles::can_edit($report);
$candownload = report_roles::can_download($context);

// Breadcrumb: "Reportium" (a link to the Reports manager for those who manage reports).
$PAGE->navbar->add(get_string('pluginname', 'local_reportium'),
    $canmanage ? new moodle_url('/local/reportium/managereport.php', ['courseid' => $report->courseid]) : null);
$PAGE->set_title(format_string($report->name) . ' | ' . get_string('pluginname', 'local_reportium'));
$PAGE->set_heading(get_string('productheading', 'local_reportium'));

echo $OUTPUT->header();
if (!$embed && $canmanage) {
    $currenttab = 'viewreport';
    include($CFG->dirroot . '/local/reportium/tabs.php');
}

echo html_writer::tag('h3', format_string($report->name), ['class' => 'reportium-report-title',
    'data-region' => 'report-title']);
if (!empty($report->summary)) {
    echo html_writer::div(format_text($report->summary, $report->summaryformat), 'reportium-summary');
}

// An assistant in the SQL Inspector, when a plugin offers one (Reportium Pro).
$assistant = report_roles::can_write_sql($report, $context)
    ? \local_reportium\local\extensions::sql_assistant($report, $context, (int) $courseid) : null;

// The levels, and the data of the first level when the page opens on it (no drill path in the URL): the interactive
// view draws at once, without asking for them. The period is the one the view will ask for (URL, else the report's).
$levelsdata = \local_reportium\external\levels::levels_data($report, $context);
$firstlevel = null;
if ($levelsdata['levels'] && in_array(optional_param('rpath', '', PARAM_RAW), ['', '[]'], true)) {
    $preset = '';
    if ($levelsdata['usesdates']) {
        $preset = optional_param('rrange', (string) ($report->daterange ?? ''), PARAM_ALPHANUMEXT);
        $preset = $preset === 'all' ? '' : $preset;
    }
    $custom = $preset === 'custom';
    $args = ['daterange' => $preset, 'rangefrom' => $custom ? optional_param('rfrom', 0, PARAM_INT) : 0,
        'rangeto' => $custom ? optional_param('rto', 0, PARAM_INT) : 0];
    try {
        $firstlevel = [
            'args' => $args,
            'data' => \local_reportium\external\levels::level_data($report, $context, 0, [],
                \local_reportium\external\levels::range($args['daterange'], $args['rangefrom'], $args['rangeto'])),
        ];
    } catch (\moodle_exception $e) {
        // The view asks for it and shows the error in its place.
        $firstlevel = null;
    }
}

// The interactive view (levels.js) reads its configuration from the data-config attribute of its root element.
$rootid = 'reportium-app-' . (int) $report->id;
$appconfig = [
    'reportid' => (int) $report->id,
    'courseid' => (int) $courseid,
    'reportname' => format_string($report->name),
    'echartsurl' => (new moodle_url('/local/reportium/vendor/echarts/echarts.min.js'))->out(false),
    'canconfigure' => $canmanage,
    // State codes (instructions.json) with colour and translated label, for the "state" column format.
    'states' => instructions::states(),
    'formats' => instructions::format_names(),
    // Date formats of the user's language, with the full year (e.g. 06/10/2026 and 06/10/2026 14:30).
    'dateformats' => [
        'date' => str_replace('%y', '%Y', get_string('strftimedatefullshort', 'langconfig')),
        'datetime' => str_replace(['%y', ', '], ['%Y', ' '], get_string('strftimedatetimeshort', 'langconfig')),
    ],
    // The "Live data" / "Cached" badge (plugin settings).
    'showfreshness' => (bool) get_config('local_reportium', 'showfreshness'),
    // Progress bar colours at 0%, 50% and 100% (plugin settings).
    'progresscolors' => instructions::progress_colors(),
    // Defaults of the KPI cards and the totals row (levels may change them in their Chart settings).
    'kpidefaults' => ['cards' => !empty($report->kpicards), 'rows' => !empty($report->kpirows),
        'totalsrow' => !empty($report->totalsrow)],
    // Addresses of the links to Moodle ({id}: the id of the row; {course}: its course).
    'linkurls' => [
        'user' => (new moodle_url('/user/view.php'))->out(false) . '?id={id}&course={course}',
        'userprofile' => (new moodle_url('/user/profile.php'))->out(false) . '?id={id}',
        'course' => (new moodle_url('/course/view.php'))->out(false) . '?id={id}',
        'category' => (new moodle_url('/course/index.php'))->out(false) . '?categoryid={id}',
    ],
    // Periods of the data (for reports whose SQL uses %%STARTTIME%% / %%ENDTIME%%).
    'ranges' => array_map(static fn($value, $label) => ['value' => (string) $value, 'label' => $label],
        array_keys(\local_reportium\local\date_range::options()), \local_reportium\local\date_range::options()),
    // Chart types offered by this report (chosen when creating/editing it); empty = all.
    'views' => !empty($report->views) ? explode(',', $report->views) : [],
    // The levels (as local_reportium_get_levels returns them), so the first level loads without waiting for them.
    'levelsdata' => $levelsdata,
    // Column order dragged by the user, per level (a preference).
    'columnorder' => (object) (json_decode((string) get_user_preferences(
        \local_reportium\local\exporter::ORDER_PREFERENCE . $report->id, '{}'), true) ?: []),
    // Data of the first level (when the page opens on it), with the period it was read for.
    'firstlevel' => $firstlevel,
    // An assistant in the SQL Inspector offered by another plugin (Reportium Pro).
    'assistant' => $assistant,
    // Otherwise, under the SQL editor: an instructions file to paste into any AI chat outside Moodle.
    'aihelp' => !$assistant && has_capability('local/reportium:useai', $context) && report_roles::can_write_sql($report, $context)
        ? [
            'reportid' => (int) $report->id,
            'courseid' => (int) $courseid,
            'title' => get_string('ai_help_title', 'local_reportium'),
            'text' => get_string('ai_help_text', 'local_reportium'),
            // The assistant inside Moodle is a Reportium Pro feature (installed: off, or no AI provider).
            'admintext' => \local_reportium\local\extensions::has_sql_assistant()
                ? '' : get_string('ai_help_pro', 'local_reportium'),
            'copy' => get_string('ai_help_copy', 'local_reportium'),
            'download' => get_string('ai_help_download', 'local_reportium'),
            'copied' => get_string('ai_help_copied', 'local_reportium'),
            'usesql' => get_string('ai_help_paste', 'local_reportium'),
            // Example request for the next level (the file already holds the SQL of every level).
            'example' => \local_reportium\local\ai_instructions::example_request($report),
        ] : null,
];
echo html_writer::start_div('', ['id' => 'printablediv']);
echo $OUTPUT->render_from_template('local_reportium/levels', ['rootid' => $rootid, 'config' => json_encode($appconfig)]);
echo html_writer::end_div();

// Footer: rows on screen and execution time of the level (filled in by the interactive view), downloads and print.
if ($report->displaytotalrecords) {
    echo html_writer::div('', '', ['id' => 'totalrecords']);
}
echo html_writer::div('', 'd-print-none', ['id' => 'lastexecutiontime']);
// Print without the browser's own header/footer (date, page title, URL): they live in the page margins,
// so the printed page gets no margin and the content its own padding. Only on this page, not site-wide.
echo html_writer::tag('style', '@media print { @page { margin: 0; } body { padding: 12mm 14mm !important; } }');
// Download and print links are not printed themselves.
echo html_writer::start_div('d-print-none');
if ($candownload) {
    echo exporter::links($report, (int) $courseid, !empty($report->displayprintbutton));
}
echo html_writer::end_div();

$PAGE->requires->js_call_amd('local_reportium/levels', 'init', [$rootid]);
echo $OUTPUT->footer();
