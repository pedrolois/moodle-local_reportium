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

use context;
use stdClass;

/**
 * What other plugins add to Reportium.
 *
 * An assistant in the SQL Inspector: a plugin with the callback {component}_reportium_sql_assistant($report, $context,
 * $courseid) in its lib.php, returning ['module' => AMD module, 'config' => array] (or null). The module's default export
 * is a class built with (config, onUseSql(sql), getLevel()) that has an "element" to show.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extensions {

    /** @var string Callback of an assistant in the SQL Inspector. */
    const SQL_ASSISTANT = 'reportium_sql_assistant';

    /**
     * Whether a plugin offers an assistant in the SQL Inspector (e.g. Reportium Pro).
     *
     * @return bool
     */
    public static function has_sql_assistant(): bool {
        return (bool) get_plugins_with_function(self::SQL_ASSISTANT);
    }

    /**
     * The assistant of the SQL Inspector of a report for the current user, if a plugin offers one.
     *
     * @param stdClass $report
     * @param context $context
     * @param int $courseid
     * @return array|null ['module', 'config']
     */
    public static function sql_assistant(stdClass $report, context $context, int $courseid): ?array {
        foreach (get_plugins_with_function(self::SQL_ASSISTANT) as $plugins) {
            foreach ($plugins as $function) {
                $assistant = $function($report, $context, $courseid);
                if (is_array($assistant) && !empty($assistant['module'])
                        && preg_match('/^[a-z][a-z0-9_]*\/[a-z0-9_]+$/', (string) $assistant['module'])) {
                    return ['module' => (string) $assistant['module'], 'config' => (array) ($assistant['config'] ?? [])];
                }
            }
        }
        return null;
    }
}
