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
 * Restore of the reports of a course.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_reportium\local\report_data_helper;
use local_reportium\local\report_roles;

/**
 * Restores the reports of a course backup into the target course.
 *
 * A backup file can be edited, so its SQL is treated like an imported report file: the reports are restored only when
 * the user restoring may write SQL in the target course (local/reportium:writesql), and a report is left out when the
 * SQL of one of its levels does not pass the same validation as the SQL editor.
 */
class restore_local_reportium_plugin extends restore_local_plugin {
    /** @var int[] new ids of the restored reports => their data freshness in minutes */
    protected $restored = [];

    /** @var bool[] new ids of the restored reports with a level whose SQL was refused */
    protected $refused = [];

    /**
     * Paths of the reports under the course element.
     *
     * @return restore_path_element[]
     */
    protected function define_course_plugin_structure() {
        return [
            new restore_path_element($this->get_namefor('report'), $this->get_pathfor('/reports/report')),
            new restore_path_element($this->get_namefor('level'), $this->get_pathfor('/reports/report/levels/level')),
            new restore_path_element($this->get_namefor('role'), $this->get_pathfor('/reports/report/roles/role')),
        ];
    }

    /**
     * Restore a report.
     *
     * @param array $data
     * @return void
     */
    public function process_local_reportium_report($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $courseid = (int) $this->task->get_courseid();
        $context = context_course::instance($courseid);
        $userid = (int) $this->task->get_userid();
        if (!has_capability('local/reportium:writesql', $context, $userid)) {
            $this->step->log('local_reportium: reports not restored, the user may not write SQL', backup::LOG_WARNING);
            // Levels and roles of this report are skipped.
            $this->set_mapping($this->get_namefor('report'), $oldid, 0);
            return;
        }
        $ttl = max(0, (int) ($data->cachettlminutes ?? 0));
        unset($data->id, $data->cachettlminutes);
        $data->courseid = $courseid;
        $data->ownerid = $this->get_mappingid('user', $data->ownerid, $userid);
        $newid = (int) $DB->insert_record('local_reportium', $data);
        $this->restored[$newid] = $ttl;
        $this->set_mapping($this->get_namefor('report'), $oldid, $newid);
    }

    /**
     * Restore a level, after validating its SQL.
     *
     * @param array $data
     * @return void
     */
    public function process_local_reportium_level($data) {
        global $DB;
        $data = (object) $data;
        $reportid = (int) $this->get_new_parentid($this->get_namefor('report'));
        if (!$reportid || isset($this->refused[$reportid])) {
            return;
        }
        $drilllevel = max(0, (int) $data->drilllevel);
        $sql = trim((string) $data->sqlquery);
        $report = (object) ['courseid' => $this->task->get_courseid()];
        $error = report_data_helper::validate_level_sql($report, $drilllevel, $sql);
        if ($error !== null) {
            $this->refused[$reportid] = true;
            $this->step->log('local_reportium: report not restored, the SQL of level ' . ($drilllevel + 1) . ' is not allowed: ' .
                $error, backup::LOG_WARNING);
            return;
        }
        unset($data->id);
        $data->reportid = $reportid;
        $data->drilllevel = $drilllevel;
        $data->sqlquery = $sql;
        $data->usermodified = $this->get_mappingid('user', $data->usermodified, $this->task->get_userid());
        $DB->insert_record('local_reportium_level', $data);
    }

    /**
     * Restore a role the report is shared with (when the role exists on this site).
     *
     * @param array $data
     * @return void
     */
    public function process_local_reportium_role($data) {
        global $DB;
        $data = (object) $data;
        $reportid = (int) $this->get_new_parentid($this->get_namefor('report'));
        $roleid = (int) $this->get_mappingid('role', $data->roleid);
        if (!$reportid || !$roleid) {
            return;
        }
        $DB->insert_record('local_reportium_role', (object) [
            'reportid' => $reportid,
            'roleid' => $roleid,
            'timecreated' => time(),
            'usermodified' => $this->get_mappingid('user', $data->usermodified, $this->task->get_userid()),
        ]);
    }

    /**
     * Remove the reports with refused SQL, and give the others their data freshness and default roles.
     *
     * @return void
     */
    public function after_restore_course() {
        global $DB;
        foreach ($this->restored as $reportid => $ttl) {
            if (isset($this->refused[$reportid])) {
                $DB->delete_records('local_reportium_level', ['reportid' => $reportid]);
                $DB->delete_records('local_reportium_role', ['reportid' => $reportid]);
                $DB->delete_records('local_reportium', ['id' => $reportid]);
                continue;
            }
            report_roles::set_defaults($reportid);
            set_config('cachettlminutes_report_' . $reportid, $ttl, 'local_reportium');
        }
    }
}
