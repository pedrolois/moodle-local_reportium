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

/**
 * Infers column types and the default chart mapping from whatever a level's SQL returns.
 *
 * This is what makes levels "dynamic": no configuration is needed, the X axis, metrics, the value passed to
 * the next level and the filters all derive from the result columns. Authors can override the mapping.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class column_inspector {
    /** @var string Identifier column (ids are passed between levels, never charted). */
    public const TYPE_ID = 'id';
    /** @var string Numeric column (a metric). */
    public const TYPE_NUMBER = 'number';
    /** @var string Unix timestamp column. */
    public const TYPE_DATE = 'date';
    /** @var string Anything else. */
    public const TYPE_TEXT = 'text';

    /** @var int Timestamps between 2000-01-01 and 2100-01-01 are treated as dates (when the name suggests it). */
    private const MIN_TIMESTAMP = 946684800;
    /** @var int */
    private const MAX_TIMESTAMP = 4102444800;

    /**
     * Describe the columns of a result set.
     *
     * @param string[] $keys column names in SQL order
     * @param array[] $rows rows as associative arrays
     * @return array[] list of ['key' => string, 'label' => string, 'type' => string]
     */
    public static function describe(array $keys, array $rows): array {
        $columns = [];
        foreach ($keys as $key) {
            $values = [];
            foreach ($rows as $row) {
                $value = $row[$key] ?? null;
                if ($value !== null && $value !== '') {
                    $values[] = $value;
                }
            }
            $type = self::detect_type((string) $key, $values);
            // Dates the SQL already formatted as text ("06/10/2026", "2026-09-28 14:27:20") are dates too:
            // the payload rows are converted to timestamps (convert_text_dates) so filters and sorting work by date.
            $textdate = false;
            $withtime = false;
            if ($type === self::TYPE_TEXT && $values) {
                $textdate = true;
                foreach ($values as $value) {
                    $parsed = self::parse_text_date((string) $value);
                    if ($parsed === null) {
                        $textdate = false;
                        break;
                    }
                    $withtime = $withtime || $parsed['withtime'];
                }
                if ($textdate) {
                    $type = self::TYPE_DATE;
                }
            }
            $columns[] = [
                'key' => (string) $key,
                'label' => self::humanise((string) $key),
                'type' => $type,
                'textdate' => $textdate,
                'withtime' => $withtime,
                // How the table shows it (progress bar, state label...), from the keywords in instructions.json.
                'format' => instructions::detect_format((string) $key, $type),
            ];
        }
        return $columns;
    }

    /**
     * Parse a date written as text: d/m/Y or Y-m-d, optionally followed by H:i or H:i:s.
     * Slashes are read day first (as DATE_FORMAT('%d/%m/%Y') writes them).
     *
     * @param string $value
     * @return array|null ['timestamp' => int, 'withtime' => bool], null when it is not such a date
     */
    public static function parse_text_date(string $value): ?array {
        $value = trim($value);
        $time = '(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?';
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})' . $time . '$#', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})' . $time . '$#', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if (!checkdate($month, $day, $year)) {
            return null;
        }
        $hour = (int) ($m[4] ?? 0);
        $minute = (int) ($m[5] ?? 0);
        $second = (int) ($m[6] ?? 0);
        $date = new \DateTime('now', \core_date::get_server_timezone_object());
        $date->setDate($year, $month, $day);
        $date->setTime($hour, $minute, $second);
        return ['timestamp' => $date->getTimestamp(), 'withtime' => isset($m[4]) && $m[4] !== ''];
    }

    /**
     * Turn the text dates of the columns marked "textdate" into timestamps, in place.
     *
     * @param array[] $columns output of describe()
     * @param array[] $rows
     * @return array[] rows
     */
    public static function convert_text_dates(array $columns, array $rows): array {
        $keys = array_column(array_filter($columns, static function (array $column): bool {
            return !empty($column['textdate']);
        }), 'key');
        if (!$keys) {
            return $rows;
        }
        foreach ($rows as $i => $row) {
            foreach ($keys as $key) {
                $parsed = isset($row[$key]) && $row[$key] !== '' ? self::parse_text_date((string) $row[$key]) : null;
                $rows[$i][$key] = $parsed ? (string) $parsed['timestamp'] : '';
            }
        }
        return $rows;
    }

    /**
     * Detect the type of one column from its name and non-empty values.
     *
     * @param string $key
     * @param array $values non-empty values
     * @return string one of the TYPE_* constants
     */
    public static function detect_type(string $key, array $values): string {
        $lkey = strtolower($key);
        $allnumeric = $values && array_reduce($values, static function (bool $carry, $value): bool {
            return $carry && is_numeric(trim((string) $value));
        }, true);
        $allintegers = $allnumeric && array_reduce($values, static function (bool $carry, $value): bool {
            return $carry && preg_match('/^-?\d+$/', trim((string) $value));
        }, true);

        if ($allintegers && ($lkey === 'id' || preg_match('/(^|_)id$/', $lkey) || preg_match('/[a-z]id$/', $lkey))) {
            return self::TYPE_ID;
        }
        if ($allintegers && preg_match('/(time|date|created|modified|access|start|end)/', $lkey)) {
            $intimestamprange = array_reduce($values, static function (bool $carry, $value): bool {
                $value = (int) $value;
                return $carry && ($value === 0 || ($value >= self::MIN_TIMESTAMP && $value <= self::MAX_TIMESTAMP));
            }, true);
            if ($intimestamprange) {
                return self::TYPE_DATE;
            }
        }
        if ($allnumeric) {
            return self::TYPE_NUMBER;
        }
        return self::TYPE_TEXT;
    }

    /** @var string Key of a row that holds the cleaned HTML of its HTML cells (column => html). */
    const HTML_KEY = '__html';

    /**
     * Columns whose SQL returns HTML (e.g. CONCAT('<a href="', ..., '">', c.fullname, '</a>')): the table shows the
     * HTML, cleaned like any user text (no scripts, no unsafe attributes; links kept); charts, search, filters, sort and
     * downloads use the text without tags.
     *
     * @param array[] $columns describe() output (changed: 'html' => true on those columns)
     * @param array[] $rows rows (changed: text without tags, the cleaned HTML in HTML_KEY)
     * @param \context|null $context context of the Moodle filters (e.g. multi-language names); default the system
     * @return array [columns, rows]
     */
    public static function html_cells(array $columns, array $rows, ?\context $context = null): array {
        $html = [];
        foreach ($columns as $i => $column) {
            foreach ($rows as $row) {
                $value = (string) ($row[$column['key']] ?? '');
                if (
                    $value !== '' &&
                        preg_match('/<\s*\/?\s*(a|b|i|u|em|strong|span|br|img|div|p|small|ul|ol|li|font)\b/i', $value)
                ) {
                    $html[] = $column['key'];
                    $columns[$i]['html'] = true;
                    break;
                }
            }
        }
        if (!$html) {
            return [$columns, $rows];
        }
        foreach ($rows as $r => $row) {
            foreach ($html as $key) {
                $value = (string) ($row[$key] ?? '');
                if ($value === '') {
                    continue;
                }
                // Cleaned (never "noclean": data typed by users must not run scripts), with the Moodle filters.
                $html = format_text($value, FORMAT_HTML, ['para' => false, 'context' => $context ?? \context_system::instance()]);
                $rows[$r][self::HTML_KEY][$key] = $html;
                // The text of what is shown (cleaned and filtered), never the content of removed tags such as scripts.
                $rows[$r][$key] = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(
                    preg_replace('/<br\s*\/?>/i', ' ', $html)
                ), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            }
        }
        return [$columns, $rows];
    }

    /** @var string[] Calculations of KPI cards and of the totals row. */
    const AGGREGATES = ['sum', 'avg', 'min', 'max', 'count', 'distinct'];

    /** @var array[] Links to Moodle: the id columns of each kind, and the columns that show its name. */
    const LINKS = [
        'user' => [
            'ids' => ['userid', 'user_id', 'studentid', 'student_id', 'learnerid', 'learner_id', 'teacherid', 'teacher_id'],
            'names' => ['fullname', 'user', 'username', 'user_name', 'name', 'learner', 'student', 'teacher', 'participant',
                'firstname', 'lastname', 'email'],
        ],
        'course' => [
            'ids' => ['courseid', 'course_id'],
            'names' => ['course', 'coursename', 'course_name', 'course_fullname', 'shortname', 'fullname', 'name'],
        ],
        'category' => [
            'ids' => ['categoryid', 'category_id', 'catid'],
            'names' => ['category', 'categoryname', 'category_name', 'name'],
        ],
    ];

    /**
     * Columns shown as links to Moodle: a name column next to the id of what it names (userid + fullname, courseid +
     * course, categoryid + category). Without such an id column, the "id" column of the level is used when one name
     * column clearly names one kind of thing (as in "SELECT c.id, c.fullname AS course").
     *
     * @param array[] $columns describe() output
     * @return array column key => ['kind' => user|course|category, 'idkey' => column, 'courseidkey' => column|null]
     */
    public static function links(array $columns): array {
        $keys = array_column($columns, 'key');
        $lower = array_combine(array_map('strtolower', $keys), $keys);
        $links = [];
        $idof = [];
        foreach (self::LINKS as $kind => $spec) {
            foreach ($spec['ids'] as $id) {
                if (isset($lower[$id])) {
                    $idof[$kind] = $lower[$id];
                    break;
                }
            }
        }
        $taken = [];
        // Name columns of the kinds whose id column is in the level ("name" goes to the first such kind).
        foreach (self::LINKS as $kind => $spec) {
            if (!isset($idof[$kind])) {
                continue;
            }
            foreach ($spec['names'] as $name) {
                if (isset($lower[$name]) && !isset($taken[$lower[$name]])) {
                    $links[$lower[$name]] = ['kind' => $kind, 'idkey' => $idof[$kind]];
                    $taken[$lower[$name]] = true;
                    break;
                }
            }
        }
        // Without id columns: "id" with an unambiguous name column (course, category, or a person's name).
        if (!$links && isset($lower['id'])) {
            $unambiguous = ['course' => ['course', 'coursename', 'course_name', 'course_fullname'],
                'category' => ['category', 'categoryname', 'category_name'],
                'user' => ['learner', 'student', 'teacher', 'participant', 'user']];
            // The "fullname" column names courses too: a person only next to an email.
            if (isset($lower['fullname'], $lower['email'])) {
                $unambiguous['user'][] = 'fullname';
            }
            $found = [];
            foreach ($unambiguous as $kind => $names) {
                foreach ($names as $name) {
                    if (isset($lower[$name])) {
                        $found[$kind] = $lower[$name];
                        break;
                    }
                }
            }
            // Only when the name columns point to a single kind of thing.
            if (count($found) === 1) {
                $kind = array_key_first($found);
                $links[$found[$kind]] = ['kind' => $kind, 'idkey' => $lower['id']];
            }
        }
        // A user link opens the profile in the course of the row, when the row has one.
        foreach ($links as $key => $link) {
            $links[$key]['courseidkey'] = $link['kind'] === 'user' ? ($idof['course'] ?? null) : null;
        }
        return $links;
    }

    /**
     * Default mapping for a level: what is shown, what is passed down, what is charted.
     *
     * @param array[] $columns output of describe()
     * @return array ['labelcolumn' => ?string, 'valuecolumn' => ?string, 'metrics' => string[], 'defaultview' => string]
     */
    public static function default_mapping(array $columns): array {
        $bytype = [];
        foreach ($columns as $column) {
            $bytype[$column['type']][] = $column['key'];
        }
        $label = $bytype[self::TYPE_TEXT][0] ?? $bytype[self::TYPE_DATE][0] ?? null;
        if ($label === null && $columns) {
            // Only numbers/ids: label with the first column that is not a metric candidate.
            $label = $bytype[self::TYPE_ID][0] ?? $columns[0]['key'];
        }
        $metrics = array_values(array_filter($bytype[self::TYPE_NUMBER] ?? [], static function (string $key) use ($label): bool {
            return $key !== $label;
        }));
        $isdatelabel = $label !== null && in_array($label, $bytype[self::TYPE_DATE] ?? [], true);

        return [
            'labelcolumn' => $label,
            'valuecolumn' => $bytype[self::TYPE_ID][0] ?? $label,
            'metrics' => $metrics,
            'defaultview' => $isdatelabel ? 'line' : 'bar',
        ];
    }

    /**
     * Merge an author-saved mapping over the defaults, dropping anything that no longer exists in the columns.
     *
     * @param array $defaults output of default_mapping()
     * @param array $saved saved config (may be partial or stale)
     * @param array[] $columns output of describe()
     * @return array
     */
    public static function merge_mapping(array $defaults, array $saved, array $columns): array {
        $keys = array_column($columns, 'key');
        $mapping = $defaults;
        if (!empty($saved['labelcolumn']) && is_string($saved['labelcolumn'])) {
            // The label may combine two columns, "fullname|email", shown as "Ana (ana@x.org)".
            $parts = explode('|', $saved['labelcolumn']);
            if (count($parts) <= 2 && !array_diff($parts, $keys)) {
                $mapping['labelcolumn'] = $saved['labelcolumn'];
            }
        }
        if (!empty($saved['valuecolumn']) && in_array($saved['valuecolumn'], $keys, true)) {
            $mapping['valuecolumn'] = $saved['valuecolumn'];
        }
        if (isset($saved['metrics']) && is_array($saved['metrics'])) {
            $metrics = array_values(array_intersect($saved['metrics'], $keys));
            if ($metrics) {
                $mapping['metrics'] = $metrics;
            }
        }
        if (!empty($saved['defaultview']) && in_array($saved['defaultview'], self::views(), true)) {
            $mapping['defaultview'] = $saved['defaultview'];
        }
        foreach (['title'] as $field) {
            if (isset($saved[$field]) && is_string($saved[$field])) {
                $mapping[$field] = $saved[$field];
            }
        }
        if (isset($saved['pagesize'])) {
            $mapping['pagesize'] = max(1, (int) $saved['pagesize']);
        }
        if (isset($saved['stacked'])) {
            $mapping['stacked'] = (bool) $saved['stacked'];
        }
        // KPI cards above the chart: false = none; a list of {key, agg} chosen in the chart settings ("_rows" = the
        // number of rows); unset = automatic. Totals row under the table, with the same calculations.
        if (isset($saved['kpis'])) {
            if (is_array($saved['kpis'])) {
                $kpis = [];
                foreach ($saved['kpis'] as $kpi) {
                    $key = is_array($kpi) ? (string) ($kpi['key'] ?? '') : '';
                    $agg = is_array($kpi) ? (string) ($kpi['agg'] ?? '') : '';
                    if (in_array($agg, self::AGGREGATES, true) && ($key === '_rows' || in_array($key, $keys, true))) {
                        // Each card (column + calculation) once.
                        $kpis[$key === '_rows' ? '_rows' : $key . ':' . $agg] = ['key' => $key,
                            'agg' => $key === '_rows' ? 'count' : $agg];
                    }
                }
                $mapping['kpis'] = array_values($kpis);
            } else {
                // Older settings: a switch (true = automatic cards).
                if (!$saved['kpis']) {
                    $mapping['kpis'] = false;
                }
            }
        }
        if (isset($saved['totalsrow'])) {
            $mapping['totalsrow'] = (bool) $saved['totalsrow'];
        }
        // Calculated columns: [{key, label, formula, format}].
        if (isset($saved['calculated'])) {
            $mapping['calculated'] = formula::definitions($saved['calculated'], $columns);
        }
        // Chart types shown at this level (a subset of the report's); empty = all of the report's.
        if (isset($saved['viewtypes']) && is_array($saved['viewtypes'])) {
            $viewtypes = array_values(array_intersect(self::views(), $saved['viewtypes']));
            if ($viewtypes) {
                $mapping['viewtypes'] = $viewtypes;
            }
        }
        // Per chart type: own metrics and stacking (bar, pie, line).
        if (isset($saved['views']) && is_array($saved['views'])) {
            $views = [];
            foreach ($saved['views'] as $view => $settings) {
                if (!in_array($view, self::views(), true) || !is_array($settings)) {
                    continue;
                }
                $clean = [];
                if (isset($settings['metrics']) && is_array($settings['metrics'])) {
                    $clean['metrics'] = array_values(array_intersect($settings['metrics'], $keys));
                }
                if (isset($settings['stacked'])) {
                    $clean['stacked'] = (bool) $settings['stacked'];
                }
                $views[$view] = $clean;
            }
            if ($views) {
                $mapping['views'] = $views;
            }
        }
        // Column formats chosen in the chart settings (override the automatic keyword detection).
        if (isset($saved['formats']) && is_array($saved['formats'])) {
            $formats = [];
            foreach ($saved['formats'] as $key => $format) {
                if (in_array($key, $keys, true) && in_array($format, instructions::format_names(), true)) {
                    $formats[$key] = $format;
                }
            }
            if ($formats) {
                $mapping['formats'] = $formats;
            }
        }
        return $mapping;
    }

    /**
     * Views a level can be shown as.
     *
     * @return string[]
     */
    public static function views(): array {
        return ['bar', 'pie', 'line', 'table'];
    }

    /**
     * Turn a column alias into a readable label: "enrolled_users" → "Enrolled users".
     *
     * @param string $key
     * @return string
     */
    public static function humanise(string $key): string {
        $text = trim(preg_replace('/[_\s]+/', ' ', $key));
        return $text === '' ? $key : \core_text::strtoupper(\core_text::substr($text, 0, 1)) . \core_text::substr($text, 1);
    }
}
