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
 * Integration tests for generic drill-down levels against a real database.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\level_service
 */
final class level_service_test extends \advanced_testcase {

    /** @var \stdClass */
    private $report;
    /** @var \stdClass */
    private $course1;
    /** @var \stdClass[] */
    private $users = [];

    /**
     * One category with two courses: course A has 2 students, course B has 1.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $cat = $gen->create_category(['name' => 'Drill Cat']);
        $this->course1 = $gen->create_course(['category' => $cat->id, 'fullname' => 'Course A']);
        $course2 = $gen->create_course(['category' => $cat->id, 'fullname' => 'Course B']);
        $this->users[] = $gen->create_user(['firstname' => 'Ana', 'lastname' => 'Alpha']);
        $this->users[] = $gen->create_user(['firstname' => 'Bob', 'lastname' => 'Beta']);
        $gen->enrol_user($this->users[0]->id, $this->course1->id);
        $gen->enrol_user($this->users[1]->id, $this->course1->id);
        $gen->enrol_user($this->users[1]->id, $course2->id);
        $this->report = $gen->get_plugin_generator('local_reportium')->create_demo_report();
    }

    /**
     * Full chain: root → courses of a category → users of a course, with the value passed down inferred.
     */
    public function test_drill_chain(): void {
        global $USER;
        $context = \context_system::instance();

        $root = level_service::get_level_payload($this->report, 0, [], $context, (int) $USER->id);
        $this->assertTrue($root['hasnext']);
        $this->assertSame('name', $root['mapping']['labelcolumn']);
        // Level 1 compares cc.name, so the root passes its label.
        $this->assertSame('name', $root['mapping']['valuecolumn']);
        $this->assertContains('Drill Cat', array_column($root['rows'], 'name'));

        $courses = level_service::get_level_payload($this->report, 1, ['Drill Cat'], $context, (int) $USER->id);
        $this->assertCount(2, $courses['rows']);
        $this->assertSame(['id', 'text', 'number'], array_column($courses['columns'], 'type'));
        // Level 2 compares e.courseid, so the course id is passed (never the ambiguous name).
        $this->assertSame('id', $courses['mapping']['valuecolumn']);
        $this->assertSame(['users'], $courses['mapping']['metrics']);
        $byname = array_column($courses['rows'], 'users', 'fullname');
        $this->assertSame('2', $byname['Course A']);
        $this->assertSame('1', $byname['Course B']);

        $users = level_service::get_level_payload($this->report, 2, ['Drill Cat', (string) $this->course1->id], $context,
            (int) $USER->id);
        $this->assertFalse($users['hasnext']);
        $this->assertSame(['Alpha', 'Beta'], array_column($users['rows'], 'lastname'));
        $types = array_column($users['columns'], 'type', 'key');
        $this->assertSame('date', $types['timecreated']);
    }

    /**
     * %%LEVELVALUE:n%% uses the value of an earlier level: a wrong category returns no users.
     */
    public function test_levelvalue_placeholder(): void {
        global $USER;
        $users = level_service::get_level_payload($this->report, 2, ['Other cat', (string) $this->course1->id],
            \context_system::instance(), (int) $USER->id);
        $this->assertSame([], $users['rows']);
    }

    /**
     * Values are bound as parameters: a malicious clicked value is just data.
     */
    public function test_values_are_bound_not_concatenated(): void {
        global $USER;
        [$sql, $params] = level_service::bind_placeholders('SELECT 1 WHERE a = %%PREVIOUSVALUE%% OR b = %%LEVELVALUE:0%%',
            ["x' OR '1'='1", 'second']);
        $this->assertStringNotContainsString("x'", $sql);
        // The PREVIOUSVALUE placeholder gets the last value of the path, LEVELVALUE:0 the first one.
        $this->assertSame(['second', "x' OR '1'='1"], array_values($params));

        $courses = level_service::get_level_payload($this->report, 1, ["Drill Cat' OR '1'='1"], \context_system::instance(),
            (int) $USER->id);
        $this->assertSame([], $courses['rows']);
    }

    /**
     * Saved chart settings override the automatic mapping; an empty config resets it.
     */
    public function test_saved_config_overrides_mapping(): void {
        global $USER;
        level_service::save_level_config((int) $this->report->id, 1, [
            'defaultview' => 'pie', 'metrics' => ['users'], 'title' => 'Courses in {parent}', 'evil' => '<script>',
        ]);
        $courses = level_service::get_level_payload($this->report, 1, ['Drill Cat'], \context_system::instance(), (int) $USER->id);
        $this->assertSame('pie', $courses['mapping']['defaultview']);
        $this->assertSame('Courses in {parent}', $courses['title']);
        $this->assertArrayNotHasKey('evil', $courses['mapping']);

        level_service::save_level_config((int) $this->report->id, 1, []);
        $reset = level_service::get_level_payload($this->report, 1, ['Drill Cat'], \context_system::instance(), (int) $USER->id);
        $this->assertSame('bar', $reset['mapping']['defaultview']);
    }

