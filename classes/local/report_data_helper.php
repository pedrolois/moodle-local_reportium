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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_reportium\local;

use cache;
use context;
use core_text;
use moodle_exception;

/**
 * Helpers for exposing report data in structured form.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_data_helper {
    /**
     * Request-local report cache.
     *
     * @var array<int, \stdClass>
     */
    protected static array $reportcache = [];

    /**
     * Request-local freshness cache.
     *
     * @var array<int, int>
     */
    protected static array $freshnesscache = [];

    /**
     * Request-local MUC instance.
     *
     * @var cache|null
     */
    protected static ?cache $mucinstance = null;

            /**
             * Return all saved SQL queries for a report from DB, keyed by drilllevel (int).
             *
             * @param int $reportid
             * @return array  [drilllevel => ['label'=>..., 'sqlquery'=>...], ...]
             */
    public static function get_saved_sqls(int $reportid): array {
        global $DB;
        $records = $DB->get_records('local_reportium_level', ['reportid' => $reportid], 'drilllevel ASC');
        $result = [];
        foreach ($records as $r) {
            $result[(int)$r->drilllevel] = [
                'label'    => (string) $r->label,
                'sqlquery' => (string) $r->sqlquery,
            ];
        }
        return $result;
    }

            /**
             * Return report record or fail.
             *
             * @param int $reportid
             * @return \stdClass
             */
    protected static function get_report(int $reportid): \stdClass {
        global $DB;

        if (isset(self::$reportcache[$reportid])) {
            return self::$reportcache[$reportid];
        }

        $report = $DB->get_record('local_reportium', ['id' => $reportid]);
        if (!$report) {
            throw new moodle_exception('reportdoesnotexists', 'local_reportium');
        }

        self::$reportcache[$reportid] = $report;
        return self::$reportcache[$reportid];
    }

                    /**
                     * Validate the SQL of a drill level before saving it.
                     *
                     * Reports only read data: write statements, several statements and explicit table prefixes are refused.
                     * Then the query runs once (limited to one row) exactly like at runtime, so it works on every database
                     * Moodle supports.
                     *
                     * @param \stdClass $report Report record.
                     * @param int $drilllevel 0 = first level, 1+ = drill-down levels.
                     * @param string $sql
                     * @return string|null Error message, or null when the SQL is valid.
                     */
    public static function validate_level_sql(\stdClass $report, int $drilllevel, string $sql): ?string {
        global $CFG, $DB, $USER;

        $sql = trim($sql);
        if ($sql === '') {
            return null;
        }

        $component = 'local_reportium';
        if (preg_match('/\b(INSERT|INTO|CREATE|ALTER|DELETE|DROP|GRANT|TRUNCATE|UPDATE|SET|VACUUM|REINDEX|DISCARD|LOCK)\b/i',
                $sql)) {
            return get_string('notallowedwords', $component);
        }
        if (strpos($sql, ';') !== false) {
            return get_string('nosemicolon', $component);
        }
        if ($CFG->prefix !== '' && preg_match('/\b' . preg_quote($CFG->prefix, '/') . '\w+/i', $sql)) {
            return get_string('noexplicitprefix', $component);
        }
        // A level under the first one shows what belongs to the row clicked above: without it, every row would open the
        // same list.
        if ($drilllevel > 0 && !preg_match('/%%(PREVIOUSVALUE|LEVELVALUE:\d+)%%/', $sql)) {
            return get_string('levelnotlinked', $component, (object) ['level' => $drilllevel + 1, 'parent' => $drilllevel]);
        }

        try {
            // Same binding as the runtime. '0' is a dummy drill value that casts cleanly to both numeric and
            // text columns (PostgreSQL is strict about this); %%MYCOURSES%% is tried with no course.
            $vars = ['userid' => (int) $USER->id, 'courseid' => (int) $report->courseid, 'categoryid' => 0,
                'mycourses' => []];
            [$testsql, $params] = level_service::bind_placeholders($sql, array_fill(0, $drilllevel, '0'), $vars);
            $rs = $DB->get_recordset_sql($testsql, $params, 0, level_service::has_own_limit($testsql) ? 0 : 1);
            $rs->close();
        } catch (\dml_exception $e) {
            $detail = ($e instanceof \dml_read_exception && !empty($e->error)) ? $e->error : $e->getMessage();
            // Plain text: the SQL drawer renders this with textContent.
            return get_string('drillsqlqueryfailed', $component, $detail);
        }

        return null;
    }

}
