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
 * Reports manager: the ready-made reports ("Reportium default") and the users' reports ("Reportium custom"),
 * with their roles, chart types, actions and downloads; and the import of report files.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/reportium/import_form.php');

use local_reportium\local\exporter;
use local_reportium\local\report_roles;
use local_reportium\local\report_transfer;
use local_reportium\output\report_roles_editable;
use local_reportium\output\report_views_editable;

$courseid = optional_param('courseid', SITEID, PARAM_INT);
// Report the user came from ("All reports" in its navigation): its navigation stays visible here.
$fromreportid = optional_param('id', 0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
if ((int) $course->id === (int) SITEID) {
    require_login();
    $context = context_system::instance();
} else {
    require_login($course);
    $context = context_course::instance($course->id);
}
if (!report_roles::can_use_manager($context)) {
    throw new moodle_exception('badpermissions', 'local_reportium');
}
$canmanageall = has_capability('local/reportium:manage', $context);
$cantransfer = has_capability('local/reportium:transfer', $context);

$PAGE->set_url('/local/reportium/managereport.php', ['courseid' => $course->id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

$importform = $cantransfer ? new import_form(null, $course->id) : null;
if ($importform && $importform->get_data() && ($content = $importform->get_file_content('userfile'))) {
    $ids = report_transfer::import_many($content, (int) $course->id, (int) $USER->id);
    redirect($PAGE->url, get_string('reportsimported', 'local_reportium', count($ids)), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

// Report managers see every report (on the site page: of every course); other users the reports of this course they
// can open (their own ones, and those shared with one of their roles).
if (!$canmanageall) {
    $reports = array_filter($DB->get_records('local_reportium', ['courseid' => $course->id], 'name ASC'),
        fn($r) => report_roles::can_view($r, (int) $USER->id, $context));
} else if ((int) $course->id === (int) SITEID) {
    $reports = $DB->get_records('local_reportium', null, 'name ASC');
} else {
    $reports = $DB->get_records('local_reportium', ['courseid' => $course->id], 'name ASC');
}

$title = get_string('reports', 'local_reportium');
$PAGE->navbar->add(get_string('managereports', 'local_reportium'));
$PAGE->set_title($title);
$PAGE->set_heading($title);

echo $OUTPUT->header();

// Navigation bar: back to the report the user came from (if any), the plugin settings for administrators,
// and "All reports" active.
$navitems = [];
if ($fromreportid && ($report = $DB->get_record('local_reportium', ['id' => $fromreportid]))
        && report_roles::can_view($report, (int) $USER->id, $context)) {
    $navitems[] = ['url' => (new moodle_url('/local/reportium/viewreport.php', ['id' => $report->id]))->out(false),
        'label' => get_string('nav_backto', 'local_reportium', format_string($report->name)), 'icon' => 'fa-arrow-left',
        'active' => false];
}
if (has_capability('moodle/site:config', context_system::instance())) {
    $navitems[] = ['url' => (new moodle_url('/admin/settings.php', ['section' => 'local_reportium_settings']))->out(false),
        'label' => get_string('nav_pluginsettings', 'local_reportium'), 'icon' => 'fa-cog', 'active' => false];
}
if (is_siteadmin() && !get_config('local_reportium', 'setupdone')) {
    // The first setup assistant, until it is finished.
    echo $OUTPUT->notification(get_string('setup_notice', 'local_reportium',
        (new moodle_url('/local/reportium/setup.php'))->out()), \core\output\notification::NOTIFY_INFO, false);
}
echo $OUTPUT->render_from_template('local_reportium/navigation', ['items' => $navitems, 'allreports' => [
    'url' => $PAGE->url->out(false), 'label' => get_string('nav_allreports', 'local_reportium'), 'active' => true]]);

// Rows of each group: ready-made reports installed with the plugin, and reports made by users.
$rows = ['default' => [], 'custom' => []];
if ($reports) {
    $cancreate = has_capability('local/reportium:create', $context);
    $candownload = report_roles::can_download($context);
    $roles = report_roles::get_many(array_keys($reports));
    // Drill-down levels per report, shown as "Levels (n)" next to the name; 1 when none is saved yet.
    [$insql, $params] = $DB->get_in_or_equal(array_keys($reports));
    $levelcounts = $DB->get_records_sql_menu("SELECT reportid, COUNT(1)
                                                FROM {local_reportium_level}
                                               WHERE reportid $insql
                                            GROUP BY reportid", $params);
    $coursenames = [];
    $owners = $DB->get_records_list('user', 'id', array_unique(array_column($reports, 'ownerid')));

    foreach ($reports as $r) {
        $name = html_writer::link(new moodle_url('/local/reportium/viewreport.php', ['id' => $r->id]), format_string($r->name))
            . ' ' . html_writer::span(get_string('ui_levelscount', 'local_reportium', max(1, (int) ($levelcounts[$r->id] ?? 0))),
                // Bootstrap pill badge: BS4 classes (Moodle 4.x) + BS5 classes (Moodle 5.x).
                'badge badge-pill badge-primary rounded-pill bg-primary text-white ml-2 ms-2', ['data-region' => 'levels-count']);

        if ((int) $r->courseid === (int) SITEID) {
            $coursecell = html_writer::link(new moodle_url('/'), get_string('site'));
        } else {
            if (!array_key_exists($r->courseid, $coursenames)) {
                $coursenames[$r->courseid] = $DB->get_field('course', 'fullname', ['id' => $r->courseid]);
            }
            $coursecell = $coursenames[$r->courseid] === false ? get_string('deleted')
                : html_writer::link(new moodle_url('/local/reportium/managereport.php', ['courseid' => $r->courseid]),
                    format_string($coursenames[$r->courseid]));
        }

        $owner = $owners[$r->ownerid] ?? null;
        $ownercell = $owner ? html_writer::link(new moodle_url('/user/view.php', ['id' => $owner->id]), fullname($owner))
            : get_string('deleted');

        $actions = '';
        if (report_roles::can_edit($r)) {
            $action = function(array $params, string $icon, string $label): string {
                global $OUTPUT;
                $url = new moodle_url('/local/reportium/editreport.php', $params + ['sesskey' => sesskey()]);
                return html_writer::link($url, $OUTPUT->pix_icon($icon, $label), ['title' => $label, 'class' => 'mr-2 me-2']);
            };
            $actions .= html_writer::link(new moodle_url('/local/reportium/editreport.php', ['id' => $r->id]),
                $OUTPUT->pix_icon('t/edit', get_string('edit')), ['title' => get_string('edit'), 'class' => 'mr-2 me-2']);
            $actions .= $action(['id' => $r->id, 'delete' => 1], 't/delete', get_string('delete'));
            $actions .= !empty($r->visible) ? $action(['id' => $r->id, 'hide' => 1], 't/hide', get_string('hide'))
                : $action(['id' => $r->id, 'show' => 1], 't/show', get_string('show'));
            if ($cancreate) {
                $actions .= $action(['id' => $r->id, 'duplicate' => 1], 't/copy', get_string('duplicate'));
            }
        }
        if ($cantransfer && report_roles::can_edit($r)) {
            $actions .= html_writer::link(new moodle_url('/local/reportium/export.php', ['id' => $r->id, 'sesskey' => sesskey()]),
                $OUTPUT->pix_icon('t/backup', get_string('exportreport', 'local_reportium')),
                ['title' => get_string('exportreport', 'local_reportium')]);
        }

        // Download the first level in each format the report offers.
        $downloads = '';
        foreach ($candownload ? exporter::offered($r) : [] as $format => $formatname) {
            $downloads .= exporter::button(new moodle_url('/local/reportium/viewreport.php',
                ['id' => $r->id, 'download' => 1, 'format' => $format]), $format, $formatname, false);
        }

        $rows[empty($r->template) ? 'custom' : 'default'][] = [
            $name,
            $coursecell,
            // Roles allowed to view the report and chart types offered, editable in place.
            $OUTPUT->render_from_template('core/inplace_editable',
                (new report_roles_editable($r, $roles[$r->id] ?? []))->export_for_template($OUTPUT)),
            $OUTPUT->render_from_template('core/inplace_editable',
                (new report_views_editable($r))->export_for_template($OUTPUT)),
            $ownercell,
            $actions,
            $downloads,
        ];
    }
}

// Each group is a collapsible section, closed by default; only "Reportium custom" offers adding reports.
// Course column only when some report belongs to a course (on the site page managers see every course).
$showcourse = false;
foreach ($reports as $r) {
    if ((int) $r->courseid !== (int) SITEID) {
        $showcourse = true;
        break;
    }
}

// Search box: filters the reports of both groups as the user types.
$searchlabel = get_string('searchreports', 'local_reportium');
echo html_writer::start_div('reportium-manager-search mb-3', ['role' => 'search']);
echo html_writer::tag('i', '', ['class' => 'fa fa-search', 'aria-hidden' => 'true']);
echo html_writer::empty_tag('input', ['type' => 'search', 'class' => 'form-control', 'id' => 'reportium-manager-search',
    'data-region' => 'reports-search', 'placeholder' => $searchlabel, 'aria-label' => $searchlabel,
    'value' => optional_param('search', '', PARAM_TEXT), 'autocomplete' => 'off']);
echo html_writer::end_div();
echo html_writer::div(get_string('searchreports_none', 'local_reportium'), 'alert alert-info',
    ['data-region' => 'reports-search-empty', 'hidden' => 'hidden']);
$PAGE->requires->js_call_amd('local_reportium/main', 'search',
    [['input' => '#reportium-manager-search', 'empty' => '[data-region="reports-search-empty"]']]);

foreach (['default', 'custom'] as $group) {
    echo html_writer::start_tag('details', ['class' => 'reportium-group card mb-3', 'data-region' => 'reports-' . $group]);
    echo html_writer::tag('summary', get_string('reportgroup_' . $group, 'local_reportium') . ' ' .
        html_writer::span(count($rows[$group]), 'badge badge-pill badge-secondary rounded-pill bg-secondary text-white ml-1 ms-1'),
        ['class' => 'card-header h5 mb-0']);
    echo html_writer::start_div('card-body');
    if ($rows[$group]) {
        $table = new html_table();
        $table->id = 'reportslist-' . $group;
        $table->attributes['class'] = 'generaltable reportium-list-table';
        $table->head = [get_string('name'), get_string('course'), get_string('reportroles', 'local_reportium'),
            get_string('reportviews', 'local_reportium'), get_string('owner', 'local_reportium')];
        // Actions and downloads are not sortable.
        foreach ([get_string('edit'), get_string('download', 'local_reportium')] as $text) {
            $cell = new html_table_cell($text);
            $cell->attributes['class'] = 'nosort';
            $table->head[] = $cell;
        }
        $table->align = ['left', 'left', 'left', 'left', 'left', 'center', 'center'];
        $table->size = ['26%', '11%', '18%', '14%', '11%', '11%', '9%'];
        $table->data = $rows[$group];
        // The search box looks in the name, course and owner columns.
        $searchcols = [0, 1, 4];
        if (!$showcourse) {
            // Every report belongs to the site: no course column.
            foreach (['head', 'align', 'size'] as $property) {
                array_splice($table->$property, 1, 1);
            }
            foreach ($table->data as $i => $row) {
                array_splice($table->data[$i], 1, 1);
            }
            $table->size = ['30%', '20%', '16%', '13%', '12%', '9%'];
            $searchcols = [0, 3];
        }
        $table->attributes['data-searchcols'] = implode(',', $searchcols);
        echo html_writer::table($table);
        // Sortable column headers.
        $PAGE->requires->js_call_amd('local_reportium/main', 'js_order', [['selector' => '#' . $table->id]]);
    } else {
        echo html_writer::div(get_string('reportgroup_' . $group . '_empty', 'local_reportium'), 'text-muted mb-3');
    }
    if ($group === 'custom' && has_capability('local/reportium:create', $context) && report_roles::can_manage_sql($context)) {
        echo html_writer::div(html_writer::link(new moodle_url('/local/reportium/editreport.php', ['courseid' => $course->id]),
            get_string('addreport', 'local_reportium'), ['class' => 'btn btn-primary']), 'addbutton mt-2');
    }
    echo html_writer::end_div();
    echo html_writer::end_tag('details');
}

// Import and export: always open (a card, not a collapsible section), for those who may move report files.
if ($importform) {
    echo html_writer::start_div('card reportium-transfer mt-4', ['data-region' => 'reports-transfer']);
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', get_string('importexport', 'local_reportium'), ['class' => 'h5 card-title']);
    echo html_writer::div(get_string('importexport_desc', 'local_reportium'), 'text-muted mb-3');
    $importform->display();
    // The reports the user can edit (exportall.php exports the same ones).
    $exportable = array_filter($reports, fn($r) => report_roles::can_edit($r));
    if ($exportable) {
        echo html_writer::div(html_writer::link(new moodle_url('/local/reportium/exportall.php',
        ['courseid' => $course->id, 'sesskey' => sesskey()]),
        $OUTPUT->pix_icon('i/export', '') . get_string('exportallreports', 'local_reportium', count($exportable)),
        ['class' => 'btn btn-secondary', 'data-action' => 'export-all']), 'mt-2');
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
