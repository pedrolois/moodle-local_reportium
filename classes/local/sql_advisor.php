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

use stdClass;

/**
 * Slow query warning: whether a level took too long, and what in its SQL usually makes a query slow.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql_advisor {
    /** @var int Default threshold, in seconds. */
    const DEFAULT_SECONDS = 3;

    /**
     * Threshold of the plugin settings, in milliseconds (0 = no warning).
     *
     * @return int
     */
    public static function threshold_ms(): int {
        $seconds = get_config('local_reportium', 'slowquerysecs');
        $seconds = $seconds === false || $seconds === '' ? self::DEFAULT_SECONDS : (float) $seconds;
        return max(0, (int) round($seconds * 1000));
    }

    /**
     * Whether a run of a level is slow.
     *
     * @param int $executionms
     * @return bool
     */
    public static function is_slow(int $executionms): bool {
        $threshold = self::threshold_ms();
        return $threshold > 0 && $executionms >= $threshold;
    }

    /**
     * Tips for the SQL of a level: the usual causes of slow queries found in it, most useful first.
     *
     * @param string $sql
     * @param stdClass $report
     * @return string[] tip keys (lang strings slowtip_<key>)
     */
    public static function tips(string $sql, stdClass $report): array {
        $text = preg_replace('/\s+/', ' ', $sql);
        $upper = strtoupper($text);
        $tips = [];
        // The logs are the biggest table of a site: without a period every run reads all of it.
        if (stripos($text, '{logstore_standard_log}') !== false && !date_range::used_in($text)) {
            $tips[] = 'period';
        }
        // A subquery in the SELECT list runs once per row.
        $from = strpos($upper, ' FROM ');
        if ($from !== false && strpos(substr($upper, 0, $from), '(SELECT ') !== false) {
            $tips[] = 'subquery';
        }
        // Functions on columns in WHERE / ON stop the database from using its indexes.
        $where = preg_split('/\b(?:WHERE|ON)\b/i', $text);
        array_shift($where);
        $conditions = implode(' ', $where);
        $functions = 'FROM_UNIXTIME|DATE|YEAR|MONTH|DAY|LOWER|UPPER|TRIM|CONCAT|COALESCE|CAST|SUBSTR|SUBSTRING';
        if (preg_match('/\b(?:' . $functions . ')\s*\(\s*\w+\.\w+/i', $conditions)) {
            $tips[] = 'function';
        }
        // A pattern that starts with % cannot use an index.
        if (preg_match("/\bLIKE\s+'%/i", $text)) {
            $tips[] = 'like';
        }
        // OR between join conditions usually reads whole tables.
        if (preg_match('/\bJOIN\b[^()]*?\bON\b(?:(?!\bJOIN\b|\bWHERE\b).)*\bOR\b/i', $text)) {
            $tips[] = 'or';
        }
        if (preg_match('/\bSELECT\s+(?:DISTINCT\s+)?\*/i', $text) || preg_match('/\bSELECT\s+\w+\.\*/i', $text)) {
            $tips[] = 'selectall';
        }
        // Without a cache every click runs the query again.
        if ((int) get_config('local_reportium', 'cachettlminutes_report_' . $report->id) <= 0) {
            $tips[] = 'cache';
        }
        return $tips ?: ['generic'];
    }
}