    /**
     * Downloads mirror the screen: search, filters and column sort.
     */
    public function test_visible_rows(): void {
        global $USER;
        $payload = level_service::get_level_payload($this->report, 1, ['Drill Cat'], \context_system::instance(),
            (int) $USER->id);

        $this->assertCount(2, level_service::visible_rows($payload));
        $this->assertSame(['Course A'], array_column(level_service::visible_rows($payload, 'course a'), 'fullname'));
        $this->assertSame(['Course A'], array_column(level_service::visible_rows($payload, '', ['users' => ['min' => '2']]),
            'fullname'));
        $this->assertSame(['Course B'], array_column(
            level_service::visible_rows($payload, '', ['fullname' => ['in' => ['Course B']]]), 'fullname'));

        // Table header sort: text alphabetically, numbers numerically; unknown columns are ignored.
        $this->assertSame(['Course B', 'Course A'],
            array_column(level_service::visible_rows($payload, '', [], [], 'fullname:desc'), 'fullname'));
        $this->assertSame(['Course B', 'Course A'],
            array_column(level_service::visible_rows($payload, '', [], [], 'users:asc'), 'fullname'));
        $this->assertSame(['Course A', 'Course B'],
            array_column(level_service::visible_rows($payload, '', [], [], 'users:desc'), 'fullname'));
        $this->assertCount(2, level_service::visible_rows($payload, '', [], [], 'nosuchcolumn:asc'));

    }

    /**
     * Value column inference from the next level's SQL.
     */
    public function test_infer_value_column(): void {
        $columns = column_inspector::describe(['id', 'fullname', 'n'], [['id' => '1', 'fullname' => 'X', 'n' => '2']]);
        $defaults = column_inspector::default_mapping($columns);
        $this->assertSame('id', level_service::infer_value_column($columns, $defaults, 'WHERE ue.userid = %%PREVIOUSVALUE%%'));
        $this->assertSame('fullname',
            level_service::infer_value_column($columns, $defaults, 'WHERE c.fullname = %%PREVIOUSVALUE%%'));
        $this->assertSame('id', level_service::infer_value_column($columns, $defaults, 'WHERE c.id IN (%%PREVIOUSVALUE%%)'));
        $this->assertSame('id', level_service::infer_value_column($columns, $defaults, 'no placeholder'));
    }

    /**
     * Errors: missing level, short path, broken SQL.
     */
    public function test_errors(): void {
        global $USER;
        $context = \context_system::instance();
        try {
            level_service::get_level_payload($this->report, 5, [], $context, (int) $USER->id);
            $this->fail('Expected levelnotfound');
        } catch (\moodle_exception $e) {
            $this->assertSame('levelnotfound', $e->errorcode);
        }
        try {
            level_service::get_level_payload($this->report, 2, ['only one'], $context, (int) $USER->id);
            $this->fail('Expected invalidpath');
        } catch (\moodle_exception $e) {
            $this->assertSame('invalidpath', $e->errorcode);
        }
        $this->getDataGenerator()->get_plugin_generator('local_reportium')
            ->create_level((int) $this->report->id, 1, 'SELECT nocolumn FROM {course} WHERE fullname = %%PREVIOUSVALUE%%');
        try {
            level_service::get_level_payload($this->report, 1, ['x'], $context, (int) $USER->id);
            $this->fail('Expected levelsqlerror');
        } catch (\moodle_exception $e) {
            $this->assertSame('levelsqlerror', $e->errorcode);
        }
    }

    /**
     * With a cache TTL, the second call is served from MUC.
     */
    public function test_cache(): void {
        global $USER;
        set_config('cachettlminutes_report_' . $this->report->id, 5, 'local_reportium');
        $context = \context_system::instance();
        $first = level_service::get_level_payload($this->report, 1, ['Drill Cat'], $context, (int) $USER->id);
        $second = level_service::get_level_payload($this->report, 1, ['Drill Cat'], $context, (int) $USER->id);
        $this->assertFalse($first['cachemeta']['cached']);
        $this->assertTrue($second['cachemeta']['cached']);
        $this->assertSame($first['rows'], $second['rows']);
    }

    /**
     * Portable validation accepts both placeholders and rejects bad SQL and write statements.
     */
    public function test_validate_level_sql(): void {
        $sql = 'SELECT id FROM {course} WHERE fullname = %%PREVIOUSVALUE%% OR shortname = %%LEVELVALUE:0%%';
        $this->assertNull(report_data_helper::validate_level_sql($this->report, 2, $sql));
        $this->assertNotNull(report_data_helper::validate_level_sql($this->report, 1, 'SELECT nocolumn FROM {course}'));
        $this->assertNotNull(report_data_helper::validate_level_sql($this->report, 1, 'DELETE FROM {course}'));
    }

    /**
     * A level under the first one must use the row clicked above; level 1 needs nothing.
     */
    public function test_a_lower_level_must_use_the_row_clicked_above(): void {
        $sql = 'SELECT id, fullname FROM {course}';
        $this->assertNull(report_data_helper::validate_level_sql($this->report, 0, $sql));
        $error = report_data_helper::validate_level_sql($this->report, 4, $sql);
        $this->assertStringContainsString('Level 5', $error);
        $this->assertStringContainsString('%%PREVIOUSVALUE%%', $error);
        $this->assertNull(report_data_helper::validate_level_sql($this->report, 4, $sql . ' WHERE id = %%PREVIOUSVALUE%%'));
        $this->assertNull(report_data_helper::validate_level_sql($this->report, 4, $sql . ' WHERE category = %%LEVELVALUE:1%%'));
        // Placeholders are replaced in capitals only: a lowercase one does not link the level.
        $this->assertStringContainsString('Level 2',
            report_data_helper::validate_level_sql($this->report, 1, $sql . ' WHERE id = %%previousvalue%%'));
    }
}
