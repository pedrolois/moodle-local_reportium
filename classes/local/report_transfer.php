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

namespace local_reportium\local;

use moodle_exception;
use stdClass;

/**
 * Move reports between sites: a report file (JSON) holds the report settings, its levels (SQL, label and chart
 * settings) and the roles that may view it (by short name, so they match on any site).
 *
 * Import also reads XML report files of SQL reports: their SQL becomes the first level.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_transfer {
    /** @var string Format name written in every report file. */
    const FORMAT = 'reportium-report';

    /** @var string Format name of a file with several reports. */
    const FORMAT_ALL = 'reportium-reports';

    /** @var int Version of the report file format. */
    const VERSION = 1;

    /** @var string[] Report settings carried by a report file. */
    const FIELDS = ['name', 'summary', 'summaryformat', 'global', 'displaytotalrecords', 'displayprintbutton', 'export',
        'views', 'daterange', 'kpicards', 'kpirows', 'totalsrow'];

    /**
     * File name of a report file.
     *
     * @param stdClass $report
     * @return string
     */
    public static function filename(stdClass $report): string {
        return clean_filename(format_string($report->name)) . '.reportium.json';
    }

    /**
     * Report file content (JSON).
     *
     * @param stdClass $report
     * @return string
     */
    public static function export(stdClass $report): string {
        return json_encode(self::export_data($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * One file with several reports (e.g. every report of the site).
     *
     * @param stdClass[] $reports
     * @return string JSON
     */
    public static function export_all(array $reports): string {
        $data = ['format' => self::FORMAT_ALL, 'version' => self::VERSION, 'reports' => []];
        foreach ($reports as $report) {
            $data['reports'][] = self::export_data($report);
        }
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * File name of a file with several reports.
     *
     * @return string
     */
    public static function filename_all(): string {
        return 'reportium-reports-' . date('Y-m-d') . '.json';
    }

    /**
     * The data of a report file: settings, levels and roles.
     *
     * @param stdClass $report
     * @return array
     */
    protected static function export_data(stdClass $report): array {
        global $DB;
        $data = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'report' => [],
            'cachettlminutes' => (int) get_config('local_reportium', 'cachettlminutes_report_' . $report->id),
            'levels' => [],
            'roles' => [],
        ];
        foreach (self::FIELDS as $field) {
            $data['report'][$field] = $report->$field ?? null;
        }
        foreach (level_service::get_levels((int) $report->id) as $level) {
            $data['levels'][] = [
                'level' => $level['level'],
                'label' => $level['label'],
                'sqlquery' => $level['sqlquery'],
                'config' => (object) $level['config'],
            ];
        }
        $roleids = report_roles::get((int) $report->id);
        if ($roleids) {
            [$insql, $params] = $DB->get_in_or_equal($roleids);
            $data['roles'] = array_values($DB->get_fieldset_select('role', 'shortname', "id $insql", $params));
        }
        return $data;
    }

    /**
     * Create a report from a report file (JSON, or XML of an SQL report).
     *
     * @param string $content file content
     * @param int $courseid course of the new report
     * @param int $userid owner of the new report
     * @return int id of the new report
     * @throws moodle_exception errorimporting when the file is not a report file
     */
    public static function import(string $content, int $courseid, int $userid): int {
        $content = trim($content);
        $data = json_decode($content, true);
        if (is_array($data)) {
            if (
                ($data['format'] ?? '') !== self::FORMAT || !is_array($data['report'] ?? null)
                    || trim((string) ($data['report']['name'] ?? '')) === ''
            ) {
                throw new moodle_exception('errorimporting', 'local_reportium');
            }
        } else {
            $data = self::read_xml($content);
        }
        return self::create($data, $courseid, $userid);
    }

    /**
     * Create every report of a file: a Reportium file (one report or several), XML with one or more SQL reports, or
     * the rows of a reports table exported as CSV or JSON from a database query (columns name, summary, type and
     * components, as stored by the database). Rows that are not SQL reports, or have no SQL, are skipped.
     *
     * @param string $content file content
     * @param int $courseid course of the new reports
     * @param int $userid owner of the new reports
     * @return int[] ids of the new reports
     * @throws moodle_exception errorimporting when the file holds no report
     */
    public static function import_many(string $content, int $courseid, int $userid): array {
        $content = trim(preg_replace('/^\xEF\xBB\xBF/', '', $content));
        $items = [];
        $json = json_decode($content, true);
        if (is_array($json)) {
            if (($json['format'] ?? '') === self::FORMAT_ALL && is_array($json['reports'] ?? null)) {
                $items = $json['reports'];
            } else if (($json['format'] ?? '') === self::FORMAT) {
                $items = [$json];
            } else {
                $items = array_filter(array_map([self::class, 'row_data'], self::json_rows($json)));
            }
        } else if (strpos(ltrim($content), '<') === 0) {
            $items = self::read_xml_reports($content);
        } else {
            $items = array_filter(array_map([self::class, 'row_data'], self::csv_rows($content)));
        }
        $ids = [];
        foreach ($items as $data) {
            if (is_array($data['report'] ?? null) && trim((string) ($data['report']['name'] ?? '')) !== '') {
                $ids[] = self::create($data, $courseid, $userid);
            }
        }
        if (!$ids) {
            throw new moodle_exception('errorimporting', 'local_reportium');
        }
        return $ids;
    }

    /**
     * Rows of a JSON export of a table: a plain list of objects, or the export of database tools that wrap the rows
     * (e.g. [{"type": "table", "data": [...]}]).
     *
     * @param array $json
     * @return array[] rows
     */
    protected static function json_rows(array $json): array {
        $rows = [];
        foreach ($json as $item) {
            if (is_array($item) && isset($item['data']) && is_array($item['data'])) {
                $rows = array_merge($rows, array_values(array_filter($item['data'], 'is_array')));
            } else if (is_array($item) && isset($item['name'])) {
                $rows[] = $item;
            }
        }
        return $rows;
    }

    /**
     * Rows of a CSV export of a table (comma, semicolon or tab separated, with a header line).
     *
     * @param string $csv
     * @return array[] rows keyed by column name
     */
    protected static function csv_rows(string $csv): array {
        $firstline = strtok($csv, "\n");
        $delimiter = ',';
        foreach ([';', "\t"] as $candidate) {
            if (substr_count($firstline, $candidate) > substr_count($firstline, $delimiter)) {
                $delimiter = $candidate;
            }
        }
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $header = fgetcsv($stream, 0, $delimiter);
        $rows = [];
        if (is_array($header)) {
            $header = array_map(fn($name) => strtolower(trim((string) $name)), $header);
            while (($line = fgetcsv($stream, 0, $delimiter)) !== false) {
                if (count($line) === count($header)) {
                    $rows[] = array_combine($header, $line);
                }
            }
        }
        fclose($stream);
        return $rows;
    }

    /**
     * Report file data from a row of a reports table: its settings, and the SQL of its components as level 1.
     *
     * @param array $row
     * @return array|null null when the row is not an SQL report with SQL
     */
    protected static function row_data(array $row): ?array {
        $row = array_change_key_case($row, CASE_LOWER);
        if (trim((string) ($row['name'] ?? '')) === '' || ($row['type'] ?? 'sql') !== 'sql') {
            return null;
        }
        $components = (string) ($row['components'] ?? '');
        // Stored serialized; report XML files carry it base64 encoded.
        $decoded = base64_decode($components, true);
        if ($decoded !== false && preg_match('/^a:\d+:\{/', $decoded)) {
            $components = $decoded;
        }
        $sql = trim(self::components_sql($components)) ?: trim((string) ($row['sqlquery'] ?? ''));
        if ($sql === '') {
            return null;
        }
        $report = array_intersect_key($row, array_flip(self::FIELDS));
        return ['report' => $report, 'levels' => [['level' => 0, 'label' => '', 'sqlquery' => $sql]]];
    }

    /**
     * Report file data of every report of an XML file (one <report>, or several inside one root element).
     *
     * @param string $xml
     * @return array[]
     */
    protected static function read_xml_reports(string $xml): array {
        global $CFG;
        require_once($CFG->libdir . '/xmlize.php');
        try {
            $parsed = xmlize($xml, 1, 'UTF-8');
        } catch (\Throwable $e) {
            throw new moodle_exception('errorimporting', 'local_reportium');
        }
        if (isset($parsed['report'])) {
            return [self::read_xml($xml)];
        }
        $items = [];
        $root = reset($parsed);
        foreach ((array) ($root['#']['report'] ?? []) as $report) {
            $values = [];
            foreach ((array) ($report['#'] ?? []) as $key => $value) {
                $values[$key] = trim((string) ($value[0]['#'] ?? ''));
            }
            if ($data = self::row_data($values)) {
                $items[] = $data;
            }
        }
        return $items;
    }

    /**
     * Create the report, its levels and roles from report file data.
     *
     * @param array $data
     * @param int $courseid
     * @param int $userid
     * @return int
     */
    protected static function create(array $data, int $courseid, int $userid): int {
        global $DB;
        $now = time();
        $views = array_intersect(['bar', 'pie', 'line', 'table'], explode(',', (string) ($data['report']['views'] ?? '')));
        $export = array_intersect(array_keys(exporter::available()), array_map(static function (string $format): string {
            return exporter::ALIASES[$format] ?? $format;
        }, explode(',', (string) ($data['report']['export'] ?? ''))));
        $report = (object) [
            'courseid' => $courseid,
            'ownerid' => $userid,
            'visible' => 1,
            // The date tells the copy apart from a report with the same name.
            'name' => \core_text::substr(trim((string) $data['report']['name']) . ' (' . userdate($now) . ')', 0, 128),
            'summary' => (string) ($data['report']['summary'] ?? ''),
            'summaryformat' => (int) ($data['report']['summaryformat'] ?? FORMAT_HTML),
            'global' => empty($data['report']['global']) ? 0 : 1,
            'displaytotalrecords' => empty($data['report']['displaytotalrecords']) ? 0 : 1,
            'displayprintbutton' => empty($data['report']['displayprintbutton']) ? 0 : 1,
            'export' => $export ? implode(',', $export) . ',' : '',
            'views' => $views ? implode(',', $views) : null,
        ];
        foreach (['kpicards' => 0, 'kpirows' => 0, 'totalsrow' => 0] as $field => $default) {
            $report->$field = isset($data['report'][$field]) ? (empty($data['report'][$field]) ? 0 : 1) : $default;
        }
        $daterange = (string) ($data['report']['daterange'] ?? '');
        $report->daterange = $daterange !== '' && $daterange !== 'custom' && date_range::valid($daterange) ? $daterange : null;

        $transaction = $DB->start_delegated_transaction();
        $report->id = (int) $DB->insert_record('local_reportium', $report);
        foreach ((array) ($data['levels'] ?? []) as $index => $level) {
            $sql = trim((string) ($level['sqlquery'] ?? ''));
            if ($sql === '') {
                continue;
            }
            $DB->insert_record(level_service::TABLE, (object) [
                'reportid' => $report->id,
                'drilllevel' => max(0, (int) ($level['level'] ?? $index)),
                'label' => \core_text::substr((string) ($level['label'] ?? ''), 0, 255),
                'sqlquery' => $sql,
                'config' => !empty($level['config']) ? json_encode($level['config']) : null,
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
        }
        // Roles by short name: the ones that exist on this site; Manager when none does.
        $roles = array_filter((array) ($data['roles'] ?? []), 'is_string');
        if ($roles) {
            [$insql, $params] = $DB->get_in_or_equal($roles);
            report_roles::set($report->id, array_map('intval', $DB->get_fieldset_select(
                'role',
                'id',
                "shortname $insql",
                $params
            )));
        }
        report_roles::set_defaults($report->id);
        set_config(
            'cachettlminutes_report_' . $report->id,
            max(0, (int) ($data['cachettlminutes'] ?? 0)),
            'local_reportium'
        );
        $transaction->allow_commit();
        return $report->id;
    }

    /**
     * Report file data from an XML report file of an SQL report: its settings, and its SQL as the first level.
     *
     * @param string $xml
     * @return array
     * @throws moodle_exception errorimporting when it is not one
     */
    protected static function read_xml(string $xml): array {
        global $CFG;
        require_once($CFG->libdir . '/xmlize.php');
        try {
            $parsed = xmlize($xml, 1, 'UTF-8');
        } catch (\Throwable $e) {
            throw new moodle_exception('errorimporting', 'local_reportium');
        }
        if (!isset($parsed['report']['@']['version']) || !is_array($parsed['report']['#'] ?? null)) {
            throw new moodle_exception('errorimporting', 'local_reportium');
        }
        $values = [];
        foreach ($parsed['report']['#'] as $key => $value) {
            $values[$key] = trim((string) ($value[0]['#'] ?? ''));
        }
        $sql = self::components_sql((string) base64_decode($values['components'] ?? ''));
        if (($values['type'] ?? '') !== 'sql' || trim($sql) === '' || ($values['name'] ?? '') === '') {
            throw new moodle_exception('errorimporting', 'local_reportium');
        }
        $report = array_intersect_key($values, array_flip(self::FIELDS));
        return ['report' => $report, 'levels' => [['level' => 0, 'label' => '', 'sqlquery' => $sql]]];
    }

    /**
     * SQL of serialized report "components" (XML report files, and reports stored before levels): the query of
     * their "customsql" part.
     *
     * @param string $components serialized components
     * @return string SQL, or '' when there is none
     */
    public static function components_sql(string $components): string {
        // Only plain data objects are unserialized.
        $data = @unserialize(
            preg_replace('/O:6:"object"/', 'O:8:"stdClass"', $components),
            ['allowed_classes' => [stdClass::class]]
        );
        if (!is_array($data) || !isset($data['customsql']['config'])) {
            return '';
        }
        $config = (array) $data['customsql']['config'];
        return str_replace(["\\'", '\\"'], ["'", '"'], urldecode((string) ($config['querysql'] ?? '')));
    }
}
