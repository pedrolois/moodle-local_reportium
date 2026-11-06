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
 * Backup of the reports of a course.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * The reports of a course go with the course backup: settings, levels (SQL and chart settings), the roles they are
 * shared with and their data freshness. The SQL history and scheduled emails (personal recipients) stay on the site.
 */
class backup_local_reportium_plugin extends backup_local_plugin {
    /**
     * Reports of the course, under the course element.
     *
     * @return backup_plugin_element
     */
    protected function define_course_plugin_structure() {
        global $DB;

        $plugin = $this->get_plugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());
        $reports = new backup_nested_element('reports');
        $report = new backup_nested_element('report', ['id'], [
            'ownerid', 'visible', 'name', 'summary', 'summaryformat', 'export', 'global', 'displaytotalrecords',
            'displayprintbutton', 'daterange', 'kpicards', 'kpirows', 'totalsrow', 'views', 'template',
            'cachettlminutes',
        ]);
        $levels = new backup_nested_element('levels');
        $level = new backup_nested_element('level', ['id'], [
            'drilllevel', 'label', 'sqlquery', 'config', 'timecreated', 'timemodified', 'usermodified',
        ]);
        $roles = new backup_nested_element('roles');
        $role = new backup_nested_element('role', ['id'], ['roleid', 'timecreated', 'usermodified']);

        $plugin->add_child($wrapper);
        $wrapper->add_child($reports);
        $reports->add_child($report);
        $report->add_child($levels);
        $levels->add_child($level);
        $report->add_child($roles);
        $roles->add_child($role);

        // The data freshness of each report is a plugin setting: read it with the config API.
        $courseid = (int) $this->task->get_courseid();
        $rows = [];
        if ($courseid !== (int) SITEID) {
            foreach ($DB->get_records('local_reportium', ['courseid' => $courseid], 'id') as $record) {
                $record->cachettlminutes = (int) get_config('local_reportium', 'cachettlminutes_report_' . $record->id);
                $rows[] = (array) $record;
            }
        }
        $report->set_source_array($rows);
        $level->set_source_table('local_reportium_level', ['reportid' => backup::VAR_PARENTID], 'drilllevel ASC');
        $role->set_source_table('local_reportium_role', ['reportid' => backup::VAR_PARENTID]);

        $report->annotate_ids('user', 'ownerid');
        $level->annotate_ids('user', 'usermodified');
        $role->annotate_ids('role', 'roleid');
        $role->annotate_ids('user', 'usermodified');

        return $plugin;
    }
}
