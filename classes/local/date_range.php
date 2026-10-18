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

use DateTimeImmutable;
use DateTimeZone;

/**
 * Periods of report data: the values of %%STARTTIME%% and %%ENDTIME%%.
 *
 * A period is a preset relative to now ("last30", "lastmonth"...), or "custom" with its own dates. Days start at
 * midnight in the timezone of the user. Every period ends at the last second of its last day; "all time" is 0 to
 * ALL_END.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class date_range {
    /** @var int End of "all time" (2038-01-01). */
    const ALL_END = 2145938400;

    /** @var string[] Presets, in the order they are offered. '' = all time. */
    const PRESETS = ['', 'today', 'yesterday', 'last7', 'last30', 'last90', 'thisweek', 'lastweek', 'thismonth',
        'lastmonth', 'thisquarter', 'lastquarter', 'thisyear', 'lastyear', 'last365'];

    /**
     * Presets with their names, for selects.
     *
     * @param bool $custom include "Custom dates"
     * @return string[] preset => name
     */
    public static function options(bool $custom = true): array {
        $options = [];
        foreach (self::PRESETS as $preset) {
            $options[$preset] = get_string('range_' . ($preset === '' ? 'all' : $preset), 'local_reportium');
        }
        if ($custom) {
            $options['custom'] = get_string('range_custom', 'local_reportium');
        }
        return $options;
    }

    /**
     * Whether a value is a known preset ('' and 'custom' included).
     *
     * @param string $preset
     * @return bool
     */
    public static function valid(string $preset): bool {
        return $preset === 'custom' || in_array($preset, self::PRESETS, true);
    }

    /**
     * Start and end (unix time, both included) of a period.
     *
     * @param string $preset a preset, 'custom' or '' (all time)
     * @param int $now
     * @param int $from custom start (any time of the first day)
     * @param int $to custom end (any time of the last day)
     * @param DateTimeZone|null $timezone default the user's timezone
     * @return int[] [start, end]
     */
    public static function resolve(
        string $preset,
        int $now = 0,
        int $from = 0,
        int $to = 0,
        ?DateTimeZone $timezone = null
    ): array {
        $timezone = $timezone ?? \core_date::get_user_timezone_object();
        $now = $now ?: time();
        $today = (new DateTimeImmutable('@' . $now))->setTimezone($timezone)->setTime(0, 0);
        $day = static fn(DateTimeImmutable $date): DateTimeImmutable => $date->setTime(0, 0);
        $range = static fn(DateTimeImmutable $start, DateTimeImmutable $endday): array =>
            [$start->getTimestamp(), $endday->modify('+1 day')->getTimestamp() - 1];
        $quarterstart = $today->setDate((int) $today->format('Y'), intdiv((int) $today->format('n') - 1, 3) * 3 + 1, 1);
        switch ($preset) {
            case 'today':
                return $range($today, $today);
            case 'yesterday':
                return $range($today->modify('-1 day'), $today->modify('-1 day'));
            case 'last7':
                return $range($today->modify('-6 days'), $today);
            case 'last30':
                return $range($today->modify('-29 days'), $today);
            case 'last90':
                return $range($today->modify('-89 days'), $today);
            case 'last365':
                return $range($today->modify('-364 days'), $today);
            case 'thisweek':
                $monday = $today->modify('monday this week');
                return $range($monday, $monday->modify('+6 days'));
            case 'lastweek':
                $monday = $today->modify('monday this week')->modify('-7 days');
                return $range($monday, $monday->modify('+6 days'));
            case 'thismonth':
                $first = $today->modify('first day of this month');
                return $range($first, $first->modify('last day of this month'));
            case 'lastmonth':
                $first = $today->modify('first day of last month');
                return $range($first, $first->modify('last day of this month'));
            case 'thisquarter':
                return $range($quarterstart, $quarterstart->modify('+3 months')->modify('-1 day'));
            case 'lastquarter':
                $first = $quarterstart->modify('-3 months');
                return $range($first, $quarterstart->modify('-1 day'));
            case 'thisyear':
                $first = $today->setDate((int) $today->format('Y'), 1, 1);
                return $range($first, $first->setDate((int) $today->format('Y'), 12, 31));
            case 'lastyear':
                $year = (int) $today->format('Y') - 1;
                return $range($today->setDate($year, 1, 1), $today->setDate($year, 12, 31));
            case 'custom':
                if ($from <= 0 && $to <= 0) {
                    return [0, self::ALL_END];
                }
                if ($from > 0 && $to > 0 && $from > $to) {
                    [$from, $to] = [$to, $from];
                }
                $start = $from > 0 ? $day((new DateTimeImmutable('@' . $from))->setTimezone($timezone))->getTimestamp() : 0;
                $end = $to > 0 ? $range($today, $day((new DateTimeImmutable('@' . $to))->setTimezone($timezone)))[1]
                    : self::ALL_END;
                return [$start, $end];
            default:
                return [0, self::ALL_END];
        }
    }

    /**
     * The period before another one, to compare with: the previous calendar month, quarter or year for those
     * presets; otherwise the same number of days just before.
     *
     * @param string $preset
     * @param int $start
     * @param int $end
     * @param DateTimeZone|null $timezone
     * @return int[]|null [start, end], or null for all time
     */
    public static function previous(string $preset, int $start, int $end, ?DateTimeZone $timezone = null): ?array {
        if ($start <= 0 && $end >= self::ALL_END) {
            return null;
        }
        $timezone = $timezone ?? \core_date::get_user_timezone_object();
        $first = (new DateTimeImmutable('@' . $start))->setTimezone($timezone);
        $months = ['thismonth' => 1, 'lastmonth' => 1, 'thisquarter' => 3, 'lastquarter' => 3, 'thisyear' => 12,
            'lastyear' => 12][$preset] ?? 0;
        if ($months) {
            $previous = $first->modify("-$months months");
            return [$previous->getTimestamp(), $start - 1];
        }
        // Whole days, so a daylight saving change does not shift the period.
        $days = (int) round(($end + 1 - $start) / DAYSECS);
        return [$first->modify("-$days days")->getTimestamp(), $start - 1];
    }

    /**
     * Readable period, e.g. "01/09/2026 - 30/09/2026".
     *
     * @param int $start
     * @param int $end
     * @return string '' for all time
     */
    public static function label(int $start, int $end): string {
        if ($start <= 0 && $end >= self::ALL_END) {
            return '';
        }
        $format = str_replace('%y', '%Y', get_string('strftimedatefullshort', 'langconfig'));
        return ($start > 0 ? userdate($start, $format) : '…') . ' - ' . ($end < self::ALL_END ? userdate($end, $format) : '…');
    }

    /**
     * Whether some SQL filters by period.
     *
     * @param string $sql
     * @return bool
     */
    public static function used_in(string $sql): bool {
        return strpos($sql, '%%STARTTIME%%') !== false || strpos($sql, '%%ENDTIME%%') !== false;
    }
}
