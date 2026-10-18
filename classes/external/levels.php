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

use context;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_reportium\local\date_range;
use local_reportium\local\level_service;
use local_reportium\local\report_data_helper;
use local_reportium\local\report_roles;
use local_reportium\local\sql_advisor;
use local_reportium\local\sql_history;
use moodle_exception;
use stdClass;

/**
 * Web services for generic drill-down levels (used by the report page via core/ajax and by REST clients).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class levels extends external_api {
    /**
     * Parameters of get_level_data.
     *
     * @return external_function_parameters
     */
    public static function get_level_data_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'level' => new external_value(PARAM_INT, 'Level to load (0 = root)', VALUE_DEFAULT, 0),
            'path' => new external_multiple_structure(
                new external_value(PARAM_RAW, 'Value chosen at each previous level'),
                'Drill path: values chosen at levels 0..level-1',
                VALUE_DEFAULT,
                []
            ),
            'courseid' => new external_value(PARAM_INT, 'Course to run a global report in (0 = its own)', VALUE_DEFAULT, 0),
            'daterange' => new external_value(
                PARAM_ALPHANUMEXT,
                'Period (date_range preset, custom, or empty = all time)',
                VALUE_DEFAULT,
                ''
            ),
            'rangefrom' => new external_value(PARAM_INT, 'First day of a custom period', VALUE_DEFAULT, 0),
            'rangeto' => new external_value(PARAM_INT, 'Last day of a custom period', VALUE_DEFAULT, 0),
            'previous' => new external_value(PARAM_BOOL, 'Data of the period just before (comparison)', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * One level's data, identical for every view (bar, pie, line, table).
     *
     * @param int $reportid
     * @param int $level
     * @param array $path
     * @param int $courseid
     * @param string $daterange
     * @param int $rangefrom
     * @param int $rangeto
     * @param bool $previous
     * @return array
     */
    public static function get_level_data(
        int $reportid,
        int $level = 0,
        array $path = [],
        int $courseid = 0,
        string $daterange = '',
        int $rangefrom = 0,
        int $rangeto = 0,
        bool $previous = false
    ): array {
        global $USER;
        $params = self::validate_parameters(
            self::get_level_data_parameters(),
            ['reportid' => $reportid, 'level' => $level, 'path' => $path, 'courseid' => $courseid,
            'daterange' => $daterange,
            'rangefrom' => $rangefrom,
            'rangeto' => $rangeto,
            'previous' => $previous]
        );

        [$report, $context] = self::load_report_and_context($params['reportid'], $params['courseid']);
        level_service::require_view($report, $context, (int) $USER->id);
        $range = self::range($params['daterange'], $params['rangefrom'], $params['rangeto'], $params['previous']);
        return self::level_data($report, $context, $params['level'], $params['path'], $range);
    }

    /**
     * What get_level_data returns, for a report the user may view (also sent with the report page for its first
     * level, so the chart draws without a request).
     *
     * @param stdClass $report
     * @param context $context context the report runs in
     * @param int $level
     * @param string[] $path
     * @param int[] $range [start, end] of the period; empty = all time
     * @return array
     */
    public static function level_data(stdClass $report, context $context, int $level, array $path, array $range): array {
        global $USER;
        $payload = level_service::get_level_payload($report, $level, $path, $context, (int) $USER->id, $range);
        return [
            'level' => $payload['level'],
            'title' => $payload['title'],
            'columns' => $payload['columns'],
            'rows' => json_encode($payload['rows'], JSON_UNESCAPED_UNICODE),
            'mapping' => json_encode($payload['mapping'], JSON_UNESCAPED_UNICODE),
            'links' => json_encode((object) ($payload['links'] ?? []), JSON_UNESCAPED_UNICODE),
            'hasnext' => $payload['hasnext'],
            'executionms' => (int) ($payload['executionms'] ?? 0),
            'cached' => !empty($payload['cachemeta']['cached']),
            'generatedat' => (int) $payload['cachemeta']['generatedat'],
            'freshnessminutes' => (int) $payload['cachemeta']['freshnessminutes'],
            'rangelabel' => $range ? date_range::label($range[0], $range[1]) : '',
            // Slow query warning, for those who can change the SQL.
            'slowtips' => self::slow_tips($report, $context, $level, (int) ($payload['executionms'] ?? 0)),
        ];
    }

    /**
     * Tips of the slow query warning (empty when the level was fast or the user cannot change its SQL).
     *
     * @param stdClass $report
     * @param context $context
     * @param int $level
     * @param int $executionms
     * @return string[]
     */
    protected static function slow_tips(stdClass $report, context $context, int $level, int $executionms): array {
        if (!sql_advisor::is_slow($executionms) || !self::can_manage_sql($report, $context)) {
            return [];
        }
        $levels = array_column(level_service::get_levels((int) $report->id), 'sqlquery', 'level');
        return array_map(
            static fn(string $key): string => get_string('slowtip_' . $key, 'local_reportium'),
            sql_advisor::tips((string) ($levels[$level] ?? ''), $report)
        );
    }

    /**
     * Start and end of a period asked for by the interactive view (empty = all time).
     *
     * @param string $preset
     * @param int $from
     * @param int $to
     * @param bool $previous the period just before
     * @return int[]
     */
    public static function range(string $preset, int $from = 0, int $to = 0, bool $previous = false): array {
        if ($preset === '' || !date_range::valid($preset)) {
            return [];
        }
        $range = date_range::resolve($preset, time(), $from, $to);
        if ($previous) {
            return date_range::previous($preset, $range[0], $range[1]) ?? [];
        }
        return $range;
    }

    /**
     * Returns of get_level_data.
     *
     * @return external_single_structure
     */
    public static function get_level_data_returns(): external_single_structure {
        return new external_single_structure([
            'level' => new external_value(PARAM_INT, 'Level'),
            'title' => new external_value(PARAM_TEXT, 'Level title template ({parent} = parent label), may be empty'),
            'columns' => new external_multiple_structure(new external_single_structure([
                'key' => new external_value(PARAM_RAW, 'Column alias'),
                'label' => new external_value(PARAM_TEXT, 'Readable label'),
                'type' => new external_value(PARAM_ALPHA, 'id | number | date | text'),
                'format' => new external_value(PARAM_ALPHA, 'Automatic display format (instructions.json)', VALUE_DEFAULT, 'text'),
                'withtime' => new external_value(PARAM_BOOL, 'Date column with a time of day', VALUE_DEFAULT, false),
                'html' => new external_value(
                    PARAM_BOOL,
                    'The SQL returns HTML (cleaned, in the __html of each row)',
                    VALUE_DEFAULT,
                    false
                ),
                'calculated' => new external_value(
                    PARAM_BOOL,
                    'Calculated column (a formula of the level settings)',
                    VALUE_DEFAULT,
                    false
                ),
            ])),
            'rows' => new external_value(PARAM_RAW, 'JSON array of row objects keyed by column'),
            'mapping' => new external_value(PARAM_RAW, 'JSON: labelcolumn, valuecolumn, metrics[], defaultview, …'),
            'links' => new external_value(
                PARAM_RAW,
                'JSON: column => {kind: user|course|category, idkey, courseidkey}',
                VALUE_DEFAULT,
                '{}'
            ),
            'hasnext' => new external_value(PARAM_BOOL, 'Whether a deeper level exists'),
            'executionms' => new external_value(PARAM_INT, 'Time the level query took, in milliseconds'),
            'cached' => new external_value(PARAM_BOOL, 'Served from cache'),
            'generatedat' => new external_value(PARAM_INT, 'When the data was generated'),
            'freshnessminutes' => new external_value(PARAM_INT, 'Cache TTL in minutes (0 = live)'),
            'rangelabel' => new external_value(PARAM_TEXT, 'Dates of the period (empty = all time)'),
            'slowtips' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Tip'),
                'How to make a slow level faster (only for those who can change its SQL; empty = not slow)',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Parameters of get_levels.
     *
     * @return external_function_parameters
     */
    public static function get_levels_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'courseid' => new external_value(PARAM_INT, 'Course to run a global report in (0 = its own)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Levels of a report. The SQL is only included for users allowed to manage SQL reports.
     *
     * @param int $reportid
     * @param int $courseid
     * @return array
     */
    public static function get_levels(int $reportid, int $courseid = 0): array {
        global $USER;
        $params = self::validate_parameters(self::get_levels_parameters(), ['reportid' => $reportid, 'courseid' => $courseid]);
        [$report, $context] = self::load_report_and_context($params['reportid'], $params['courseid']);
        level_service::require_view($report, $context, (int) $USER->id);
        return self::levels_data($report, $context);
    }

    /**
     * What get_levels returns, for a report the user may view (also sent with the report page, so the first level
     * loads without waiting for it).
     *
     * @param stdClass $report
     * @param context $context context the report runs in
     * @return array
     */
    public static function levels_data(stdClass $report, context $context): array {
        $cansql = self::can_manage_sql($report, $context);
        $levels = [];
        $usesdates = false;
        foreach (level_service::get_levels((int) $report->id) as $level) {
            $levels[] = [
                'level' => $level['level'],
                'label' => $level['label'],
                'sqlquery' => $cansql ? $level['sqlquery'] : '',
                'config' => json_encode((object) $level['config']),
            ];
            $usesdates = $usesdates || date_range::used_in($level['sqlquery']);
        }
        return ['levels' => $levels, 'canmanagesql' => $cansql, 'canconfigure' => self::can_configure($report, $context),
            'usesdates' => $usesdates, 'daterange' => (string) ($report->daterange ?? '')];
    }

    /**
     * Returns of get_levels.
     *
     * @return external_single_structure
     */
    public static function get_levels_returns(): external_single_structure {
        return new external_single_structure([
            'levels' => new external_multiple_structure(new external_single_structure([
                'level' => new external_value(PARAM_INT, 'Level'),
                'label' => new external_value(PARAM_TEXT, 'Label'),
                'sqlquery' => new external_value(PARAM_RAW, 'SQL (empty unless the user can manage SQL reports)'),
                'config' => new external_value(PARAM_RAW, 'JSON mapping overrides'),
            ])),
            'canmanagesql' => new external_value(PARAM_BOOL, 'User may edit level SQL'),
            'canconfigure' => new external_value(PARAM_BOOL, 'User may save chart settings'),
            'usesdates' => new external_value(PARAM_BOOL, 'Some level uses %%STARTTIME%% / %%ENDTIME%%'),
            'daterange' => new external_value(PARAM_ALPHANUMEXT, 'Period shown first (empty = all time)'),
        ]);
    }

    /**
     * Parameters of save_level.
     *
     * @return external_function_parameters
     */
    public static function save_level_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'level' => new external_value(PARAM_INT, 'Level (0 = root)'),
            'sqlquery' => new external_value(PARAM_RAW, 'SQL of the level'),
            'label' => new external_value(PARAM_TEXT, 'Level label', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Validate and save the SQL of a level (create or update).
     *
     * @param int $reportid
     * @param int $level
     * @param string $sqlquery
     * @param string $label
     * @return array
     */
    public static function save_level(int $reportid, int $level, string $sqlquery, string $label = ''): array {
        global $DB, $USER;
        $params = self::validate_parameters(
            self::save_level_parameters(),
            ['reportid' => $reportid, 'level' => $level, 'sqlquery' => $sqlquery, 'label' => $label]
        );
        [$report, $context] = self::load_report_and_context($params['reportid'], 0);
        self::require_manage_sql($report, $context);
        if ($params['level'] < 0) {
            throw new \invalid_parameter_exception('level');
        }

        $error = report_data_helper::validate_level_sql($report, $params['level'], $params['sqlquery']);
        if ($error !== null) {
            return ['success' => false, 'error' => $error, 'id' => 0];
        }

        $now = time();
        $existing = $DB->get_record(level_service::TABLE, ['reportid' => $report->id, 'drilllevel' => $params['level']]);
        if ($existing) {
            // Saving the same SQL again adds no version.
            $changed = $existing->sqlquery !== $params['sqlquery'] || (string) $existing->label !== $params['label'];
            $existing->sqlquery = $params['sqlquery'];
            $existing->label = $params['label'];
            $existing->timemodified = $now;
            $existing->usermodified = (int) $USER->id;
            $DB->update_record(level_service::TABLE, $existing);
            $id = (int) $existing->id;
            if ($changed) {
                sql_history::record($report, $params['level'], $params['label'], $params['sqlquery'], 'saved', (int) $USER->id);
            }
        } else {
            $id = (int) $DB->insert_record(level_service::TABLE, (object) [
                'reportid' => $report->id,
                'drilllevel' => $params['level'],
                'label' => $params['label'],
                'sqlquery' => $params['sqlquery'],
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => (int) $USER->id,
            ]);
            sql_history::record($report, $params['level'], $params['label'], $params['sqlquery'], 'saved', (int) $USER->id);
        }
        return ['success' => true, 'error' => '', 'id' => $id];
    }

    /**
     * Returns of save_level.
     *
     * @return external_single_structure
     */
    public static function save_level_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Saved'),
            'error' => new external_value(PARAM_TEXT, 'Validation error (plain text)'),
            'id' => new external_value(PARAM_INT, 'Level record id'),
        ]);
    }

    /**
     * Parameters of delete_level.
     *
     * @return external_function_parameters
     */
    public static function delete_level_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'level' => new external_value(PARAM_INT, 'Level to delete (must be the deepest, never 0)'),
        ]);
    }

    /**
     * Delete the deepest level of a report.
     *
     * @param int $reportid
     * @param int $level
     * @return array
     */
    public static function delete_level(int $reportid, int $level): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::delete_level_parameters(), ['reportid' => $reportid, 'level' => $level]);
        [$report, $context] = self::load_report_and_context($params['reportid'], 0);
        self::require_manage_sql($report, $context);

        $deepest = (int) $DB->get_field_sql(
            'SELECT MAX(drilllevel) FROM {' . level_service::TABLE . '} WHERE reportid = ?',
            [$report->id]
        );
        if ($params['level'] <= 0 || $params['level'] !== $deepest) {
            throw new moodle_exception('cannotdeletelevel', 'local_reportium');
        }
        $deleted = $DB->get_record(level_service::TABLE, ['reportid' => $report->id, 'drilllevel' => $params['level']]);
        $DB->delete_records(level_service::TABLE, ['reportid' => $report->id, 'drilllevel' => $params['level']]);
        sql_history::record(
            $report,
            $params['level'],
            (string) $deleted->label,
            (string) $deleted->sqlquery,
            'deleted',
            (int) $USER->id
        );
        return ['success' => true];
    }

    /**
     * Returns of delete_level.
     *
     * @return external_single_structure
     */
    public static function delete_level_returns(): external_single_structure {
        return new external_single_structure(['success' => new external_value(PARAM_BOOL, 'Deleted')]);
    }

    /**
     * Parameters of save_level_config.
     *
     * @return external_function_parameters
     */
    public static function save_level_config_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'level' => new external_value(PARAM_INT, 'Level'),
            'config' => new external_value(
                PARAM_RAW,
                'JSON: labelcolumn, valuecolumn, metrics[], defaultview, title, sort, limit, stacked'
            ),
        ]);
    }

    /**
     * Save the chart settings (mapping) of a level. An empty JSON object resets it to automatic.
     *
     * @param int $reportid
     * @param int $level
     * @param string $config
     * @return array
     */
    public static function save_level_config(int $reportid, int $level, string $config): array {
        $params = self::validate_parameters(
            self::save_level_config_parameters(),
            ['reportid' => $reportid, 'level' => $level, 'config' => $config]
        );
        [$report, $context] = self::load_report_and_context($params['reportid'], 0);
        if (!self::can_configure($report, $context)) {
            throw new \required_capability_exception($context, 'local/reportium:manage', 'nopermissions', '');
        }
        $data = json_decode($params['config'], true);
        if (!is_array($data)) {
            throw new \invalid_parameter_exception('config must be a JSON object');
        }
        level_service::save_level_config((int) $report->id, $params['level'], $data);
        return ['success' => true];
    }

    /**
     * Returns of save_level_config.
     *
     * @return external_single_structure
     */
    public static function save_level_config_returns(): external_single_structure {
        return new external_single_structure(['success' => new external_value(PARAM_BOOL, 'Saved')]);
    }

    /**
     * Parameters of get_level_history.
     *
     * @return external_function_parameters
     */
    public static function get_level_history_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'level' => new external_value(PARAM_INT, 'Level (0 = level 1); a deleted level keeps its history'),
        ]);
    }

    /**
     * Versions of the SQL of a level, newest first.
     *
     * @param int $reportid
     * @param int $level
     * @return array
     */
    public static function get_level_history(int $reportid, int $level): array {
        $params = self::validate_parameters(self::get_level_history_parameters(), ['reportid' => $reportid, 'level' => $level]);
        [$report, $context] = self::load_report_and_context($params['reportid'], 0);
        self::require_manage_sql($report, $context);
        $versions = array_map(static function (array $version): array {
            unset($version['userid']);
            return $version;
        }, sql_history::versions((int) $report->id, $params['level']));
        return ['versions' => $versions];
    }

    /**
     * Returns of get_level_history.
     *
     * @return external_single_structure
     */
    public static function get_level_history_returns(): external_single_structure {
        return new external_single_structure([
            'versions' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Version id'),
                'level' => new external_value(PARAM_INT, 'Level'),
                'label' => new external_value(PARAM_TEXT, 'Label'),
                'sqlquery' => new external_value(PARAM_RAW, 'SQL of the version'),
                'action' => new external_value(PARAM_ALPHA, 'saved, deleted or restored'),
                'timecreated' => new external_value(PARAM_INT, 'When'),
                'userfullname' => new external_value(PARAM_TEXT, 'Who (empty when the user was deleted)'),
            ])),
        ]);
    }

    /**
     * Parameters of restore_level_version.
     *
     * @return external_function_parameters
     */
    public static function restore_level_version_parameters(): external_function_parameters {
        return new external_function_parameters([
            'reportid' => new external_value(PARAM_INT, 'Report id'),
            'versionid' => new external_value(PARAM_INT, 'Version to restore'),
        ]);
    }

    /**
     * Restore an earlier version of the SQL of a level (also a deleted level, as the new deepest level).
     *
     * @param int $reportid
     * @param int $versionid
     * @return array
     */
    public static function restore_level_version(int $reportid, int $versionid): array {
        global $USER;
        $params = self::validate_parameters(
            self::restore_level_version_parameters(),
            ['reportid' => $reportid, 'versionid' => $versionid]
        );
        [$report, $context] = self::load_report_and_context($params['reportid'], 0);
        self::require_manage_sql($report, $context);
        [$level, $error] = sql_history::restore($report, $params['versionid'], (int) $USER->id);
        return ['success' => $error === '', 'error' => $error, 'level' => $level];
    }

    /**
     * Returns of restore_level_version.
     *
     * @return external_single_structure
     */
    public static function restore_level_version_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Restored'),
            'error' => new external_value(PARAM_TEXT, 'Validation error (plain text)'),
            'level' => new external_value(PARAM_INT, 'Level restored'),
        ]);
    }

    /**
     * Load the report and validate (login + context) the context it runs in.
     *
     * @param int $reportid
     * @param int $courseid
     * @return array [stdClass report, context]
     */
    protected static function load_report_and_context(int $reportid, int $courseid): array {
        $report = level_service::get_report($reportid);
        $context = level_service::get_context($report, $courseid);
        self::validate_context($context);
        return [$report, $context];
    }

    /**
     * Whether the user may read and write raw SQL of this report.
     *
     * @param stdClass $report
     * @param context $context
     * @return bool
     */
    protected static function can_manage_sql(stdClass $report, context $context): bool {
        return report_roles::can_write_sql($report, $context);
    }

    /**
     * Throw unless the user may manage the SQL of this report.
     *
     * @param stdClass $report
     * @param context $context
     * @return void
     */
    protected static function require_manage_sql(stdClass $report, context $context): void {
        if (!self::can_manage_sql($report, $context)) {
            throw new \required_capability_exception($context, 'local/reportium:writesql', 'nopermissions', '');
        }
    }

    /**
     * Whether the user may save chart settings (they can edit the report).
     *
     * @param stdClass $report
     * @param context $context
     * @return bool
     */
    protected static function can_configure(stdClass $report, context $context): bool {
        return report_roles::can_edit($report);
    }
}
