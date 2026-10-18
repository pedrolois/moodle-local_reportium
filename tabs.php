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
 * Reportium navigation of a report (shown to users who can edit it): Report, Edit, Settings (the plugin, for
 * administrators) and All reports.
 * Expects $report, $course and $currenttab.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$navitems = [];
$navitems[] = [
    'url' => (new moodle_url('/local/reportium/viewreport.php', ['id' => $report->id, 'courseid' => $COURSE->id]))->out(false),
    'label' => get_string('nav_report', 'local_reportium'),
    'icon' => 'fa-bar-chart',
    'active' => $currenttab === 'viewreport',
];
$navitems[] = [
    'url' => (new moodle_url('/local/reportium/editreport.php', ['id' => $report->id, 'courseid' => $COURSE->id]))->out(false),
    'label' => get_string('nav_edit', 'local_reportium'),
    'icon' => 'fa-pencil',
    'active' => $currenttab === 'report',
];
if (has_any_capability(['local/reportium:schedule', 'local/reportium:manage'], $context)
        && has_capability('local/reportium:download', $context)) {
    // Scheduled email delivery of the report.
    $navitems[] = [
        'url' => (new moodle_url('/local/reportium/schedules.php', ['id' => $report->id]))->out(false),
        'label' => get_string('schedules', 'local_reportium'),
        'icon' => 'fa-envelope',
        'active' => $currenttab === 'schedules',
    ];
}
if (has_capability('moodle/site:config', context_system::instance())) {
    // The plugin settings, for administrators.
    $navitems[] = [
        'url' => (new moodle_url('/admin/settings.php', ['section' => 'local_reportium_settings']))->out(false),
        'label' => get_string('nav_pluginsettings', 'local_reportium'),
        'icon' => 'fa-cog',
        'active' => false,
    ];
}

echo $OUTPUT->render_from_template('local_reportium/navigation', [
    'items' => $navitems,
    'allreports' => [
        // The report id keeps this navigation visible on the Reports manager page.
        'url' => (new moodle_url('/local/reportium/managereport.php',
            ['courseid' => $course->id, 'id' => $report->id]))->out(false),
        'label' => get_string('nav_allreports', 'local_reportium'),
        'active' => $currenttab === 'managereports',
    ],
]);
