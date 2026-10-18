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

use cache;
use context;
use moodle_exception;
use stdClass;

/**
 * Generic drill-down levels: one data model for every view (bar, pie, line, table).
 *
 * A report is an ordered list of levels (0..N) stored in local_reportium_level. Each level's SQL may use
 * %%PREVIOUSVALUE%% (value chosen in the parent level) and %%LEVELVALUE:n%% (value chosen at level n); both
 * are bound as parameters. The payload of a level is the same for every view, so they always agree.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class level_service {

    /** @var string What a "?" inside a text of the SQL becomes while the query runs (see protect_literals()). */
    const QUESTIONMARK = '[[QUESTIONMARK]]';

    /** @var string What the ":" of a ":name" inside a text of the SQL becomes while the query runs. */
    const COLON = '[[COLON]]';
    /** @var string Table holding one row per report level. */
    public const TABLE = 'local_reportium_level';

    /**
     * Load a report record or throw.
     *
     * @param int $reportid
     * @return stdClass
     */
    public static function get_report(int $reportid): stdClass {
        global $DB;
        $report = $DB->get_record('local_reportium', ['id' => $reportid]);
        if (!$report) {
            throw new moodle_exception('reportdoesnotexists', 'local_reportium');
        }
        return $report;
    }

    /**
     * Resolve the context a report runs in (a global report may run in another course).
     *
     * @param stdClass $report
     * @param int $courseid requested course, 0 for the report's own
     * @return context
     */
    public static function get_context(stdClass $report, int $courseid = 0): context {
        if (!$report->global || !$courseid) {
            $courseid = (int) $report->courseid;
        }
        return $courseid === (int) SITEID ? \context_system::instance() : \context_course::instance($courseid);
    }

    /**
     * Throw unless the user may view the report in that context (same rules as the report page).
     *
     * @param stdClass $report
     * @param context $context
     * @param int $userid
     * @return void
     */
    public static function require_view(stdClass $report, context $context, int $userid): void {
        if (!report_roles::can_view($report, $userid, $context)) {
            throw new moodle_exception('badpermissions', 'local_reportium');
        }
    }

    /**
     * All levels of a report, ordered, with their decoded config.
     *
     * @param int $reportid
     * @return array[] list of ['level', 'label', 'sqlquery', 'config' (array)]
     */
    public static function get_levels(int $reportid): array {
        global $DB;
        $levels = [];
        foreach ($DB->get_records(self::TABLE, ['reportid' => $reportid], 'drilllevel ASC') as $record) {
            $levels[] = [
                'level' => (int) $record->drilllevel,
                'label' => (string) $record->label,
                'sqlquery' => (string) $record->sqlquery,
                'config' => self::decode_config($record->config ?? null),
            ];
        }
        return $levels;
    }

    /**
     * Build the payload of one level for the given drill path.
     *
     * @param stdClass $report
     * @param int $level
     * @param string[] $path values chosen at levels 0..$level-1 (path[0] is the value clicked on level 0)
     * @param context $context
     * @param int $userid
     * @param int[] $range [start, end] of %%STARTTIME%% / %%ENDTIME%%; empty = all time
     * @return array ['level', 'title', 'columns', 'rows', 'mapping', 'hasnext', 'cachemeta']
     */
    public static function get_level_payload(stdClass $report, int $level, array $path, context $context, int $userid,
            array $range = []): array {
        $levels = [];
        foreach (self::get_levels((int) $report->id) as $item) {
            $levels[$item['level']] = $item;
        }
        if (!isset($levels[$level]) || trim($levels[$level]['sqlquery']) === '') {
            throw new moodle_exception('levelnotfound', 'local_reportium', '', $level);
        }
        if (count($path) < $level) {
            throw new moodle_exception('invalidpath', 'local_reportium');
        }
        $path = array_map('strval', array_slice(array_values($path), 0, $level));

        $ttl = max(0, (int) get_config('local_reportium', 'cachettlminutes_report_' . $report->id));
        // Simple keys only (a-z, 0-9, _): the cache definition uses simplekeys.
        $cachekey = 'level_' . sha1(json_encode([
            (int) $report->id, $level, $path, $context->id, $userid, sha1($levels[$level]['sqlquery']),
            $levels[$level]['config'], self::request_filters_signature(), array_values($range),
        ]));
        if ($ttl > 0) {
            $entry = cache::make('local_reportium', 'reportdata')->get($cachekey);
            if (is_array($entry) && (time() - (int) $entry['cachedat']) <= $ttl * 60) {
                $entry['payload']['cachemeta'] = [
                    'freshnessminutes' => $ttl,
                    'cached' => true,
                    'generatedat' => (int) $entry['cachedat'],
                ];
                return $entry['payload'];
            }
        }

        $started = microtime(true);
        $vars = self::user_vars($context, $userid);
        if (count($range) === 2) {
            [$vars['starttime'], $vars['endtime']] = array_map('intval', array_values($range));
        }
        [$keys, $rows] = self::run_level($levels[$level]['sqlquery'], $path, $vars);
        $executionms = (int) round((microtime(true) - $started) * 1000);

        $columns = column_inspector::describe($keys, $rows);
        // Text dates ("06/10/2026") become timestamps, so every date filters and sorts by date.
        $rows = column_inspector::convert_text_dates($columns, $rows);
        foreach ($columns as $i => $column) {
            unset($columns[$i]['textdate']);
        }
        // HTML from the SQL (links...): cleaned for the table, plain text for everything else.
        [$columns, $rows] = column_inspector::html_cells($columns, $rows, $context);
        // Calculated columns (a formula over the columns of the row, chosen in the table header).
        [$columns, $rows] = formula::apply($columns, $rows, $levels[$level]['config']['calculated'] ?? []);
        $defaults = column_inspector::default_mapping($columns);
        $hasnext = isset($levels[$level + 1]) && trim($levels[$level + 1]['sqlquery']) !== '';
        if ($hasnext) {
            $defaults['valuecolumn'] = self::infer_value_column($columns, $defaults, $levels[$level + 1]['sqlquery']);
        }
        $mapping = column_inspector::merge_mapping($defaults, $levels[$level]['config'], $columns);

        $payload = [
            'level' => $level,
            'title' => (string) ($mapping['title'] ?? ($levels[$level]['label'] !== '' ? $levels[$level]['label'] : '')),
            'columns' => $columns,
            'rows' => $rows,
            'mapping' => $mapping,
            // Name columns shown as links to Moodle (users, courses, categories).
            'links' => get_config('local_reportium', 'autolinks') === '0' ? [] : column_inspector::links($columns),
            'hasnext' => $hasnext,
            // Time the level's query took (milliseconds), shown as "Execution time" under each table.
            'executionms' => $executionms,
            'cachemeta' => ['freshnessminutes' => $ttl, 'cached' => false, 'generatedat' => time()],
        ];
        if ($ttl > 0) {
            cache::make('local_reportium', 'reportdata')->set($cachekey, ['cachedat' => time(), 'payload' => $payload]);
        }
        return $payload;
    }

    /**
     * Apply what the user sees on screen to a level payload: search, automatic filters, chart-settings
     * overrides (sort, top N). Mirrors visibleRows() in amd/src/levels.js, so exports match the screen.
     *
     * @param array $payload output of get_level_payload()
     * @param string $search free-text search
     * @param array $filters [column => ['in' => [...]] | ['min' => x, 'max' => y]]
     * @param array $override chart settings override (sort, metrics)
     * @param string $columnsort table header sort "column:asc" or "column:desc", applied last (after top N)
     * @return array visible rows
     */
    public static function visible_rows(array $payload, string $search = '', array $filters = [], array $override = [],
            string $columnsort = ''): array {
        $columns = array_column($payload['columns'], null, 'key');
        $mapping = column_inspector::merge_mapping($payload['mapping'], $override, $payload['columns']);
        $query = \core_text::strtolower(trim($search));

        $rows = array_values(array_filter($payload['rows'], static function(array $row) use ($columns, $query, $filters): bool {
            if ($query !== '') {
                $match = false;
                foreach ($row as $value) {
                    if (strpos(\core_text::strtolower((string) $value), $query) !== false) {
                        $match = true;
                        break;
                    }
                }
                if (!$match) {
                    return false;
                }
            }
            foreach ($filters as $key => $filter) {
                if (!isset($columns[$key]) || !is_array($filter)) {
                    continue;
                }
                $value = $row[$key] ?? '';
                // The "Contains" filter (column funnel filter on text columns with many values).
                if (isset($filter['like']) && trim((string) $filter['like']) !== '') {
                    $needle = \core_text::strtolower(trim((string) $filter['like']));
                    if (strpos(\core_text::strtolower((string) $value), $needle) === false) {
                        return false;
                    }
                    continue;
                }
                if (!empty($filter['in']) && is_array($filter['in'])) {
                    if (!in_array((string) $value, array_map('strval', $filter['in']), true)) {
                        return false;
                    }
                    continue;
                }
                $number = (float) $value;
                if (isset($filter['min']) && $filter['min'] !== '' && !($number >= (float) $filter['min'])) {
                    return false;
                }
                if (isset($filter['max']) && $filter['max'] !== '' && !($number <= (float) $filter['max'])) {
                    return false;
                }
            }
            return true;
        }));

        [$sortkey, $sortdir] = array_pad(explode(':', $columnsort, 2), 2, '');
        if (isset($columns[$sortkey]) && in_array($sortdir, ['asc', 'desc'], true)) {
            $numeric = in_array($columns[$sortkey]['type'], ['number', 'date', 'id'], true);
            $direction = $sortdir === 'asc' ? 1 : -1;
            usort($rows, static function(array $a, array $b) use ($sortkey, $numeric, $direction): int {
                $x = $a[$sortkey] ?? '';
                $y = $b[$sortkey] ?? '';
                $cmp = $numeric ? ((float) $x <=> (float) $y)
                    : strcmp(\core_text::strtolower((string) $x), \core_text::strtolower((string) $y));
                return $direction * $cmp;
            });
        }
        return $rows;
    }

    /**
     * Pick which column is passed to the next level: an id when the next SQL compares %%PREVIOUSVALUE%% with an
     * id-like column (e.g. "ue.userid = %%PREVIOUSVALUE%%"), the label otherwise (e.g. "cc.name = %%PREVIOUSVALUE%%").
     *
     * @param array[] $columns
     * @param array $defaults
     * @param string $nextsql
     * @return string|null
     */
    public static function infer_value_column(array $columns, array $defaults, string $nextsql): ?string {
        $label = $defaults['labelcolumn'];
        if (!preg_match('/([\w.]+)\s*(?:=|IN\s*\()\s*%%PREVIOUSVALUE%%/i', $nextsql, $match)) {
            return $defaults['valuecolumn'];
        }
        $target = strtolower(preg_replace('/^.*\./', '', $match[1]));
        $wantsid = $target === 'id' || substr($target, -2) === 'id';
        if (!$wantsid) {
            return $label;
        }
        foreach ($columns as $column) {
            if ($column['type'] === column_inspector::TYPE_ID) {
                return $column['key'];
            }
        }
        return $defaults['valuecolumn'];
    }

    /**
     * SQL of the first drill level (what the report shows first), or '' when the report has none yet.
     *
     * @param int $reportid
     * @return string
     */
    public static function get_root_sql(int $reportid): string {
        global $DB;
        return (string) $DB->get_field(self::TABLE, 'sqlquery', ['reportid' => $reportid, 'drilllevel' => 0]);
    }

    /**
     * Write the SQL of the first drill level, creating it if the report has none yet (XML import, upgrade).
     *
     * @param int $reportid
     * @param string $sql
     * @return void
     */
    public static function save_root_sql(int $reportid, string $sql): void {
        global $DB, $USER;
        if (trim($sql) === '') {
            return;
        }
        $now = time();
        $userid = isset($USER->id) ? (int) $USER->id : 0;
        if ($level = $DB->get_record(self::TABLE, ['reportid' => $reportid, 'drilllevel' => 0])) {
            $level->sqlquery = $sql;
            $level->timemodified = $now;
            $level->usermodified = $userid;
            $DB->update_record(self::TABLE, $level);
        } else {
            $DB->insert_record(self::TABLE, (object) [
                'reportid' => $reportid,
                'drilllevel' => 0,
                'label' => '',
                'sqlquery' => $sql,
                'config' => '{}',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
        }
        cache::make('local_reportium', 'reportdata')->purge();
    }

    /**
     * Save the mapping/config of a level (who may do it is checked by the caller).
     *
     * @param int $reportid
     * @param int $level
     * @param array $config
     * @return void
     */
    public static function save_level_config(int $reportid, int $level, array $config): void {
        global $DB, $USER;
        $record = $DB->get_record(self::TABLE, ['reportid' => $reportid, 'drilllevel' => $level], '*', MUST_EXIST);
        $clean = [];
        foreach (['labelcolumn', 'valuecolumn', 'defaultview', 'title'] as $field) {
            if (isset($config[$field]) && is_scalar($config[$field])) {
                $clean[$field] = clean_param((string) $config[$field], PARAM_TEXT);
            }
        }
        if (isset($config['metrics']) && is_array($config['metrics'])) {
            $clean['metrics'] = self::clean_metrics($config['metrics']);
        }
        if (isset($config['pagesize'])) {
            $clean['pagesize'] = max(1, (int) $config['pagesize']);
        }
        // Chart types shown at this level.
        if (isset($config['viewtypes']) && is_array($config['viewtypes'])) {
            $viewtypes = array_values(array_intersect(column_inspector::views(), $config['viewtypes']));
            if ($viewtypes) {
                $clean['viewtypes'] = $viewtypes;
            }
        }
        // Own settings of each chart type (metrics, stacking).
        if (isset($config['views']) && is_array($config['views'])) {
            foreach ($config['views'] as $view => $settings) {
                if (!in_array($view, column_inspector::views(), true) || !is_array($settings)) {
                    continue;
                }
                if (isset($settings['metrics']) && is_array($settings['metrics'])) {
                    $clean['views'][$view]['metrics'] = self::clean_metrics($settings['metrics']);
                }
                if (isset($settings['stacked'])) {
                    $clean['views'][$view]['stacked'] = (bool) $settings['stacked'];
                }
            }
        }
        if (isset($config['stacked'])) {
            $clean['stacked'] = (bool) $config['stacked'];
        }
        if (isset($config['formats']) && is_array($config['formats'])) {
            foreach ($config['formats'] as $key => $format) {
                if (is_string($key) && in_array($format, instructions::format_names(), true)) {
                    $clean['formats'][clean_param($key, PARAM_TEXT)] = $format;
                }
            }
        }
        $record->config = json_encode($clean);
        $record->timemodified = time();
        $record->usermodified = (int) $USER->id;
        $DB->update_record(self::TABLE, $record);
    }

    /**
     * Run the SQL of a level with the drill path and the viewer bound as parameters.
     *
     * @param string $sql
     * @param string[] $path
     * @param array $vars viewer placeholders (user_vars())
     * @return array [string[] keys, array[] rows]
     */
    protected static function run_level(string $sql, array $path, array $vars = []): array {
        global $DB;
        [$sql, $params] = self::bind_placeholders($sql, $path, $vars);
        $limit = (int) get_config('local_reportium', 'reportlimit');
        if ($limit <= 0) {
            $limit = 5000;
        }
        try {
            // An SQL with its own LIMIT keeps it (the row limit of the plugin cannot be added to it).
            $rs = $DB->get_recordset_sql($sql, $params, 0, self::has_own_limit($sql) ? 0 : $limit);
        } catch (\dml_exception $e) {
            $detail = ($e instanceof \dml_read_exception && !empty($e->error)) ? $e->error : $e->getMessage();
            throw new moodle_exception('levelsqlerror', 'local_reportium', '', null, $detail);
        }
        $keys = [];
        $rows = [];
        foreach ($rs as $record) {
            $row = [];
            foreach ((array) $record as $key => $value) {
                $row[(string) $key] = $value === null ? '' : self::restore_literals((string) $value);
            }
            if (!$keys) {
                $keys = array_keys($row);
            }
            $rows[] = $row;
        }
        $rs->close();
        return [$keys, $rows];
    }

    /**
     * Viewer placeholders: who is looking at the report, and from which course.
     *
     * @param context $context context the report is viewed in
     * @param int $userid viewer
     * @return array ['userid' => int, 'courseid' => int, 'categoryid' => int, 'mycourses' => int[]|null]
     */
    public static function user_vars(context $context, int $userid): array {
        $courseid = SITEID;
        $categoryid = 0;
        if ($coursecontext = $context->get_course_context(false)) {
            $courseid = (int) $coursecontext->instanceid;
            $categoryid = (int) get_course($courseid)->category;
        }
        return [
            'userid' => $userid,
            'courseid' => $courseid,
            'categoryid' => $categoryid,
            'mycourses' => self::my_courses($userid),
        ];
    }

    /**
     * Courses of %%MYCOURSES%% ("the courses where I have a part"):
     * - the courses the user is enrolled in (any role: student, teacher...),
     * - the courses where the user holds a role without being enrolled (e.g. "Other users"),
     * - every course of the categories (and subcategories) where the user holds a role (category teacher/manager);
     * or null (= every course) for users who can see every course (site administrators, site managers).
     *
     * @param int $userid
     * @return int[]|null
     */
    public static function my_courses(int $userid): ?array {
        global $DB;
        if (!$userid) {
            return [];
        }
        if (has_capability('moodle/course:view', \context_system::instance(), $userid)) {
            return null;
        }
        $courses = array_map('intval', array_keys(enrol_get_users_courses($userid, true, 'id')));
        // Roles in a course context.
        $courses = array_merge($courses, array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT ctx.instanceid
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :courselevel
              WHERE ra.userid = :userid", ['courselevel' => CONTEXT_COURSE, 'userid' => $userid])));
        // Roles in a category context: every course of that category and its subcategories.
        $categories = $DB->get_records_sql(
            "SELECT DISTINCT cat.id, cat.path
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :catlevel
               JOIN {course_categories} cat ON cat.id = ctx.instanceid
              WHERE ra.userid = :userid", ['catlevel' => CONTEXT_COURSECAT, 'userid' => $userid]);
        foreach ($categories as $category) {
            $courses = array_merge($courses, array_map('intval', $DB->get_fieldset_sql(
                "SELECT c.id
                   FROM {course} c
                   JOIN {course_categories} cc ON cc.id = c.category
                  WHERE cc.id = :catid OR " . $DB->sql_like('cc.path', ':subpath'),
                ['catid' => $category->id, 'subpath' => $category->path . '/%'])));
        }
        return array_values(array_unique(array_diff($courses, [(int) SITEID])));
    }

    /**
     * Bind the placeholders of a drill level as named parameters and expand prefix_:
     * %%PREVIOUSVALUE%%, %%LEVELVALUE:n%%, %%NOW%%, %%WWWROOT%% and the viewer ones (%%USERID%%, %%COURSEID%%,
     * %%CATEGORYID%%, %%MYCOURSES%%) when $vars is given. %%STARTTIME%% / %%ENDTIME%% are the period chosen in the
     * report ($vars starttime / endtime; all time without them) and any other %%KEYWORD%% (e.g. %%FILTER_...%%) is
     * removed. See instructions.json.
     *
     * @param string $sql
     * @param string[] $path
     * @param array $vars viewer placeholders (user_vars())
     * @return array [string sql, array params]
     */
    public static function bind_placeholders(string $sql, array $path, array $vars = []): array {
        global $CFG;
        $params = [];
        $counter = 0;
        foreach (['USERID' => 'userid', 'COURSEID' => 'courseid', 'CATEGORYID' => 'categoryid'] as $keyword => $var) {
            if (!array_key_exists($var, $vars)) {
                continue;
            }
            $sql = preg_replace_callback('/%%' . $keyword . '%%/', static function() use (&$params, &$counter, $vars, $var) {
                $name = 'rpv' . $counter++;
                $params[$name] = (int) $vars[$var];
                return ':' . $name;
            }, $sql);
        }
        if (array_key_exists('mycourses', $vars)) {
            // Used as "c.id IN (%%MYCOURSES%%)": a list of bound ids, every course, or none.
            $sql = preg_replace_callback('/%%MYCOURSES%%/', static function() use (&$params, &$counter, $vars) {
                if ($vars['mycourses'] === null) {
                    return 'SELECT id FROM {course}';
                }
                if (!$vars['mycourses']) {
                    return '-1';
                }
                $names = [];
                foreach ($vars['mycourses'] as $courseid) {
                    $name = 'rpv' . $counter++;
                    $params[$name] = (int) $courseid;
                    $names[] = ':' . $name;
                }
                return implode(', ', $names);
            }, $sql);
        }
        $previous = $path ? (string) end($path) : '';
        $bind = static function(array $m) use (&$params, &$counter, $path, $previous): string {
            $name = 'rpv' . $counter++;
            $params[$name] = isset($m[1]) && $m[1] !== '' ? (string) ($path[(int) $m[1]] ?? '') : $previous;
            return ':' . $name;
        };
        $sql = preg_replace_callback('/%%PREVIOUSVALUE%%|%%LEVELVALUE:(\d+)%%/', $bind, $sql);
        // Current time (unix timestamp), e.g. to tell overdue activities apart.
        $sql = preg_replace_callback('/%%NOW%%/', static function() use (&$params, &$counter): string {
            $name = 'rpv' . $counter++;
            $params[$name] = time();
            return ':' . $name;
        }, $sql);
        // Site address, and the period of the data (all time when none is chosen).
        // The site address goes in as text: it is mostly written inside a quoted text of the SQL
        // ('<a href="%%WWWROOT%%/course/view.php?id=', ...), where a parameter cannot go. Outside quotes it becomes a
        // quoted text. It comes from config.php; quotes are doubled anyway.
        $sql = preg_replace_callback('/%%WWWROOT%%/', static function(array $m) use ($CFG, &$sql): string {
            return str_replace("'", "''", $CFG->wwwroot);
        }, self::quote_outside_literals($sql, '%%WWWROOT%%'));
        $period = ['STARTTIME' => (int) ($vars['starttime'] ?? 0),
            'ENDTIME' => (int) ($vars['endtime'] ?? date_range::ALL_END)];
        foreach ($period as $keyword => $value) {
            $sql = preg_replace_callback('/%%' . $keyword . '%%/', static function() use (&$params, &$counter, $value) {
                $name = 'rpv' . $counter++;
                $params[$name] = $value;
                return ':' . $name;
            }, $sql);
        }
        // Keywords without a value yet (%%FILTER_...%%, %%DEBUG%%) are removed.
        $sql = preg_replace('/%%[A-Za-z][A-Za-z0-9_]*(:[^%]*)?%%/', '', $sql);
        $sql = preg_replace('/\bprefix_(?=\w+)/i', $CFG->prefix, $sql);
        // Inside the texts of the SQL, "?" ('.../view.php?id=') and ":name" ('%H:%i', 'javascript:x') would be taken
        // for parameters by the database layer: they run as markers and come back in the results (run_level()).
        $sql = self::protect_literals($sql);
        return [$sql, $params];
    }

    /**
     * Whether an SQL query ends with its own LIMIT (LIMIT n, LIMIT n OFFSET m, LIMIT m, n).
     *
     * @param string $sql
     * @return bool
     */
    public static function has_own_limit(string $sql): bool {
        return (bool) preg_match('/\bLIMIT\s+(:\w+|\d+)\s*((,|OFFSET)\s*(:\w+|\d+))?\s*$/i', trim($sql));
    }

    /**
     * The texts of an SQL query with their "?" and ":name" as markers, so the database layer does not take them for
     * parameters (the parameters of Reportium are named and outside texts). Results get them back with
     * restore_literals().
     *
     * @param string $sql
     * @return string
     */
    public static function protect_literals(string $sql): string {
        $out = '';
        $inside = false;
        $length = strlen($sql);
        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            if ($char === "'") {
                if ($inside && ($sql[$i + 1] ?? '') === "'") {
                    $out .= "''";
                    $i++;
                    continue;
                }
                $inside = !$inside;
                $out .= $char;
                continue;
            }
            if ($inside && $char === '?') {
                $out .= self::QUESTIONMARK;
            } else if ($inside && $char === ':' && preg_match('/[a-z_]/i', $sql[$i + 1] ?? '')) {
                $out .= self::COLON;
            } else {
                $out .= $char;
            }
        }
        return $out;
    }

    /**
     * A value read from the database with the "?" and ":" of the texts of its SQL back.
     *
     * @param string $value
     * @return string
     */
    public static function restore_literals(string $value): string {
        return str_replace([self::QUESTIONMARK, self::COLON], ['?', ':'], $value);
    }

    /**
     * Put quotes around a keyword wherever it is not already inside a quoted text of the SQL, so that replacing it
     * with plain text always gives valid SQL.
     *
     * @param string $sql
     * @param string $keyword
     * @return string
     */
    protected static function quote_outside_literals(string $sql, string $keyword): string {
        $out = '';
        $inside = false;
        $length = strlen($sql);
        $klength = strlen($keyword);
        for ($i = 0; $i < $length; $i++) {
            if (!$inside && substr($sql, $i, $klength) === $keyword) {
                $out .= "'" . $keyword . "'";
                $i += $klength - 1;
                continue;
            }
            if ($sql[$i] === "'") {
                // A doubled quote inside a text is a quote, not the end of the text.
                if ($inside && ($sql[$i + 1] ?? '') === "'") {
                    $out .= "''";
                    $i++;
                    continue;
                }
                $inside = !$inside;
            }
            $out .= $sql[$i];
        }
        return $out;
    }

    /**
     * Metric column names of saved settings: text values only.
     *
     * @param array $metrics
     * @return string[]
     */
    protected static function clean_metrics(array $metrics): array {
        return array_values(array_map(static function($metric): string {
            return clean_param((string) $metric, PARAM_TEXT);
        }, array_filter($metrics, 'is_scalar')));
    }

    /**
     * Decode a stored config JSON safely.
     *
     * @param string|null $json
     * @return array
     */
    protected static function decode_config(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Signature of the upstream request filters, so cached root payloads vary with them.
     *
     * @return string
     */
    protected static function request_filters_signature(): string {
        $filters = [];
        foreach (array_merge($_GET, $_POST) as $key => $value) {
            if (strpos((string) $key, 'filter_') === 0) {
                $filters[$key] = $value;
            }
        }
        ksort($filters);
        return sha1(json_encode($filters));
    }
}
