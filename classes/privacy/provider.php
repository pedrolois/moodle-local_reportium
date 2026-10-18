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

namespace local_reportium\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy of Reportium: the reports a user created, and the levels and roles a user changed last.
 *
 * Reports belong to the site, not to the person who wrote them: when a user's data is deleted, their reports stay
 * and the user is removed from them (owner and "modified by" become 0).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\user_preference_provider {
    /**
     * What Reportium stores about users, and where data goes.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_reportium', [
            'courseid' => 'privacy:metadata:local_reportium:courseid',
            'ownerid' => 'privacy:metadata:local_reportium:ownerid',
            'visible' => 'privacy:metadata:local_reportium:visible',
            'global' => 'privacy:metadata:local_reportium:global',
            'name' => 'privacy:metadata:local_reportium:name',
            'summary' => 'privacy:metadata:local_reportium:summary',
        ], 'privacy:metadata:local_reportium');
        $collection->add_database_table('local_reportium_level', [
            'reportid' => 'privacy:metadata:local_reportium_level:reportid',
            'sqlquery' => 'privacy:metadata:local_reportium_level:sqlquery',
            'usermodified' => 'privacy:metadata:usermodified',
            'timemodified' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_reportium_level');
        $collection->add_database_table('local_reportium_role', [
            'reportid' => 'privacy:metadata:local_reportium_role:reportid',
            'roleid' => 'privacy:metadata:local_reportium_role:roleid',
            'usermodified' => 'privacy:metadata:usermodified',
        ], 'privacy:metadata:local_reportium_role');
        $collection->add_database_table('local_reportium_version', [
            'reportid' => 'privacy:metadata:local_reportium_level:reportid',
            'sqlquery' => 'privacy:metadata:local_reportium_level:sqlquery',
            'usermodified' => 'privacy:metadata:usermodified',
            'timecreated' => 'privacy:metadata:timemodified',
        ], 'privacy:metadata:local_reportium_version');
        $collection->add_user_preference('local_reportium_columns', 'privacy:metadata:preference:columns');
        $collection->add_database_table('local_reportium_schedule', [
            'reportid' => 'privacy:metadata:local_reportium_level:reportid',
            'userid' => 'privacy:metadata:local_reportium_schedule:userid',
            'recipients' => 'privacy:metadata:local_reportium_schedule:recipients',
            'subject' => 'privacy:metadata:local_reportium_schedule:subject',
            'message' => 'privacy:metadata:local_reportium_schedule:message',
        ], 'privacy:metadata:local_reportium_schedule');
        // Scheduled reports are emailed to their recipients, external addresses included.
        $collection->add_external_location_link('email', [
            'email' => 'privacy:metadata:email:email',
            'reportdata' => 'privacy:metadata:email:reportdata',
        ], 'privacy:metadata:email');
        // The AI assistant sends the report SQL and the user's messages through Moodle's AI subsystem.
        return $collection;
    }

    /**
     * The user context of a user who created a report or changed a level or the roles of a report.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        if (self::has_data($userid)) {
            $contextlist->add_user_context($userid);
        }
        return $contextlist;
    }

    /**
     * Users with data in a user context: the user themself, when they have data.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && self::has_data((int) $context->instanceid)) {
            $userlist->add_user((int) $context->instanceid);
        }
    }

    /**
     * Export the reports the user created, and the levels and report roles they changed.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        $context = \context_user::instance($user->id);
        if (!in_array($context->id, $contextlist->get_contextids())) {
            return;
        }
        $reports = [];
        foreach ($DB->get_records('local_reportium', ['ownerid' => $user->id], 'id') as $report) {
            $reports[] = (object) [
                'name' => format_string($report->name, true),
                'summary' => format_text($report->summary, $report->summaryformat),
                'course' => format_string((string) $DB->get_field('course', 'fullname', ['id' => $report->courseid]), true),
                'visible' => transform::yesno($report->visible),
                'global' => transform::yesno($report->global),
            ];
        }
        $levels = [];
        foreach ($DB->get_records('local_reportium_level', ['usermodified' => $user->id], 'reportid, drilllevel') as $level) {
            $levels[] = (object) [
                'report' => format_string((string) $DB->get_field('local_reportium', 'name', ['id' => $level->reportid]), true),
                'level' => $level->drilllevel + 1,
                'sqlquery' => $level->sqlquery,
                'timemodified' => transform::datetime($level->timemodified),
            ];
        }
        $roles = [];
        foreach ($DB->get_records('local_reportium_role', ['usermodified' => $user->id], 'reportid') as $role) {
            $roles[] = (object) [
                'report' => format_string((string) $DB->get_field('local_reportium', 'name', ['id' => $role->reportid]), true),
                'role' => role_get_name($DB->get_record('role', ['id' => $role->roleid]), \context_system::instance()),
                'timecreated' => transform::datetime($role->timecreated),
            ];
        }
        $versions = [];
        foreach ($DB->get_records('local_reportium_version', ['usermodified' => $user->id], 'timecreated') as $version) {
            $versions[] = (object) [
                'report' => format_string((string) $DB->get_field('local_reportium', 'name', ['id' => $version->reportid]), true),
                'level' => $version->drilllevel + 1,
                'action' => $version->action,
                'sqlquery' => $version->sqlquery,
                'timecreated' => transform::datetime($version->timecreated),
            ];
        }
        $schedules = [];
        foreach ($DB->get_records('local_reportium_schedule', null, 'id') as $schedule) {
            $lists = \local_reportium\local\scheduler::recipient_lists($schedule);
            $recipient = in_array((int) $user->id, $lists['users'], true);
            if ((int) $schedule->userid !== (int) $user->id && !$recipient) {
                continue;
            }
            $schedules[] = (object) [
                'report' => format_string((string) $DB->get_field('local_reportium', 'name', ['id' => $schedule->reportid]), true),
                'createdbyyou' => transform::yesno((int) $schedule->userid === (int) $user->id),
                'youreceiveit' => transform::yesno($recipient),
                'frequency' => $schedule->frequency,
                'subject' => $schedule->subject,
            ];
        }
        if ($reports || $levels || $roles || $versions || $schedules) {
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_reportium')],
                (object) ['reports' => $reports, 'levels' => $levels, 'roles' => $roles, 'versions' => $versions,
                'schedules' => $schedules]
            );
        }
    }

    /**
     * Delete the data of every user in a context (only user contexts hold Reportium data).
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context instanceof \context_user) {
            self::delete_user((int) $context->instanceid);
        }
    }

    /**
     * Delete the data of some users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $context = $userlist->get_context();
        if ($context instanceof \context_user && in_array((int) $context->instanceid, $userlist->get_userids())) {
            self::delete_user((int) $context->instanceid);
        }
    }

    /**
     * Delete the data of a user.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int) $contextlist->get_user()->id;
        if (in_array(\context_user::instance($userid)->id, $contextlist->get_contextids())) {
            self::delete_user($userid);
        }
    }

    /**
     * Whether a user created a report or changed a level or the roles of a report.
     *
     * @param int $userid
     * @return bool
     */
    protected static function has_data(int $userid): bool {
        global $DB;
        return $userid && ($DB->record_exists('local_reportium', ['ownerid' => $userid])
            || $DB->record_exists('local_reportium_level', ['usermodified' => $userid])
            || $DB->record_exists('local_reportium_role', ['usermodified' => $userid])
            || $DB->record_exists('local_reportium_version', ['usermodified' => $userid])
            || $DB->record_exists('local_reportium_schedule', ['userid' => $userid])
            || self::schedules_sent_to($userid));
    }

    /**
     * Remove a user from the reports, levels and roles (the reports stay: they belong to the site).
     *
     * @param int $userid
     */
    protected static function delete_user(int $userid): void {
        global $DB;
        $DB->set_field('local_reportium', 'ownerid', 0, ['ownerid' => $userid]);
        $DB->set_field('local_reportium_level', 'usermodified', 0, ['usermodified' => $userid]);
        $DB->set_field('local_reportium_role', 'usermodified', 0, ['usermodified' => $userid]);
        $DB->set_field('local_reportium_version', 'usermodified', 0, ['usermodified' => $userid]);
        // Their schedules ran with their access: they go. Other schedules stop sending to them.
        foreach ($DB->get_fieldset_select('local_reportium_schedule', 'id', 'userid = ?', [$userid]) as $scheduleid) {
            \local_reportium\local\scheduler::delete((int) $scheduleid);
        }
        foreach (self::schedules_sent_to($userid) as $schedule) {
            $lists = \local_reportium\local\scheduler::recipient_lists($schedule);
            $lists['users'] = array_values(array_diff($lists['users'], [$userid]));
            $DB->set_field('local_reportium_schedule', 'recipients', json_encode($lists), ['id' => $schedule->id]);
        }
    }

    /**
     * Schedules that name a user among their recipients.
     *
     * @param int $userid
     * @return \stdClass[]
     */
    protected static function schedules_sent_to(int $userid): array {
        global $DB;
        return array_filter($DB->get_records('local_reportium_schedule'), static fn($schedule): bool =>
            in_array($userid, \local_reportium\local\scheduler::recipient_lists($schedule)['users'], true));
    }

    /**
     * The column order of each report the user dragged in its table.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid) {
        global $DB;
        $prefix = \local_reportium\local\exporter::ORDER_PREFERENCE;
        $records = $DB->get_records_select(
            'user_preferences',
            'userid = ? AND ' . $DB->sql_like('name', '?'),
            [$userid, $DB->sql_like_escape($prefix) . '%']
        );
        foreach ($records as $record) {
            writer::export_user_preference(
                'local_reportium',
                $record->name,
                $record->value,
                get_string('privacy:metadata:preference:columns', 'local_reportium')
            );
        }
    }
}
