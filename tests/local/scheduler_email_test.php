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
 * Tests of the emails of scheduled reports: what each recipient receives (subject, message, link, the file and what
 * it holds), who receives it, the view and period sent, and when nothing must be sent.
 *
 * Emails are caught by the PHPUnit sink: none leaves the test.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\scheduler
 * @covers     \local_reportium\local\exporter
 */
final class scheduler_email_test extends \advanced_testcase {
    /** @var \stdClass report: level 1 courses (Alpha, Beta, Gamma), level 2 the items of a course */
    private $report;

    /** @var \phpunit_phpmailer_sink */
    private $sink;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Email on, no divert: only for this test (the sink catches every message).
        $CFG->noemailever = false;
        $CFG->divertallemailsto = '';
        $this->sink = $this->redirectEmails();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $this->report = $generator->create_report(['name' => 'Course summary']);
        $generator->create_level(
            $this->report->id,
            0,
            "SELECT 1 AS id, 'Alpha' AS course, 30 AS learners UNION ALL SELECT 2, 'Beta', 12 UNION ALL SELECT 3, 'Gamma', 7"
        );
        $generator->create_level(
            $this->report->id,
            1,
            "SELECT 10 AS id, CONCAT('Item of ', t.v) AS item, %%STARTTIME%% AS period_start"
            . " FROM (SELECT 2 AS v UNION ALL SELECT 3) t WHERE t.v = %%PREVIOUSVALUE%%"
        );
    }

    protected function tearDown(): void {
        $this->sink->close();
        parent::tearDown();
    }

    /**
     * Save a schedule of the test report.
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

    /**
     * The attachments of a sent message: file name => decoded content.
     *
     * @param \stdClass $message
     * @return string[]
     */
    private function attachments(\stdClass $message): array {
        $files = [];
        $parts = preg_split('/\r?\n--[^\r\n]+\r?\n/', $message->body);
        foreach ($parts as $part) {
            if (!preg_match('/Content-Disposition:\s*attachment;\s*filename="?([^"\r\n]+)"?/i', $part, $name)) {
                continue;
            }
            [$headers, $content] = preg_split('/\r?\n\r?\n/', $part, 2) + [1 => ''];
            $content = preg_replace('/\r?\n--[^\r\n]*--\s*$/', '', $content);
            $files[trim($name[1])] = stripos($headers, 'base64') !== false ? base64_decode(preg_replace('/\s+/', '', $content))
                : $content;
        }
        return $files;
    }

    /**
     * The readable text of a sent message (quoted-printable decoded).
     *
     * @param \stdClass $message
     * @return string
     */
    private function text(\stdClass $message): string {
        return quoted_printable_decode($message->body);
    }

    public function test_every_recipient_gets_the_file_with_the_rows_of_the_report(): void {
        $ana = $this->getDataGenerator()->create_user(['email' => 'ana@example.com']);
        $log = scheduler::send($this->schedule(['users' => [$ana->id], 'emails' => ['outside@example.org']]));
        $this->assertSame('sent', $log->status, (string) $log->error);
        $this->assertSame(3, $log->rowcount);

        $messages = $this->sink->get_messages();
        $this->assertCount(2, $messages);
        $this->assertEqualsCanonicalizing(['ana@example.com', 'outside@example.org'], array_column($messages, 'to'));
        foreach ($messages as $message) {
            $files = $this->attachments($message);
            $this->assertArrayHasKey('Course summary.csv', $files);
            $lines = array_map('str_getcsv', array_filter(explode("\n", trim($files['Course summary.csv']))));
            // Header and the three rows, without the id column, in the order of the SQL.
            $this->assertSame(['Course', 'Learners'], array_map(fn($v) => trim($v, "\xEF\xBB\xBF"), $lines[0]));
            $this->assertSame([['Alpha', '30'], ['Beta', '12'], ['Gamma', '7']], array_slice($lines, 1));
        }
    }

    public function test_subject_message_and_link(): void {
        $schedule = $this->schedule(['emails' => ['outside@example.org']], ['message' => 'Hello team, here is the summary.']);
        scheduler::send($schedule);
        $message = $this->sink->get_messages()[0];
        $this->assertSame('Course summary', $message->subject);
        $text = $this->text($message);
        // An own message is the whole body.
        $this->assertStringContainsString('Hello team, here is the summary.', $text);
        $this->assertStringNotContainsString('Attached: the report', $text);
        $this->assertSame(\core_user::get_noreply_user()->email, $message->from);

        // Without one, the default body: what is attached and the link to the report.
        $this->sink->clear();
        scheduler::send($this->schedule(['emails' => ['outside@example.org']]));
        $text = $this->text($this->sink->get_messages()[0]);
        $this->assertStringContainsString('Attached: the report "Course summary" (3 rows).', $text);
        $this->assertStringContainsString('/local/reportium/viewreport.php?id=' . $this->report->id, $text);

        // Own subject; with a period, the default subject names it.
        $this->sink->clear();
        scheduler::send($this->schedule(['emails' => ['outside@example.org']], ['subject' => 'Weekly numbers']));
        $this->assertSame('Weekly numbers', $this->sink->get_messages()[0]->subject);
        $this->sink->clear();
        scheduler::send($this->schedule(['emails' => ['outside@example.org']], ['daterange' => 'lastmonth']));
        [$start, $end] = date_range::resolve('lastmonth', 0, 0, 0, \core_date::get_user_timezone_object(get_admin()));
        $this->assertSame('Course summary (' . date_range::label($start, $end) . ')', $this->sink->get_messages()[0]->subject);
        $this->assertStringContainsString(
            'Period: ' . date_range::label($start, $end),
            $this->text($this->sink->get_messages()[0])
        );
    }

    public function test_the_file_follows_the_format(): void {
        $files = ['excel' => 'Course summary.xlsx', 'json' => 'Course summary.json', 'ods' => 'Course summary.ods'];
        foreach ($files as $format => $name) {
            $this->sink->clear();
            scheduler::send($this->schedule(['emails' => ['outside@example.org']], ['format' => $format]));
            $files = $this->attachments($this->sink->get_messages()[0]);
            $this->assertArrayHasKey($name, $files, $format);
            $this->assertNotSame('', $files[$name]);
        }
        // JSON holds the same rows.
        $this->sink->clear();
        scheduler::send($this->schedule(['emails' => ['outside@example.org']], ['format' => 'json']));
        $json = $this->attachments($this->sink->get_messages()[0])['Course summary.json'];
        $rows = json_decode($json, true);
        // Moodle JSON files hold one list of rows per sheet, keyed by column name.
        $this->assertCount(3, $rows[0]);
        $this->assertSame(['course' => 'Alpha', 'learners' => '30'], $rows[0][0]);
    }

    public function test_a_deeper_level_and_its_period_are_sent(): void {
        $view = ['level' => 1, 'steps' => [['v' => '2', 'l' => 'Beta']]];
        $log = scheduler::send($this->schedule(
            ['emails' => ['outside@example.org']],
            ['viewstate' => json_encode($view), 'daterange' => 'last7']
        ));
        $this->assertSame('sent', $log->status, (string) $log->error);
        $files = $this->attachments($this->sink->get_messages()[0]);
        // The file is named after the path, and holds the level of the row chosen (Beta), for the period.
        $this->assertArrayHasKey('Course summary - Beta.csv', $files);
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($files['Course summary - Beta.csv']))));
        $this->assertSame('Item of 2', $lines[1][0]);
        $start = date_range::resolve('last7', 0, 0, 0, \core_date::get_user_timezone_object(get_admin()))[0];
        $this->assertNotSame('', $lines[1][1]);
        $this->assertNotSame('0', $lines[1][1], 'The period of the schedule reached the SQL');
        $this->assertSame(1, $log->rowcount);
        $this->assertGreaterThan(0, $start);
    }

    public function test_search_and_filters_of_the_view_are_applied(): void {
        $view = ['level' => 0, 'search' => 'a', 'filters' => ['learners' => ['min' => '10']], 'sort' => 'learners:asc'];
        $log = scheduler::send($this->schedule(['emails' => ['outside@example.org']], ['viewstate' => json_encode($view)]));
        $this->assertSame(2, $log->rowcount);
        $lines = array_map('str_getcsv', array_filter(explode(
            "\n",
            trim($this->attachments($this->sink->get_messages()[0])['Course summary.csv'])
        )));
        $this->assertSame([['Beta', '12'], ['Alpha', '30']], array_slice($lines, 1));
    }

    public function test_recipients_from_cohorts_and_course_roles(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $report = $gen->get_plugin_generator('local_reportium')->create_report(['name' => 'Course report',
            'courseid' => $course->id]);
        $gen->get_plugin_generator('local_reportium')->create_level($report->id, 0, "SELECT 1 AS id, 'x' AS name");
        $teacher = $gen->create_user(['email' => 'teacher@example.com']);
        $student = $gen->create_user(['email' => 'student@example.com']);
        $other = $gen->create_user(['email' => 'other-course-teacher@example.com']);
        $gen->enrol_user($teacher->id, $course->id, 'editingteacher');
        $gen->enrol_user($student->id, $course->id, 'student');
        $gen->enrol_user($other->id, $gen->create_course()->id, 'editingteacher');
        $member = $gen->create_user(['email' => 'member@example.com']);
        $cohort = $gen->create_cohort();
        cohort_add_member($cohort->id, $member->id);
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);

        $schedule = $this->schedule(['roles' => [$teacherrole], 'cohorts' => [$cohort->id]], ['reportid' => $report->id]);
        // Enrolling sends course welcome emails: only the emails of the delivery count.
        $this->sink->clear();
        $log = scheduler::send($schedule);
        $this->assertSame('sent', $log->status, (string) $log->error);
        $this->assertEqualsCanonicalizing(
            ['teacher@example.com', 'member@example.com'],
            array_column($this->sink->get_messages(), 'to')
        );
    }

    public function test_no_email_to_suspended_deleted_or_repeated_addresses(): void {
        $gen = $this->getDataGenerator();
        $ana = $gen->create_user(['email' => 'ana@example.com']);
        $suspended = $gen->create_user(['email' => 'off@example.com', 'suspended' => 1]);
        $deleted = $gen->create_user(['email' => 'gone@example.com']);
        delete_user($deleted);
        $log = scheduler::send($this->schedule(['users' => [$ana->id, $suspended->id, $deleted->id],
            'emails' => ['ANA@example.com', 'not-an-address', 'outside@example.org']]));
        $this->assertSame(2, $log->recipients);
        $this->assertEqualsCanonicalizing(
            ['ana@example.com', 'outside@example.org'],
            array_column($this->sink->get_messages(), 'to')
        );
    }

    public function test_nothing_is_sent_when_it_must_not_be(): void {
        global $CFG, $DB;
        // The creator lost access to the report: no email, an error in the log.
        $teacher = $this->getDataGenerator()->create_user();
        $log = scheduler::send($this->schedule(['emails' => ['outside@example.org']], ['userid' => $teacher->id]));
        $this->assertSame('error', $log->status);
        $this->assertSame(0, $this->sink->count());

        // No recipients left (e.g. the only user was suspended).
        $user = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $log = scheduler::send($this->schedule(['users' => [$user->id]]));
        $this->assertSame(get_string('schedulenorecipients', 'local_reportium'), $log->error);
        $this->assertSame(0, $this->sink->count());

        // A paused schedule is never sent by the task, even with an old next run.
        $paused = $this->schedule(['emails' => ['outside@example.org']], ['enabled' => 0]);
        $DB->set_field(scheduler::TABLE, 'nextrun', time() - 3600, ['id' => $paused->id]);
        $this->assertSame(0, scheduler::run_due());
        $this->assertSame(0, $this->sink->count());

        // Email switched off on the site: logged as sent (like core), nothing goes out.
        $CFG->noemailever = true;
        $log = scheduler::send($this->schedule(['emails' => ['outside@example.org']]));
        $this->assertSame('sent', $log->status);
        $this->assertSame(0, $this->sink->count());
    }

    public function test_the_task_sends_each_due_schedule_once(): void {
        global $DB;
        $due = $this->schedule(['emails' => ['outside@example.org']]);
        $DB->set_field(scheduler::TABLE, 'nextrun', time() - 60, ['id' => $due->id]);
        $this->expectOutputRegex('/Reportium schedule \d+: sent, 1 recipient/');
        $this->assertSame(1, scheduler::run_due());
        $this->assertSame(1, $this->sink->count());
        // Already moved to its next run: a second run of the task sends nothing more.
        $this->assertSame(0, scheduler::run_due());
        $this->assertSame(1, $this->sink->count());
        $this->assertGreaterThan(time(), (int) $DB->get_field(scheduler::TABLE, 'nextrun', ['id' => $due->id]));
        $this->assertSame(1, $DB->count_records(scheduler::LOG, ['scheduleid' => $due->id, 'status' => 'sent']));
    }

    public function test_the_log_keeps_the_last_deliveries(): void {
        global $DB;
        $schedule = $this->schedule(['emails' => ['outside@example.org']]);
        for ($i = 0; $i < scheduler::KEEP_LOG + 3; $i++) {
            $DB->insert_record(scheduler::LOG, (object) ['scheduleid' => $schedule->id, 'timesent' => $i, 'status' => 'sent',
                'recipients' => 1, 'rowcount' => 1]);
        }
        scheduler::send($schedule);
        $this->assertSame(scheduler::KEEP_LOG, $DB->count_records(scheduler::LOG, ['scheduleid' => $schedule->id]));
        // The newest delivery is kept, the oldest ones go.
        $this->assertGreaterThan(scheduler::KEEP_LOG + 3, (int) $DB->get_field_sql(
            'SELECT MAX(timesent) FROM {' . scheduler::LOG . '} WHERE scheduleid = ?',
            [$schedule->id]
        ));
        $this->assertFalse($DB->record_exists(scheduler::LOG, ['scheduleid' => $schedule->id, 'timesent' => 0]));
    }

    public function test_privacy_removes_a_deleted_user_from_recipients_and_their_schedules(): void {
        global $DB;
        $ana = $this->getDataGenerator()->create_user(['email' => 'ana@example.com']);
        $ben = $this->getDataGenerator()->create_user(['email' => 'ben@example.com']);
        $theirs = $this->schedule(['emails' => ['outside@example.org']], ['userid' => $ana->id]);
        $sent = $this->schedule(['users' => [$ana->id, $ben->id]]);
        $approved = new \core_privacy\local\request\approved_contextlist(
            $ana,
            'local_reportium',
            [\context_user::instance($ana->id)->id]
        );
        \local_reportium\privacy\provider::delete_data_for_user($approved);
        $this->assertFalse($DB->record_exists(scheduler::TABLE, ['id' => $theirs->id]));
        $lists = scheduler::recipient_lists($DB->get_record(scheduler::TABLE, ['id' => $sent->id]));
        $this->assertSame([(int) $ben->id], $lists['users']);
    }
}
