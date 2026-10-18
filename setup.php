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
 * First setup assistant: the ready-made reports, who sees them, scheduled emails and the AI assistant, step by step.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_reportium\local\report_roles;
use local_reportium\local\report_templates;

admin_externalpage_setup('local_reportium_setup');

$steps = ['welcome', 'reports', 'access', 'email', 'ai', 'done'];
$step = min(max(0, optional_param('step', 0, PARAM_INT)), count($steps) - 1);
$baseurl = new moodle_url('/local/reportium/setup.php');
$systemcontext = context_system::instance();

// The ready-made reports of the site (one per template).
$templatereports = $DB->get_records_select('local_reportium', "template IS NOT NULL AND template <> '' AND courseid = ?",
    [SITEID], 'name');

// Save the step and go to the next (or back).
if (data_submitted() && confirm_sesskey()) {
    $name = $steps[$step];
    if ($name === 'reports') {
        if (optional_param('installmissing', 0, PARAM_BOOL)) {
            $installed = report_templates::install_missing();
            redirect(new moodle_url($baseurl, ['step' => $step]),
                get_string('setup_installed', 'local_reportium', count($installed)), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }
        $visible = array_map('intval', optional_param_array('visible', [], PARAM_INT));
        foreach ($templatereports as $report) {
            $show = in_array((int) $report->id, $visible, true) ? 1 : 0;
            if ((int) $report->visible !== $show) {
                $DB->set_field('local_reportium', 'visible', $show, ['id' => $report->id]);
            }
        }
    } else if ($name === 'access') {
        $options = report_roles::options($systemcontext);
        $roles = array_values(array_intersect(array_map('intval', optional_param_array('roles', [], PARAM_INT)),
            array_map('intval', array_keys($options))));
        // Unchanged: each report keeps the roles it has (some may have been shared already).
        $before = array_map('intval', explode(',', optional_param('rolesbefore', '', PARAM_SEQUENCE)));
        $same = !array_diff($roles, $before) && !array_diff(array_filter($before), $roles);
        foreach ($same ? [] : $templatereports as $report) {
            report_roles::set((int) $report->id, $roles);
        }
    }
    $next = optional_param('back', 0, PARAM_BOOL) ? $step - 1 : $step + 1;
    if ($steps[min($next, count($steps) - 1)] === 'done') {
        set_config('setupdone', 1, 'local_reportium');
    }
    redirect(new moodle_url($baseurl, ['step' => max(0, min($next, count($steps) - 1))]));
}

$PAGE->set_url(new moodle_url($baseurl, ['step' => $step]));
$PAGE->set_title(get_string('setup_title', 'local_reportium'));
$PAGE->set_heading(get_string('setup_title', 'local_reportium'));

// A line of a check list: green tick, amber warning or blue information.
// Arguments: $kind (ok | warning | info) and $text (escaped HTML).
$check = static function(string $kind, string $text): string {
    $icons = ['ok' => 'fa-circle-check text-success', 'warning' => 'fa-triangle-exclamation text-warning',
        'info' => 'fa-circle-info text-info'];
    return html_writer::tag('li', html_writer::tag('i', '', ['class' => 'fa ' . $icons[$kind], 'aria-hidden' => 'true']) .
        html_writer::span($text), ['class' => 'reportium-setup-check is-' . $kind, 'data-check' => $kind]);
};

// Content of the step.
$name = $steps[$step];
$body = '';
if ($name === 'welcome') {
    $body .= html_writer::tag('p', get_string('setup_welcome_intro', 'local_reportium'), ['class' => 'lead']);
    $features = '';
    foreach (['drill', 'charts', 'kpis', 'schedules', 'ai'] as $feature) {
        $features .= html_writer::tag('li', get_string('setup_feature_' . $feature, 'local_reportium'));
    }
    $body .= html_writer::tag('ul', $features, ['class' => 'reportium-setup-features']);
    $body .= html_writer::tag('p', get_string('setup_welcome_time', 'local_reportium'), ['class' => 'text-muted']);
} else if ($name === 'reports') {
    $body .= html_writer::tag('p', get_string('setup_reports_intro', 'local_reportium'));
    $list = '';
    foreach ($templatereports as $report) {
        $id = 'reportium-setup-report-' . $report->id;
        $summary = trim(strip_tags(format_text($report->summary, $report->summaryformat, ['context' => $systemcontext])));
        $list .= html_writer::div(
            html_writer::empty_tag('input', ['type' => 'checkbox', 'class' => 'form-check-input', 'name' => 'visible[]',
                'value' => $report->id, 'id' => $id] + (!empty($report->visible) ? ['checked' => 'checked'] : [])) .
            html_writer::label(html_writer::tag('strong', format_string($report->name)) .
                ($summary !== '' ? html_writer::div(s(shorten_text($summary, 160)), 'small text-muted') : ''),
                $id, false, ['class' => 'form-check-label']),
            'form-check reportium-setup-report');
    }
    $body .= $list ?: $OUTPUT->notification(get_string('setup_reports_none', 'local_reportium'), 'info', false);
    $missing = array_diff(array_keys(report_templates::definitions()),
        array_filter(array_map(static fn($r) => (string) $r->template, $templatereports)));
    if ($missing) {
        $body .= html_writer::div(html_writer::tag('button', get_string('setup_installmissing', 'local_reportium', count($missing)),
            ['type' => 'submit', 'name' => 'installmissing', 'value' => 1, 'class' => 'btn btn-outline-secondary btn-sm']),
            'mt-2');
    }
} else if ($name === 'access') {
    $body .= html_writer::tag('p', get_string('setup_access_intro', 'local_reportium'));
    $first = reset($templatereports);
    $current = $first ? report_roles::get((int) $first->id) : report_roles::default_roles();
    $body .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'rolesbefore',
        'value' => implode(',', array_map('intval', $current))]);
    foreach (report_roles::options($systemcontext) as $roleid => $rolename) {
        $id = 'reportium-setup-role-' . $roleid;
        $body .= html_writer::div(
            html_writer::empty_tag('input', ['type' => 'checkbox', 'class' => 'form-check-input', 'name' => 'roles[]',
                'value' => $roleid, 'id' => $id] + (in_array((int) $roleid, array_map('intval', $current), true)
                ? ['checked' => 'checked'] : [])) .
            html_writer::label(s($rolename), $id, false, ['class' => 'form-check-label']), 'form-check');
    }
    $body .= html_writer::tag('p', get_string('setup_access_more', 'local_reportium',
        (new moodle_url('/admin/roles/manage.php'))->out()), ['class' => 'small text-muted mt-3']);
} else if ($name === 'email') {
    $body .= html_writer::tag('p', get_string('setup_email_intro', 'local_reportium'));
    $checks = '';
    if (!empty($CFG->noemailever)) {
        $checks .= $check('warning', get_string('setup_email_off', 'local_reportium'));
    }
    $checks .= trim((string) ($CFG->smtphosts ?? '')) === ''
        ? $check('info', get_string('setup_email_phpmail', 'local_reportium'))
        : $check('ok', get_string('setup_email_smtp', 'local_reportium', s($CFG->smtphosts)));
    if (!empty($CFG->divertallemailsto)) {
        $checks .= $check('info', get_string('setup_email_divert', 'local_reportium', s($CFG->divertallemailsto)));
    }
    $checks .= $check('info', get_string('setup_email_noreply', 'local_reportium', s((string) ($CFG->noreplyaddress ?? ''))));
    $lastcron = (int) get_config('tool_task', 'lastcronstart');
    $checks .= $lastcron && time() - $lastcron < HOURSECS
        ? $check('ok', get_string('setup_email_cron', 'local_reportium', userdate($lastcron)))
        : $check('warning', get_string('setup_email_nocron', 'local_reportium'));
    $body .= html_writer::tag('ul', $checks, ['class' => 'reportium-setup-checks']);
    $body .= html_writer::div(html_writer::link(new moodle_url('/admin/testoutgoingmailconf.php'),
        get_string('setup_email_test', 'local_reportium'), ['class' => 'btn btn-outline-secondary btn-sm', 'target' => '_blank']),
        'mt-2');
} else if ($name === 'ai') {
    $body .= html_writer::tag('p', get_string('setup_ai_intro', 'local_reportium'));
    $body .= html_writer::tag('ul', $check('info', get_string('ai_pro_desc', 'local_reportium')),
        ['class' => 'reportium-setup-checks']);
    $body .= html_writer::tag('p', get_string('setup_ai_noai', 'local_reportium',
        (new moodle_url('/local/reportium/aiinstructions.php'))->out()), ['class' => 'small text-muted mt-3']);
} else {
    $body .= html_writer::tag('p', get_string('setup_done_intro', 'local_reportium'), ['class' => 'lead']);
    $body .= html_writer::div(
        html_writer::link(new moodle_url('/local/reportium/managereport.php', ['courseid' => SITEID]),
            get_string('setup_done_reports', 'local_reportium'), ['class' => 'btn btn-primary mr-2 me-2']) .
        html_writer::link(new moodle_url('/local/reportium/editreport.php', ['courseid' => SITEID]),
            get_string('setup_done_create', 'local_reportium'), ['class' => 'btn btn-outline-primary mr-2 me-2']) .
        html_writer::link(new moodle_url('/admin/category.php', ['category' => 'localplugins']),
            get_string('setup_done_settings', 'local_reportium'), ['class' => 'btn btn-link']),
        'reportium-setup-done');
}

