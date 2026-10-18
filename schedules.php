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
 * Scheduled email delivery of a report: the list of schedules, their form, "Send now", pause and delivery log.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportium\local\date_range;
use local_reportium\local\exporter;
use local_reportium\local\level_service;
use local_reportium\local\report_roles;
use local_reportium\local\scheduler;

require_once('../../config.php');
require_once($CFG->dirroot . '/local/reportium/schedule_form.php');

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$sid = optional_param('sid', 0, PARAM_INT);

$report = $DB->get_record('local_reportium', ['id' => $id], '*', MUST_EXIST);
$context = report_roles::report_context($report);
if ($context->contextlevel == CONTEXT_COURSE) {
    require_login($context->instanceid);
} else {
    require_login();
}
// Schedules send data: the user must be able to open and download the report, and schedule it.
if (!report_roles::can_view($report, (int) $USER->id, $context) || !report_roles::can_download($context)) {
    throw new moodle_exception('badpermissions', 'local_reportium');
}
$canmanageall = has_capability('local/reportium:manage', $context);
if (!$canmanageall) {
    require_capability('local/reportium:schedule', $context);
}

$baseurl = new moodle_url('/local/reportium/schedules.php', ['id' => $report->id]);
$PAGE->set_context($context);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('incourse');
$title = get_string('schedules', 'local_reportium');
$PAGE->set_title(format_string($report->name) . ' | ' . $title);
$PAGE->set_heading(get_string('productheading', 'local_reportium'));
$PAGE->navbar->add(get_string('pluginname', 'local_reportium'),
    new moodle_url('/local/reportium/managereport.php', ['courseid' => $report->courseid]));
$PAGE->navbar->add(format_string($report->name), new moodle_url('/local/reportium/viewreport.php', ['id' => $report->id]));
$PAGE->navbar->add($title);

$schedule = $sid ? $DB->get_record(scheduler::TABLE, ['id' => $sid, 'reportid' => $report->id], '*', MUST_EXIST) : null;
if ($schedule && !scheduler::can_manage($schedule, $report)) {
    throw new moodle_exception('badpermissions', 'local_reportium');
}

// Actions of the list.
if ($schedule && in_array($action, ['sendnow', 'pause', 'resume', 'delete'], true)) {
    require_sesskey();
    if ($action === 'sendnow') {
        $log = scheduler::send($schedule);
        if ($log->status === 'skipped') {
            redirect($baseurl, get_string('schedule_skippednow', 'local_reportium', s((string) $log->error)), null,
                \core\output\notification::NOTIFY_INFO);
        }
        $message = $log->status === 'sent'
            ? get_string('schedule_sentnow', 'local_reportium',
                (object) ['recipients' => $log->recipients, 'rows' => $log->rowcount])
            : get_string('schedule_failednow', 'local_reportium', s((string) $log->error));
        redirect($baseurl, $message, null, $log->status === 'sent' ? \core\output\notification::NOTIFY_SUCCESS
            : \core\output\notification::NOTIFY_ERROR);
    }
    if ($action === 'pause' || $action === 'resume') {
        $schedule->enabled = $action === 'resume' ? 1 : 0;
        scheduler::save($schedule);
        redirect($baseurl);
    }
    if (!optional_param('confirm', 0, PARAM_BOOL)) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(get_string('schedule_confirmdelete', 'local_reportium'),
            new moodle_url($baseurl, ['sid' => $schedule->id, 'action' => 'delete', 'confirm' => 1, 'sesskey' => sesskey()]),
            $baseurl);
        echo $OUTPUT->footer();
        exit;
    }
    scheduler::delete((int) $schedule->id);
    redirect($baseurl);
}

