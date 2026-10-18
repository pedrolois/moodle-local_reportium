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
 * Tests of the schedule form and its helpers: level names, the view saved from the form, its summary, the external
 * addresses, the validation and the recipient lists.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\scheduler
 * @covers     \schedule_form
 */
final class schedule_helpers_test extends \advanced_testcase {
    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/reportium/schedule_form.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $this->report = $generator->create_report(['name' => 'Helpers']);
        $generator->create_level($this->report->id, 0, "SELECT 1 AS id, 'A' AS name");
        $generator->create_level($this->report->id, 1, 'SELECT 1 AS id, %%PREVIOUSVALUE%% AS v');
        $generator->create_level($this->report->id, 2, 'SELECT 1 AS id, %%PREVIOUSVALUE%% AS w');
        \core\di::get(\moodle_database::class)->set_field(
            'local_reportium_level',
            'label',
            'Courses in {parent}',
            ['reportid' => $this->report->id, 'drilllevel' => 1]
        );
    }

    public function test_level_names(): void {
        $this->assertSame(
            [0 => 'Level 1', 1 => 'Level 2: Courses in the row chosen in level 1', 2 => 'Level 3'],
            scheduler::level_names((int) $this->report->id)
        );
        // A report without levels yet still offers level 1.
        $empty = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_report(['name' => 'Empty']);
        $this->assertSame([0 => 'Level 1'], scheduler::level_names((int) $empty->id));
    }

    public function test_view_from_form(): void {
        $saved = json_encode(['level' => 2, 'steps' => [['v' => '7', 'l' => 'Cat <b>A</b>'], ['v' => '9', 'l' => 'Course 9'],
            ['v' => 'x', 'l' => 'extra']], 'search' => 'ana', 'filters' => ['n' => ['min' => '1']], 'sort' => 'n:desc']);
        // Same level: the rows up to it, search, filters and sort kept; labels cleaned.
        $view = scheduler::view_from_form(2, $saved);
        $this->assertSame([['v' => '7', 'l' => 'Cat A'], ['v' => '9', 'l' => 'Course 9']], $view['steps']);
        $this->assertSame('ana', $view['search']);
        $this->assertSame('n:desc', $view['sort']);
        $this->assertSame(['n' => ['min' => '1']], $view['filters']);
        // Another level (or rows changed in the form): the rows kept, the rest belonged to the old view.
        $other = scheduler::view_from_form(1, $saved);
        $this->assertSame([['v' => '7', 'l' => 'Cat A']], $other['steps']);
        $this->assertSame('', $other['search']);
        $this->assertSame([], $other['filters']);
        $changed = scheduler::view_from_form(2, json_encode(json_decode($saved, true) + ['changed' => true]));
        $this->assertSame('', $changed['sort']);
        // Bad input: level 1, nothing else.
        $this->assertSame(
            ['level' => 0, 'steps' => [], 'search' => '', 'filters' => [], 'override' => [], 'sort' => ''],
            scheduler::view_from_form(-3, 'not json')
        );
    }

    public function test_view_summary(): void {
        $this->assertSame('Level 1', scheduler::view_summary([]));
        $this->assertSame('Level 3: Cat A › Course 9', scheduler::view_summary(['level' => 2,
            'steps' => [['v' => 1, 'l' => 'Cat A'], ['v' => 2, 'l' => 'Course 9']]]));
        $this->assertSame('Level 1 (with search and filters)', scheduler::view_summary(['search' => 'x']));
        // Escaped for the page.
        $this->assertSame('Level 2: &lt;script&gt;', scheduler::view_summary(['level' => 1, 'steps' => [['l' => '<script>']]]));
    }

    public function test_external_addresses_of_the_form(): void {
        $this->assertSame(
            ['a@example.com', 'b@example.org', 'c@example.net'],
            \schedule_form::split_emails(" a@example.com,b@example.org;\n\nc@example.net  a@example.com ")
        );
        $this->assertSame([], \schedule_form::split_emails(''));
    }

    public function test_form_validation(): void {
        $form = new \schedule_form(null, ['report' => $this->report, 'levels' => scheduler::level_names((int) $this->report->id)]);
        $base = ['level' => 0, 'viewstate' => '{}', 'users' => [], 'cohorts' => [], 'roles' => [], 'emails' => ''];
        $errors = $form->validation($base, []);
        $this->assertSame(get_string('schedulenorecipients', 'local_reportium'), $errors['users']);

        $errors = $form->validation(['emails' => 'good@example.com, not-an-address'] + $base, []);
        $this->assertStringContainsString('not-an-address', $errors['emails']);
        $this->assertArrayNotHasKey('users', $errors);

        // Level 3 needs the rows of levels 1 and 2.
        $errors = $form->validation(['level' => 2, 'emails' => 'good@example.com',
            'viewstate' => json_encode(['steps' => [['v' => '7']]])] + $base, []);
        $this->assertSame(get_string('schedule_pathrequired', 'local_reportium'), $errors['level']);
        $errors = $form->validation(['level' => 2, 'emails' => 'good@example.com',
            'viewstate' => json_encode(['steps' => [['v' => '7'], ['v' => '9']]])] + $base, []);
        $this->assertSame([], $errors);
    }

    public function test_recipient_lists_are_cleaned(): void {
        $schedule = (object) ['recipients' => json_encode(['users' => ['3', 3, 0, 'x', 5], 'cohorts' => [2, 2],
            'roles' => ['4'], 'emails' => [' a@example.com ', 'nope', 'a@example.com']])];
        $this->assertSame(
            ['users' => [3, 5], 'cohorts' => [2], 'roles' => [4], 'emails' => ['a@example.com']],
            scheduler::recipient_lists($schedule)
        );
        $this->assertSame(
            ['users' => [], 'cohorts' => [], 'roles' => [], 'emails' => []],
            scheduler::recipient_lists((object) ['recipients' => 'broken'])
        );
    }

    public function test_saving_sets_the_next_run_and_pausing_clears_it(): void {
        global $DB;
        $id = scheduler::save((object) ['reportid' => $this->report->id, 'userid' => get_admin()->id,
            'recipients' => '{"emails":["a@example.com"]}', 'frequency' => 'daily', 'weekday' => 1, 'monthday' => 1,
            'hour' => 23, 'format' => 'csv', 'viewstate' => '{}', 'daterange' => '', 'subject' => '', 'message' => '',
            'enabled' => 1]);
        $schedule = $DB->get_record(scheduler::TABLE, ['id' => $id]);
        $this->assertGreaterThan(time(), (int) $schedule->nextrun);
        $this->assertLessThanOrEqual(time() + DAYSECS, (int) $schedule->nextrun);
        $schedule->enabled = 0;
        scheduler::save($schedule);
        $this->assertSame(0, (int) $DB->get_field(scheduler::TABLE, 'nextrun', ['id' => $id]));
    }
}
