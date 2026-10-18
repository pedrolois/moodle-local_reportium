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
 * Tests of scheduled email delivery: next runs, recipients, the delivery and its log, the task, and access.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\scheduler
 * @covers     \local_reportium\task\send_scheduled
 */
final class scheduler_test extends \advanced_testcase {

    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $this->report = $generator->create_report(['name' => 'Weekly report']);
        $generator->create_level($this->report->id, 0,
            "SELECT 1 AS id, 'Alpha' AS name, 3 AS n UNION ALL SELECT 2, 'Beta', %%STARTTIME%%");
    }

    /**
     * A saved schedule of the test report.
     *
     * @param array $recipients
     * @param array $fields
     * @return \stdClass
     */
    private function schedule(array $recipients, array $fields = []): \stdClass {
        global $DB;
        $schedule = (object) array_merge(['reportid' => $this->report->id, 'userid' => get_admin()->id,
            'recipients' => json_encode($recipients), 'frequency' => 'weekly', 'weekday' => 1, 'monthday' => 1, 'hour' => 8,
            'format' => 'csv', 'viewstate' => json_encode(['level' => 0]), 'daterange' => '', 'subject' => '',
            'message' => '', 'enabled' => 1], $fields);
        return $DB->get_record(scheduler::TABLE, ['id' => scheduler::save($schedule)]);
    }

    public function test_next_run_of_each_frequency(): void {
        $tz = new \DateTimeZone('Europe/Madrid');
        $now = (new \DateTime('2026-10-10 15:30:00', $tz))->getTimestamp();
        $base = (object) ['userid' => 2, 'hour' => 8, 'weekday' => 1, 'monthday' => 5];
        $expected = ['daily' => '2026-10-11 08:00', 'weekly' => '2026-10-12 08:00', 'monthly' => '2026-11-05 08:00',
            'quarterly' => '2027-01-05 08:00'];
        foreach ($expected as $frequency => $date) {
            $base->frequency = $frequency;
            $this->assertSame($date, (new \DateTime('@' . scheduler::next_run($base, $now, $tz)))->setTimezone($tz)
                ->format('Y-m-d H:i'), $frequency);
        }
        // Later today when the hour has not passed yet.
        $base->frequency = 'daily';
        $base->hour = 18;
        $this->assertSame('2026-10-10 18:00', (new \DateTime('@' . scheduler::next_run($base, $now, $tz)))
            ->setTimezone($tz)->format('Y-m-d H:i'));
    }

    public function test_recipients_from_users_cohorts_roles_and_addresses(): void {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $gen = $this->getDataGenerator();
        $ana = $gen->create_user(['email' => 'ana@example.com']);
        $ben = $gen->create_user(['email' => 'ben@example.com']);
        $suspended = $gen->create_user(['email' => 'off@example.com', 'suspended' => 1]);
        $cohort = $gen->create_cohort();
        cohort_add_member($cohort->id, $ben->id);
        cohort_add_member($cohort->id, $suspended->id);
        $manager = $gen->create_user(['email' => 'boss@example.com']);
        $gen->role_assign('manager', $manager->id);
        // A role held only inside a course does not count for a site report.
        $course = $gen->create_course();
        $gen->enrol_user($gen->create_user(['email' => 'teacher@example.com'])->id, $course->id, 'manager');

        $managerrole = (int) \core\di::get(\moodle_database::class)->get_field('role', 'id', ['shortname' => 'manager']);
        $schedule = $this->schedule(['users' => [$ana->id, $suspended->id], 'cohorts' => [$cohort->id],
            'roles' => [$managerrole], 'emails' => ['outside@example.org', 'ANA@example.com', 'not an email']]);
        $emails = array_map(fn($u) => $u->email, scheduler::recipients($schedule, $this->report));
        sort($emails);
        $this->assertSame(['ana@example.com', 'ben@example.com', 'boss@example.com', 'outside@example.org'], $emails);
    }

    public function test_delivery_sends_the_file_and_logs_it(): void {
        global $CFG, $DB;
        // Email switched on for this test (the sink catches it).
        $CFG->noemailever = false;
        $sink = $this->redirectEmails();
        $ana = $this->getDataGenerator()->create_user(['email' => 'ana@example.com']);
        $schedule = $this->schedule(['users' => [$ana->id], 'emails' => ['outside@example.org']],
            ['daterange' => 'lastmonth', 'message' => 'Hello team', 'format' => 'csv']);
        $log = scheduler::send($schedule);
        $this->assertSame('sent', $log->status, (string) $log->error);
        $this->assertSame(2, $log->recipients);
        $this->assertSame(2, $log->rowcount);
        $messages = $sink->get_messages();
        $sink->close();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Weekly report (', $messages[0]->subject);
        $this->assertStringContainsString('Hello team', quoted_printable_decode($messages[0]->body));
        $this->assertStringContainsString('Weekly report.csv', $messages[0]->body);
        $this->assertSame(1, $DB->count_records(scheduler::LOG, ['scheduleid' => $schedule->id, 'status' => 'sent']));
        $this->assertGreaterThan(0, (int) $DB->get_field(scheduler::TABLE, 'lastrun', ['id' => $schedule->id]));
    }

    public function test_a_creator_who_lost_access_sends_nothing(): void {
        $teacher = $this->getDataGenerator()->create_user();
        $schedule = $this->schedule(['emails' => ['outside@example.org']], ['userid' => $teacher->id]);
        $log = scheduler::send($schedule);
        $this->assertSame('error', $log->status);
        $this->assertSame(get_string('scheduleownerlost', 'local_reportium'), $log->error);
    }

    public function test_the_task_sends_what_is_due_and_moves_the_next_run(): void {
        global $DB;
        $due = $this->schedule(['emails' => ['outside@example.org']]);
        $later = $this->schedule(['emails' => ['outside@example.org']]);
        $paused = $this->schedule(['emails' => ['outside@example.org']], ['enabled' => 0]);
        $DB->set_field(scheduler::TABLE, 'nextrun', time() - 60, ['id' => $due->id]);
        $this->assertSame(0, (int) $paused->nextrun);
        $this->expectOutputRegex('/1 scheduled report\(s\) processed/');
        (new \local_reportium\task\send_scheduled())->execute();
        $this->assertGreaterThan(time(), (int) $DB->get_field(scheduler::TABLE, 'nextrun', ['id' => $due->id]));
        $this->assertSame(1, $DB->count_records(scheduler::LOG, ['scheduleid' => $due->id]));
        $this->assertSame(0, $DB->count_records(scheduler::LOG, ['scheduleid' => $later->id]));

        scheduler::delete_report((int) $this->report->id);
        $this->assertSame(0, $DB->count_records(scheduler::TABLE, ['reportid' => $this->report->id]));
        $this->assertSame(0, $DB->count_records(scheduler::LOG));
    }

    public function test_who_manages_a_schedule(): void {
        $schedule = $this->schedule(['emails' => ['outside@example.org']]);
        $this->assertTrue(scheduler::can_manage($schedule, $this->report));
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('editingteacher', $teacher->id);
        $this->setUser($teacher);
        $this->assertFalse(scheduler::can_manage($schedule, $this->report));
    }
}