// The form: a new schedule (optionally of the view on screen) or an existing one.
if ($action === 'edit') {
    $viewstate = $schedule ? (string) $schedule->viewstate : optional_param('viewstate', '', PARAM_RAW);
    $view = json_decode($viewstate, true);
    $view = is_array($view) ? $view : [];
    $form = new schedule_form(null, ['report' => $report, 'levels' => scheduler::level_names((int) $report->id)]);
    $PAGE->requires->js_call_amd('local_reportium/schedule_form', 'init', [[
        'reportid' => (int) $report->id,
        'choose' => get_string('schedule_choose', 'local_reportium'),
        'levels' => scheduler::level_names((int) $report->id),
        'labels' => scheduler::level_labels((int) $report->id),
        'levelname' => get_string('ui_sqltitle', 'local_reportium', '{n}'),
        'nocolumns' => get_string('schedule_cond_nocolumns', 'local_reportium'),
    ]]);
    if ($form->is_cancelled()) {
        redirect($baseurl);
    }
    if ($data = $form->get_data()) {
        // The chart of the PDF is hidden (and not sent) for other formats: keep the saved one.
        $chart = (string) ($data->chart ?? ($view['chart'] ?? ''));
        $chart = in_array($chart, scheduler::PDF_CHARTS, true) ? $chart : '';
        $record = (object) [
            'reportid' => (int) $report->id,
            'recipients' => json_encode([
                'users' => array_values(array_map('intval', (array) ($data->users ?? []))),
                'cohorts' => array_values(array_map('intval', (array) ($data->cohorts ?? []))),
                'roles' => array_values(array_map('intval', (array) ($data->roles ?? []))),
                'emails' => schedule_form::split_emails((string) $data->emails),
            ]),
            'frequency' => in_array($data->frequency, scheduler::FREQUENCIES, true) ? $data->frequency : 'weekly',
            // Hidden fields (hideIf) are not submitted: keep the saved or default value.
            'weekday' => (int) ($data->weekday ?? ($schedule->weekday ?? 1)),
            'monthday' => (int) ($data->monthday ?? ($schedule->monthday ?? 1)),
            'hour' => (int) $data->hour,
            'format' => isset(exporter::available()[$data->format]) ? $data->format : 'csv',
            'viewstate' => json_encode(scheduler::view_from_form((int) $data->level, (string) $data->viewstate)
                + ['chart' => $chart]),
            'daterange' => date_range::valid((string) $data->daterange) && $data->daterange !== 'custom'
                ? (string) $data->daterange : '',
            'sendcondition' => scheduler::condition_from_form((string) ($data->condtype ?? ''), (string) ($data->condcolumn ?? ''),
                (string) ($data->condagg ?? ''), (string) ($data->condop ?? ''), (string) ($data->condvalue ?? ''),
                (string) ($data->condcolumnlabel ?? '')),
            'subject' => trim((string) $data->subject),
            'message' => trim((string) $data->message),
            'enabled' => (int) $data->enabled,
        ];
        if ($schedule) {
            $record->id = $schedule->id;
            $record->userid = $schedule->userid;
        } else {
            $record->userid = (int) $USER->id;
        }
        scheduler::save($record);
        redirect($baseurl, get_string('schedule_saved', 'local_reportium'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    $defaults = ['id' => $report->id, 'sid' => $schedule->id ?? 0, 'viewstate' => json_encode((object) $view), 'action' => 'edit',
        'level' => (int) ($view['level'] ?? 0)];
    if (!$schedule && array_key_exists('daterange', $view) && $view['daterange'] !== null) {
        // The period on screen when the schedule was started from the report.
        $defaults['daterange'] = (string) $view['daterange'];
    }
    if ($schedule) {
        $lists = scheduler::recipient_lists($schedule);
        $defaults += (array) $schedule;
        $defaults['users'] = $lists['users'];
        $defaults['cohorts'] = $lists['cohorts'];
        $defaults['roles'] = $lists['roles'];
        $defaults['emails'] = implode("\n", $lists['emails']);
        unset($defaults['recipients'], $defaults['sendcondition']);
        $condition = scheduler::condition($schedule);
        if ($condition) {
            $defaults['condtype'] = $condition['type'];
            if ($condition['type'] === 'value') {
                $defaults += ['condcolumnlabel' => (string) ($condition['label'] ?? ''), 'condcolumn' => $condition['column'],
                    'condagg' => $condition['agg'], 'condop' => $condition['op'],
                    'condvalue' => rtrim(rtrim(number_format($condition['value'], 4, '.', ''), '0'), '.')];
            }
        }
        $defaults['id'] = $report->id;
        $defaults['sid'] = $schedule->id;
        $defaults['level'] = (int) ($view['level'] ?? 0);
        $defaults['chart'] = (string) ($view['chart'] ?? '');
    }
    $form->set_data($defaults);
    echo $OUTPUT->header();
    if (report_roles::can_edit($report)) {
        $course = get_course($report->courseid);
        $currenttab = 'schedules';
        include($CFG->dirroot . '/local/reportium/tabs.php');
    }
    echo html_writer::tag('h3', format_string($report->name) . ' · ' . $title, ['class' => 'reportium-report-title']);
    $form->display();
    echo $OUTPUT->footer();
    exit;
}

// The list.
$schedules = $DB->get_records(scheduler::TABLE, ['reportid' => $report->id], 'timecreated ASC');
if (!$canmanageall) {
    $schedules = array_filter($schedules, static fn($s) => (int) $s->userid === (int) $USER->id);
}

echo $OUTPUT->header();
if (report_roles::can_edit($report)) {
    $course = get_course($report->courseid);
    $currenttab = 'schedules';
    include($CFG->dirroot . '/local/reportium/tabs.php');
}
echo html_writer::tag('h3', format_string($report->name) . ' · ' . $title, ['class' => 'reportium-report-title']);
echo html_writer::div(get_string('schedules_desc', 'local_reportium'), 'text-muted mb-3');
echo html_writer::div(
    html_writer::link(new moodle_url($baseurl, ['action' => 'edit']), get_string('schedule_add', 'local_reportium'),
        ['class' => 'btn btn-primary', 'data-action' => 'schedule-add']), 'mb-3');

if (!$schedules) {
    echo $OUTPUT->notification(get_string('schedules_none', 'local_reportium'), \core\output\notification::NOTIFY_INFO, false);
    echo $OUTPUT->footer();
    exit;
}

$formats = exporter::available();
$ranges = date_range::options(false);
$weekdays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
$table = new html_table();
$table->id = 'reportium-schedules';
$table->attributes['class'] = 'generaltable';
$table->head = [get_string('schedule_when', 'local_reportium'), get_string('schedule_what', 'local_reportium'),
    get_string('schedule_who', 'local_reportium'), get_string('schedule_nextrun', 'local_reportium'),
    get_string('schedule_lastrun', 'local_reportium'), get_string('actions')];
$dateformat = str_replace(['%y', ', '], ['%Y', ' '], get_string('strftimedatetimeshort', 'langconfig'));
foreach ($schedules as $item) {
    $a = (object) ['hour' => sprintf('%02d:00', $item->hour), 'day' => (int) $item->monthday,
        'weekday' => get_string($weekdays[(int) $item->weekday], 'calendar')];
    $when = get_string('schedule_desc_' . $item->frequency, 'local_reportium', $a);
    if (empty($item->enabled)) {
        $when .= ' ' . html_writer::span(get_string('schedule_paused', 'local_reportium'), 'badge bg-secondary badge-secondary');
    }
    $view = json_decode((string) $item->viewstate, true);
    $what = scheduler::view_summary(is_array($view) ? $view : []) . html_writer::empty_tag('br') .
        s(($ranges[(string) $item->daterange] ?? $ranges['']) . ' · ' . ($formats[$item->format] ?? $item->format));
    $condition = scheduler::condition($item);
    if ($condition) {
        $what .= html_writer::empty_tag('br') . html_writer::span(
            get_string('schedule_cond_short', 'local_reportium', s(scheduler::condition_summary($condition))),
            'badge bg-warning text-dark badge-warning');
    }
    $lists = scheduler::recipient_lists($item);
    $who = [];
    foreach (['users', 'cohorts', 'roles', 'emails'] as $kind) {
        if ($lists[$kind]) {
            $who[] = get_string('schedule_count_' . $kind, 'local_reportium', count($lists[$kind]));
        }
    }
    $last = $DB->get_records(scheduler::LOG, ['scheduleid' => $item->id], 'timesent DESC, id DESC', '*', 0, 5);
    $lastcell = '';
    foreach ($last as $log) {
        $badge = ['sent' => 'bg-success badge-success', 'skipped' => 'bg-secondary badge-secondary'][$log->status]
            ?? 'bg-danger badge-danger';
        $detail = $log->status === 'sent'
            ? get_string('schedule_logsent', 'local_reportium',
                (object) ['recipients' => $log->recipients, 'rows' => $log->rowcount])
            : s((string) $log->error);
        $lastcell .= html_writer::div(userdate($log->timesent, $dateformat) . ' ' .
            html_writer::span(get_string('schedule_status_' . $log->status, 'local_reportium'), 'badge ' . $badge) .
            ' ' . html_writer::span($detail, 'small text-muted'), 'reportium-sendlog');
    }
    $actions = '';
    if (scheduler::can_manage($item, $report)) {
        $link = static function(array $params, string $icon, string $label, bool $sesskey = true) use ($baseurl, $item): string {
            global $OUTPUT;
            $params += ['sid' => $item->id] + ($sesskey ? ['sesskey' => sesskey()] : []);
            return html_writer::link(new moodle_url($baseurl, $params), $OUTPUT->pix_icon($icon, $label),
                ['title' => $label, 'class' => 'mr-2 me-2', 'data-action' => 'schedule-' . $params['action']]);
        };
        $actions .= $link(['action' => 'edit'], 't/edit', get_string('edit'), false);
        $actions .= $link(['action' => 'sendnow'], 't/email', get_string('schedule_sendnow', 'local_reportium'));
        $actions .= !empty($item->enabled) ? $link(['action' => 'pause'], 't/stop', get_string('schedule_pause', 'local_reportium'))
            : $link(['action' => 'resume'], 't/play', get_string('schedule_resume', 'local_reportium'));
        $actions .= $link(['action' => 'delete'], 't/delete', get_string('delete'));
    }
    $table->data[] = [
        $when,
        $what,
        s(implode(', ', $who)),
        !empty($item->enabled) && $item->nextrun ? userdate($item->nextrun, $dateformat) : '-',
        $lastcell ?: '-',
        $actions,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->footer();
