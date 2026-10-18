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
 * History of the SQL of levels: every save, delete and restore keeps a version, and fires a Moodle event (site logs).
 *
 * A deleted level can be restored while it would again be the deepest one (level = deepest + 1).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sql_history {
    /** @var string Table. */
    const TABLE = 'local_reportium_version';

    /** @var int Versions kept per level (the oldest ones are removed). */
    const KEEP = 50;

    /**
     * Keep a version of a level and log the change.
     *
     * @param stdClass $report
     * @param int $level
     * @param string $label
     * @param string $sql
     * @param string $action saved, deleted or restored
     * @param int $userid
     * @return int version id
     */
    public static function record(stdClass $report, int $level, string $label, string $sql, string $action, int $userid): int {
        global $DB;
        $id = (int) $DB->insert_record(self::TABLE, (object) [
            'reportid' => (int) $report->id,
            'drilllevel' => $level,
            'label' => $label,
            'sqlquery' => $sql,
            'action' => $action,
            'timecreated' => time(),
            'usermodified' => $userid,
        ]);
        // Only the newest versions of the level are kept.
        $old = $DB->get_fieldset_sql('SELECT id FROM {' . self::TABLE . '} WHERE reportid = ? AND drilllevel = ?
            ORDER BY timecreated DESC, id DESC', [(int) $report->id, $level]);
        if (count($old) > self::KEEP) {
            [$insql, $params] = $DB->get_in_or_equal(array_slice($old, self::KEEP));
            $DB->delete_records_select(self::TABLE, "id $insql", $params);
        }
        $class = '\\local_reportium\\event\\level_' . $action;
        $class::create([
            'context' => report_roles::report_context($report),
            'objectid' => (int) $report->id,
            'other' => ['level' => $level, 'versionid' => $id],
        ])->trigger();
        return $id;
    }

    /**
     * Versions of a level, newest first, with the name of who made them.
     *
     * @param int $reportid
     * @param int $level
     * @return array[] [id, level, label, sqlquery, action, timecreated, userid, userfullname]
     */
    public static function versions(int $reportid, int $level): array {
        global $DB;
        $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        $records = $DB->get_records_sql("SELECT v.*, $userfields
                                           FROM {" . self::TABLE . "} v
                                      LEFT JOIN {user} u ON u.id = v.usermodified
                                          WHERE v.reportid = ? AND v.drilllevel = ?
                                       ORDER BY v.timecreated DESC, v.id DESC", [$reportid, $level]);
        $versions = [];
        foreach ($records as $record) {
            $versions[] = [
                'id' => (int) $record->id,
                'level' => (int) $record->drilllevel,
                'label' => (string) $record->label,
                'sqlquery' => (string) $record->sqlquery,
                'action' => (string) $record->action,
                'timecreated' => (int) $record->timecreated,
                'userid' => (int) $record->usermodified,
                'userfullname' => $record->usermodified ? fullname($record) : '',
            ];
        }
        return $versions;
    }

    /**
     * Put a version back: as the SQL of its level, or as a new deepest level when the level was deleted.
     *
     * @param stdClass $report
     * @param int $versionid
     * @param int $userid
     * @return array [level, error] error is '' on success (the SQL is validated like a save)
     */
    public static function restore(stdClass $report, int $versionid, int $userid): array {
        global $DB;
        $version = $DB->get_record(self::TABLE, ['id' => $versionid, 'reportid' => (int) $report->id], '*', MUST_EXIST);
        $level = (int) $version->drilllevel;
        $error = report_data_helper::validate_level_sql($report, $level, (string) $version->sqlquery);
        if ($error !== null) {
            return [$level, $error];
        }
        $now = time();
        $existing = $DB->get_record(level_service::TABLE, ['reportid' => $report->id, 'drilllevel' => $level]);
        if ($existing) {
            $existing->sqlquery = $version->sqlquery;
            $existing->label = (string) $version->label;
            $existing->timemodified = $now;
            $existing->usermodified = $userid;
            $DB->update_record(level_service::TABLE, $existing);
        } else {
            $deepest = $DB->get_field_sql(
                'SELECT MAX(drilllevel) FROM {' . level_service::TABLE . '} WHERE reportid = ?',
                [$report->id]
            );
            $next = $deepest === null || $deepest === false ? 0 : (int) $deepest + 1;
            if ($level !== $next) {
                throw new moodle_exception('cannotrestorelevel', 'local_reportium', '', $level + 1);
            }
            $DB->insert_record(level_service::TABLE, (object) [
                'reportid' => $report->id,
                'drilllevel' => $level,
                'label' => (string) $version->label,
                'sqlquery' => $version->sqlquery,
                'config' => '{}',
                'timecreated' => $now,
                'timemodified' => $now,
                'usermodified' => $userid,
            ]);
        }
        \cache::make('local_reportium', 'reportdata')->purge();
        self::record($report, $level, (string) $version->label, (string) $version->sqlquery, 'restored', $userid);
        return [$level, ''];
    }

    /**
     * Forget the history of a report (when it is deleted).
     *
     * @param int $reportid
     * @return void
     */
    public static function delete_report(int $reportid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['reportid' => $reportid]);
    }
}
