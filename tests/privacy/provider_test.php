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

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests of the privacy provider: metadata, the contexts and users with data, export and deletion.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass author of a report */
    private $author;

    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->author = $this->getDataGenerator()->create_user();
        $this->setUser($this->author);
        $gen = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $this->report = $gen->create_demo_report();
        $DB->set_field('local_reportium', 'ownerid', $this->author->id, ['id' => $this->report->id]);
        $DB->set_field('local_reportium_level', 'usermodified', $this->author->id, ['reportid' => $this->report->id]);
        \local_reportium\local\report_roles::set(
            (int) $this->report->id,
            [(int) $DB->get_field('role', 'id', ['shortname' => 'manager'])]
        );
    }

    public function test_metadata(): void {
        $items = provider::get_metadata(new \core_privacy\local\metadata\collection('local_reportium'))->get_collection();
        $names = array_map(function ($item) {
            return $item->get_name();
        }, $items);
        $this->assertContains('local_reportium', $names);
        $this->assertContains('local_reportium_level', $names);
        $this->assertContains('local_reportium_role', $names);
        $this->assertContains('local_reportium_version', $names);
        $this->assertContains('local_reportium_schedule', $names);
        $this->assertContains('email', $names);
    }

    public function test_contexts_and_users(): void {
        $context = \context_user::instance($this->author->id);
        $this->assertSame(
            [$context->id],
            array_map('intval', provider::get_contexts_for_userid((int) $this->author->id)->get_contextids())
        );
        $other = $this->getDataGenerator()->create_user();
        $this->assertSame([], provider::get_contexts_for_userid((int) $other->id)->get_contextids());

        $userlist = new userlist($context, 'local_reportium');
        provider::get_users_in_context($userlist);
        $this->assertSame([(int) $this->author->id], array_map('intval', $userlist->get_userids()));
        $empty = new userlist(\context_system::instance(), 'local_reportium');
        provider::get_users_in_context($empty);
        $this->assertSame([], $empty->get_userids());
    }

    public function test_export(): void {
        $context = \context_user::instance($this->author->id);
        $this->export_context_data_for_user((int) $this->author->id, $context, 'local_reportium');
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('pluginname', 'local_reportium')]);
        $this->assertSame('Demo drill report', $data->reports[0]->name);
        $this->assertCount(3, $data->levels);
        $this->assertSame(1, $data->levels[0]->level);
        $this->assertStringContainsString('SELECT', $data->levels[0]->sqlquery);
        $this->assertCount(1, $data->roles);
    }

    public function test_delete_keeps_the_reports_without_the_user(): void {
        global $DB;
        $context = \context_user::instance($this->author->id);
        provider::delete_data_for_user(new approved_contextlist($this->author, 'local_reportium', [$context->id]));
        $this->assertTrue($DB->record_exists('local_reportium', ['id' => $this->report->id, 'ownerid' => 0]));
        $this->assertFalse($DB->record_exists('local_reportium_level', ['usermodified' => $this->author->id]));
        $this->assertFalse($DB->record_exists('local_reportium_role', ['usermodified' => $this->author->id]));
        $this->assertSame(3, $DB->count_records('local_reportium_level', ['reportid' => $this->report->id]));
        $this->assertSame([], provider::get_contexts_for_userid((int) $this->author->id)->get_contextids());
    }

    public function test_delete_for_users_and_for_context(): void {
        global $DB;
        $context = \context_user::instance($this->author->id);
        // Another context: nothing happens.
        provider::delete_data_for_all_users_in_context(\context_system::instance());
        $this->assertTrue($DB->record_exists('local_reportium', ['ownerid' => $this->author->id]));

        provider::delete_data_for_users(new approved_userlist($context, 'local_reportium', [(int) $this->author->id]));
        $this->assertFalse($DB->record_exists('local_reportium', ['ownerid' => $this->author->id]));

        $DB->set_field('local_reportium', 'ownerid', $this->author->id, ['id' => $this->report->id]);
        provider::delete_data_for_all_users_in_context($context);
        $this->assertFalse($DB->record_exists('local_reportium', ['ownerid' => $this->author->id]));
    }
    public function test_versions_and_schedules_are_exported_and_deleted(): void {
        global $DB;
        \local_reportium\local\sql_history::record($this->report, 0, '', 'SELECT 1 AS id', 'saved', (int) $this->author->id);
        $recipient = $this->getDataGenerator()->create_user();
        $scheduleid = \local_reportium\local\scheduler::save((object) ['reportid' => $this->report->id,
            'userid' => $this->author->id, 'frequency' => 'weekly', 'weekday' => 1, 'monthday' => 1, 'hour' => 8,
            'format' => 'csv', 'viewstate' => '{}', 'daterange' => '', 'subject' => 'Weekly', 'message' => '', 'enabled' => 1,
            'recipients' => json_encode(['users' => [(int) $recipient->id], 'emails' => ['x@example.org']])]);

        $context = \context_user::instance($this->author->id);
        $this->export_context_data_for_user((int) $this->author->id, $context, 'local_reportium');
        $data = writer::with_context($context)->get_data([get_string('pluginname', 'local_reportium')]);
        $this->assertSame('SELECT 1 AS id', $data->versions[0]->sqlquery);
        $this->assertSame('Weekly', $data->schedules[0]->subject);

        // A recipient has data too (the schedules they receive).
        $recipientcontext = \context_user::instance($recipient->id);
        $this->assertSame(
            [$recipientcontext->id],
            array_map('intval', provider::get_contexts_for_userid((int) $recipient->id)->get_contextids())
        );
        writer::reset();
        $this->export_context_data_for_user((int) $recipient->id, $recipientcontext, 'local_reportium');
        $received = writer::with_context($recipientcontext)->get_data([get_string('pluginname', 'local_reportium')]);
        $this->assertSame(transform::yesno(true), $received->schedules[0]->youreceiveit);

        // Deleting the recipient: out of the list. Deleting the author: their schedule goes, their versions are anonymised.
        provider::delete_data_for_user(new approved_contextlist($recipient, 'local_reportium', [$recipientcontext->id]));
        $this->assertSame([], \local_reportium\local\scheduler::recipient_lists(
            $DB->get_record('local_reportium_schedule', ['id' => $scheduleid])
        )['users']);
        provider::delete_data_for_user(new approved_contextlist($this->author, 'local_reportium', [$context->id]));
        $this->assertFalse($DB->record_exists('local_reportium_schedule', ['id' => $scheduleid]));
        $this->assertFalse($DB->record_exists('local_reportium_version', ['usermodified' => $this->author->id]));
    }

    public function test_column_order_preferences_are_declared_and_exported(): void {
        $names = array_map(
            fn($item) => $item->get_name(),
            provider::get_metadata(new \core_privacy\local\metadata\collection('local_reportium'))->get_collection()
        );
        $this->assertContains('local_reportium_columns', $names);
        set_user_preference('local_reportium_columns_5', '{"0":["b","a"]}', $this->author);
        set_user_preference('local_reportium_columns_9', '{"1":["x"]}', $this->author);
        set_user_preference('local_reportium_columns_5', '{"0":["other"]}', $this->getDataGenerator()->create_user());
        provider::export_user_preferences($this->author->id);
        $prefs = writer::with_context(\context_system::instance())->get_user_preferences('local_reportium');
        $this->assertSame('{"0":["b","a"]}', $prefs->local_reportium_columns_5->value);
        $this->assertSame('{"1":["x"]}', $prefs->local_reportium_columns_9->value);
    }
}
