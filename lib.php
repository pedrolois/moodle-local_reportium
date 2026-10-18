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
 * Callbacks of Reportium.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * In-place editing callback (core_update_inplace_editable): the "Roles" column of the Reports manager.
 *
 * @param string $itemtype
 * @param int $itemid
 * @param mixed $newvalue
 * @return \core\output\inplace_editable|null
 */
function local_reportium_inplace_editable($itemtype, $itemid, $newvalue) {
    if ($itemtype === 'report_roles') {
        return \local_reportium\output\report_roles_editable::update($itemid, $newvalue);
    }
    if ($itemtype === 'report_views') {
        return \local_reportium\output\report_views_editable::update($itemid, $newvalue);
    }
    return null;
}

/**
 * Add plugin links to course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 * @return void
 */
function local_reportium_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
): void {
    $canmanage = has_any_capability(['local/reportium:manage', 'local/reportium:create'], $context);

    // Course › Reports (report/view.php lists the children of the "coursereports" node).
    if ($canmanage || has_capability('local/reportium:view', $context)) {
        $reportsnode = $navigation->find('coursereports', navigation_node::TYPE_CONTAINER);
        if ($reportsnode) {
            $reportsnode->add(
                get_string('pluginname', 'local_reportium'),
                new moodle_url('/local/reportium/managereport.php', ['courseid' => $course->id]),
                navigation_node::TYPE_SETTING,
                null,
                'local_reportium_coursereports',
                new pix_icon('i/report', '')
            );
        }
    }

    if (!$canmanage) {
        return;
    }

    $url = new moodle_url('/local/reportium/managereport.php', ['courseid' => $course->id]);
    $navigation->add(
        get_string('pluginname', 'local_reportium'),
        $url,
        navigation_node::TYPE_CUSTOM,
        null,
        'local_reportium'
    );
}

/**
 * Preferences of the plugin: the column order of each report, set from the table by dragging its headers.
 *
 * @return array
 */
function local_reportium_user_preferences(): array {
    return [
        '/^local_reportium_columns_(\d)+$/' => [
            'type' => PARAM_RAW,
            'null' => NULL_NOT_ALLOWED,
            'default' => '{}',
            'isregex' => true,
            'permissioncallback' => [core_user::class, 'is_current_user'],
        ],
    ];
}
