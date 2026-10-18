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

namespace local_reportium\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_reportium\local\ai_instructions;
use local_reportium\local\level_service;
use moodle_exception;

/**
 * Web service of the report AI assistant (SQL Inspector).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat extends external_api {
    /**
     * Parameters of prompt_file.
     *
     * @return external_function_parameters
     */
    public static function prompt_file_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'courseid' => new external_value(PARAM_INT, 'Course to run a global report in (0 = its own)', VALUE_DEFAULT, 0),
            'level' => new external_value(
                PARAM_INT,
                'Drill level being edited in the SQL Inspector (-1 = none)',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    /**
     * Instructions file (Markdown) to paste into an AI chat outside Moodle, for users who write the SQL of reports.
     *
     * @param int $reportid
     * @param int $courseid
     * @param int $level
     * @return array
     */
    public static function prompt_file(int $reportid, int $courseid = 0, int $level = -1): array {
        global $USER;
        $params = self::validate_parameters(
            self::prompt_file_parameters(),
            ['reportid' => $reportid, 'courseid' => $courseid, 'level' => $level]
        );
        $report = level_service::get_report($params['reportid']);
        $context = level_service::get_context($report, $params['courseid']);
        self::validate_context($context);
        // The instructions file works with any AI chat outside Moodle: no Reportium Pro needed.
        require_capability('local/reportium:useai', $context);
        level_service::require_view($report, $context, (int) $USER->id);
        // The file holds the SQL of the report: only for those who may read and write it.
        if (!\local_reportium\local\report_roles::can_manage_sql($context)) {
            throw new moodle_exception('nosqlpermissions', 'local_reportium');
        }
        return [
            'filename' => ai_instructions::prompt_filename($report),
            'content' => ai_instructions::prompt_file($report, $params['level']),
        ];
    }

    /**
     * Returns of prompt_file.
     *
     * @return external_single_structure
     */
    public static function prompt_file_returns(): external_single_structure {
        return new external_single_structure([
            'filename' => new external_value(PARAM_FILE, 'File name'),
            'content' => new external_value(PARAM_RAW, 'File content (Markdown)'),
        ]);
    }
}
