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
 * Data generator for Reportium tests.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_reportium_generator extends component_generator_base {
    /**
     * Create a report record (SQL type, site level by default).
     *
     * @param array $record
     * @return stdClass
     */
    public function create_report(array $record = []): stdClass {
        global $CFG, $DB, $USER;
        $record = (object) array_merge([
            'courseid' => SITEID,
            'ownerid' => $USER->id ?: get_admin()->id,
            'visible' => 1,
            'name' => 'Test report',
            'summary' => '',
            'summaryformat' => FORMAT_HTML,
            'export' => 'csv,',
            'global' => 0,
            'displaytotalrecords' => 1,
            'displayprintbutton' => 1,
        ], $record);
        $record->id = $DB->insert_record('local_reportium', $record);
        return $record;
    }

    /**
     * Create (or replace) the SQL of one level.
     *
     * @param int $reportid
     * @param int $level
     * @param string $sql
     * @param array $config optional mapping overrides
     * @return stdClass
     */
    public function create_level(int $reportid, int $level, string $sql, array $config = []): stdClass {
        global $DB;
        $DB->delete_records('local_reportium_level', ['reportid' => $reportid, 'drilllevel' => $level]);
        $record = (object) [
            'reportid' => $reportid,
            'drilllevel' => $level,
            'label' => 'Level ' . ($level + 1),
            'sqlquery' => $sql,
            'config' => $config ? json_encode($config) : null,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ];
        $record->id = $DB->insert_record('local_reportium_level', $record);
        return $record;
    }

    /**
     * A 3-level demo report: categories → courses (by category name) → users (by course id).
     *
     * @return stdClass the report
     */
    public function create_demo_report(): stdClass {
        $report = $this->create_report(['name' => 'Demo drill report']);
        $this->create_level($report->id, 0, "SELECT cc.name, COUNT(DISTINCT c.id) AS courses
              FROM {course_categories} cc
         LEFT JOIN {course} c ON c.category = cc.id
          GROUP BY cc.id, cc.name");
        $this->create_level($report->id, 1, "SELECT c.id, c.fullname, COUNT(DISTINCT ue.userid) AS users
              FROM {course_categories} cc
              JOIN {course} c ON c.category = cc.id
         LEFT JOIN {enrol} e ON e.courseid = c.id
         LEFT JOIN {user_enrolments} ue ON ue.enrolid = e.id
             WHERE cc.name = %%PREVIOUSVALUE%%
          GROUP BY c.id, c.fullname");
        $this->create_level($report->id, 2, "SELECT u.id, u.firstname, u.lastname, u.timecreated
              FROM {user} u
              JOIN {user_enrolments} ue ON ue.userid = u.id
              JOIN {enrol} e ON e.id = ue.enrolid
              JOIN {course} c ON c.id = e.courseid
              JOIN {course_categories} cc ON cc.id = c.category
             WHERE e.courseid = %%PREVIOUSVALUE%% AND cc.name = %%LEVELVALUE:0%%
          ORDER BY u.lastname");
        return $report;
    }
}