// Steps at the top.
$stepper = '';
foreach ($steps as $index => $stepname) {
    $state = $index < $step ? 'is-done' : ($index === $step ? 'is-current' : '');
    $label = html_writer::span($index < $step ? html_writer::tag('i', '', ['class' => 'fa fa-check', 'aria-hidden' => 'true'])
        : (string) ($index + 1), 'reportium-setup-number') .
        html_writer::span(get_string('setup_step_' . $stepname, 'local_reportium'), 'reportium-setup-steplabel');
    // Steps already seen can be opened again.
    $content = $index < $step ? html_writer::link(new moodle_url($baseurl, ['step' => $index]), $label) : $label;
    $stepper .= html_writer::tag('li', $content, ['class' => trim('reportium-setup-step ' . $state)] +
        ($index === $step ? ['aria-current' => 'step'] : []));
}

// Buttons.
$buttons = '';
if ($step > 0 && $name !== 'done') {
    $buttons .= html_writer::tag('button', get_string('back'), ['type' => 'submit', 'name' => 'back', 'value' => 1,
        'class' => 'btn btn-secondary']);
}
if ($name !== 'done') {
    $nextlabel = $name === 'email'
        ? get_string('continue')
        : get_string($name === 'welcome' ? 'setup_start' : 'setup_next', 'local_reportium');
    $buttons .= html_writer::tag('button', $nextlabel,
        ['type' => 'submit', 'class' => 'btn btn-primary', 'data-action' => 'setup-next']);
}

echo $OUTPUT->header();
echo html_writer::start_div('reportium-setup', ['data-region' => 'setup', 'data-step' => $name]);
echo html_writer::tag('ol', $stepper, ['class' => 'reportium-setup-steps',
    'aria-label' => get_string('setup_title', 'local_reportium')]);
echo html_writer::start_tag('form', ['method' => 'post', 'action' => (new moodle_url($baseurl, ['step' => $step]))->out(false),
    'class' => 'reportium-setup-card']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo $OUTPUT->heading(get_string('setup_heading_' . $name, 'local_reportium'), 3);
echo $body;
echo $buttons !== '' ? html_writer::div($buttons, 'reportium-setup-buttons') : '';
echo html_writer::end_tag('form');
echo html_writer::end_div();
echo $OUTPUT->footer();
