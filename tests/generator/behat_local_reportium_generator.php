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
 * Behat data generator for local_reportium: reports and the SQL of their levels.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_reportium_generator extends behat_generator_base {
    /**
     * Entities that the step "the following "local_reportium > ..." exist" can create.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'reports' => [
                'singular' => 'report',
                'datagenerator' => 'report',
                'required' => ['name'],
                'switchids' => ['course' => 'courseid'],
            ],
            'levels' => [
                'singular' => 'level',
                'datagenerator' => 'level',
                'required' => ['report', 'level', 'sql'],
                'switchids' => ['report' => 'reportid'],
            ],
        ];
    }

    /**
     * The id of a report from its name.
     *
     * @param string $name
     * @return int
     */
    protected function get_report_id(string $name): int {
        global $DB;
        return (int) $DB->get_field('local_reportium', 'id', ['name' => $name], MUST_EXIST);
    }

    /**
     * Create a report (site level unless a course is given), viewed by the roles given as a comma separated list of
     * shortnames ("roles"), or by managers.
     *
     * @param array $data
     */
    protected function process_report(array $data): void {
        $roles = isset($data['roles']) ? array_filter(array_map('trim', explode(',', $data['roles']))) : [];
        unset($data['roles']);
        if (empty($data['courseid'])) {
            $data['courseid'] = SITEID;
        }
        $report = $this->componentdatagenerator->create_report($data);
        if ($roles) {
            $roleids = array_map(fn($shortname) => $this->get_role_id($shortname), $roles);
            \local_reportium\local\report_roles::set((int) $report->id, $roleids);
        } else {
            \local_reportium\local\report_roles::set_defaults((int) $report->id);
        }
    }

    /**
     * Create the SQL of a level; "level" counts from 1 (the first level of the report).
     *
     * @param array $data
     */
    protected function process_level(array $data): void {
        $this->componentdatagenerator->create_level(
            (int) $data['reportid'],
            (int) $data['level'] - 1,
            (string) $data['sql']
        );
    }
}
