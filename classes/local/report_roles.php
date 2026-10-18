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
 * Access to reports: the roles each report is shared with (Reports manager "Roles" column) and the capabilities
 * of Reportium (db/access.php).
 *
 * A user sees a report when they hold local/reportium:view and one of its roles: in the course (or above) for a
 * course report, at site or category level for a site report. Those who can edit the report always see it.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_roles {

    /** Table. */
    const TABLE = 'local_reportium_role';

    /**
     * Context a report belongs to.
     *
     * @param stdClass $report
     * @return context
     */
    public static function report_context(stdClass $report): context {
        return (int) $report->courseid === (int) SITEID
            ? \context_system::instance()
            : \context_course::instance($report->courseid);
    }

    /**
     * Roles that can be chosen: every role except guest, with its localised name.
     *
     * @param context $context
     * @return string[] roleid => name
     */
    public static function options(context $context): array {
        $options = [];
        foreach (role_fix_names(get_all_roles(), $context, ROLENAME_ALIAS) as $role) {
            if ($role->archetype !== 'guest') {
                $options[(int) $role->id] = $role->localname;
            }
        }
        return $options;
    }

    /**
     * Role ids of a report.
     *
     * @param int $reportid
     * @return int[]
     */
    public static function get(int $reportid): array {
        global $DB;
        return array_map('intval', $DB->get_fieldset_select(self::TABLE, 'roleid', 'reportid = ?', [$reportid]));
    }

    /**
     * Role ids of several reports at once.
     *
     * @param int[] $reportids
     * @return array reportid => int[] role ids
     */
    public static function get_many(array $reportids): array {
        global $DB;
        $result = array_fill_keys($reportids, []);
        if (!$reportids) {
            return $result;
        }
        [$insql, $params] = $DB->get_in_or_equal($reportids);
        foreach ($DB->get_records_select(self::TABLE, "reportid $insql", $params, '', 'id, reportid, roleid') as $row) {
            $result[(int) $row->reportid][] = (int) $row->roleid;
        }
        return $result;
    }

    /**
     * Replace the roles of a report.
     *
     * @param int $reportid
     * @param int[] $roleids
     * @return void
     */
    public static function set(int $reportid, array $roleids): void {
        global $DB, $USER;
        $roleids = array_values(array_unique(array_filter(array_map('intval', $roleids))));
        if ($roleids) {
            // Only roles that exist.
            [$insql, $params] = $DB->get_in_or_equal($roleids);
            $roleids = array_values(array_intersect($roleids, array_map('intval',
                $DB->get_fieldset_select('role', 'id', "id $insql", $params))));
        }
        $current = self::get($reportid);
        foreach (array_diff($current, $roleids) as $roleid) {
            $DB->delete_records(self::TABLE, ['reportid' => $reportid, 'roleid' => $roleid]);
        }
        foreach (array_diff($roleids, $current) as $roleid) {
            $DB->insert_record(self::TABLE, (object) ['reportid' => $reportid, 'roleid' => $roleid, 'timecreated' => time(),
                'usermodified' => (int) $USER->id]);
        }
    }

    /**
     * Default roles of a new report: Manager (managers see everything first, then they choose who else).
     *
     * @return int[]
     */
    public static function default_roles(): array {
        global $DB;
        return array_map('intval', array_keys($DB->get_records('role', ['archetype' => 'manager'], '', 'id')));
    }

    /**
     * Give a report the default roles if it has none yet.
     *
     * @param int $reportid
     * @return void
     */
    public static function set_defaults(int $reportid): void {
        if (!self::get($reportid)) {
            self::set($reportid, self::default_roles());
        }
    }

    /**
     * Whether a user holds one of the given roles where the report lives: in the course (or above) for a course
     * report; at site or category level for a site report (a role held only inside some course does not count).
     * "Authenticated user" is held by everybody who is logged in.
     *
     * @param stdClass $report
     * @param int[] $roleids
     * @param int $userid
     * @return bool
     */
    public static function user_has_role(stdClass $report, array $roleids, int $userid): bool {
        global $CFG, $DB;
        if (!$roleids || !$userid) {
            return false;
        }
        if (!empty($CFG->defaultuserroleid) && in_array((int) $CFG->defaultuserroleid, $roleids, true) && !isguestuser($userid)) {
            return true;
        }
        if ((int) $report->courseid === (int) SITEID) {
            [$insql, $params] = $DB->get_in_or_equal($roleids);
            return $DB->record_exists_sql("SELECT 1
                                             FROM {role_assignments} ra
                                             JOIN {context} ctx ON ctx.id = ra.contextid
                                            WHERE ra.userid = ? AND ra.roleid $insql AND ctx.contextlevel IN (?, ?)",
                array_merge([$userid], $params, [CONTEXT_SYSTEM, CONTEXT_COURSECAT]));
        }
        foreach (get_user_roles(\context_course::instance($report->courseid), $userid, true) as $role) {
            if (in_array((int) $role->roleid, $roleids, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the user may edit a report (settings, chart types, hide, delete): local/reportium:manage, or the owner
     * with local/reportium:create.
     *
     * @param stdClass $report
     * @param int|null $userid default the current user
     * @return bool
     */
    public static function can_edit(stdClass $report, ?int $userid = null): bool {
        global $USER;
        $userid = $userid ?? (int) $USER->id;
        $context = self::report_context($report);
        return has_capability('local/reportium:manage', $context, $userid)
            || ((int) $report->ownerid === $userid && has_capability('local/reportium:create', $context, $userid));
    }

    /**
     * Whether the user may choose the roles a report is shared with: they can edit it, and local/reportium:share.
     *
     * @param stdClass $report
     * @return bool
     */
    public static function can_share(stdClass $report): bool {
        return self::can_edit($report) && has_capability('local/reportium:share', self::report_context($report));
    }

    /**
     * Whether the user may write SQL in a context (local/reportium:writesql).
     *
     * @param context $context
     * @return bool
     */
    public static function can_manage_sql(context $context): bool {
        return has_capability('local/reportium:writesql', $context);
    }

    /**
     * Whether the user may write the SQL of this report: they can edit it, and write SQL.
     *
     * @param stdClass $report
     * @param context $context context the report runs in
     * @return bool
     */
    public static function can_write_sql(stdClass $report, context $context): bool {
        return self::can_edit($report) && self::can_manage_sql($context);
    }

    /**
     * Whether the user may open the Reports manager of a context (it lists what the user can open or manage).
     *
     * @param context $context
     * @return bool
     */
    public static function can_use_manager(context $context): bool {
        return has_any_capability(['local/reportium:view', 'local/reportium:create', 'local/reportium:manage'], $context);
    }

    /**
     * Whether a user may view a report in a context. Those who can edit it always may. Others need the report to be
     * visible, local/reportium:view, and one of the roles the report is shared with (a report shared with nobody
     * is seen only by those who manage it).
     *
     * @param stdClass $report
     * @param int $userid
     * @param context $context context the report is viewed in
     * @return bool
     */
    public static function can_view(stdClass $report, int $userid, context $context): bool {
        if (has_capability('local/reportium:manage', $context, $userid)
                || ((int) $report->ownerid === $userid && has_capability('local/reportium:create', $context, $userid))) {
            return true;
        }
        if (empty($report->visible) || !has_capability('local/reportium:view', $context, $userid)) {
            return false;
        }
        return self::user_has_role($report, self::get((int) $report->id), $userid);
    }

    /**
     * Whether the user may download and print the data of the reports of a context (local/reportium:download).
     *
     * @param context $context
     * @return bool
     */
    public static function can_download(context $context): bool {
        return has_capability('local/reportium:download', $context);
    }
}
