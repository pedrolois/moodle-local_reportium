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

/**
 * Tests of report roles and of the rows each role sees.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_reportium\local;

use local_reportium\output\report_roles_editable;

/**
 * A teacher sees only what they need: the reports given to their role, and only the rows of their own courses
 * (%%MYCOURSES%%), at every drill level.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\report_roles
 * @covers     \local_reportium\output\report_roles_editable
 * @covers     \local_reportium\local\level_service
 */
final class report_roles_test extends \advanced_testcase {

    /** @var \stdClass */
    private $report;
    /** @var \stdClass */
    private $coursea;
    /** @var \stdClass */
    private $courseb;
    /** @var \stdClass */
    private $teacher;
    /** @var \stdClass */
    private $student;

    /**
     * Two courses. The teacher teaches course A only; the student is enrolled in both.
     * One site report: courses (level 1) → enrolled users of the clicked course (level 2), both limited to %%MYCOURSES%%.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $this->coursea = $gen->create_course(['fullname' => 'Course A']);
        $this->courseb = $gen->create_course(['fullname' => 'Course B']);
        $this->teacher = $gen->create_user(['firstname' => 'Tina', 'lastname' => 'Teacher']);
        $this->student = $gen->create_user(['firstname' => 'Sam', 'lastname' => 'Student']);
        $gen->enrol_user($this->teacher->id, $this->coursea->id, 'editingteacher');
        $gen->enrol_user($this->student->id, $this->coursea->id, 'student');
        $gen->enrol_user($this->student->id, $this->courseb->id, 'student');

        $this->setAdminUser();
        $this->report = (object) [
            'courseid' => SITEID, 'ownerid' => get_admin()->id, 'visible' => 1, 'name' => 'My courses',
            'summary' => '', 'summaryformat' => FORMAT_HTML, 'export' => 'csv,',
            'global' => 1, 'displaytotalrecords' => 1, 'displayprintbutton' => 1,
        ];
        $this->report->id = $DB->insert_record('local_reportium', $this->report);
        level_service::save_root_sql((int) $this->report->id,
            'SELECT c.id, c.fullname FROM {course} c WHERE c.id > 1 AND c.id IN (%%MYCOURSES%%) ORDER BY c.fullname');
        $DB->insert_record('local_reportium_level', (object) [
            'reportid' => $this->report->id, 'drilllevel' => 1, 'label' => '', 'config' => '{}',
            'sqlquery' => "SELECT u.id, u.firstname, u.lastname
                             FROM {user} u
                             JOIN {user_enrolments} ue ON ue.userid = u.id
                             JOIN {enrol} e ON e.id = ue.enrolid
                            WHERE e.courseid = %%PREVIOUSVALUE%% AND e.courseid IN (%%MYCOURSES%%)
                         ORDER BY u.lastname",
            'timecreated' => time(), 'timemodified' => time(), 'usermodified' => get_admin()->id,
        ]);
        $this->report = $DB->get_record('local_reportium', ['id' => $this->report->id]);
    }

    /**
     * Can the user open the report?
     *
     * @param \stdClass $user
     * @return bool
     */
    private function can_view(\stdClass $user): bool {
        return report_roles::can_view($this->report, (int) $user->id, \context_system::instance());
    }

    /**
     * Rows of a level as the given user sees them.
     *
     * @param \stdClass $user
     * @param int $level
     * @param string[] $path
     * @return array
     */
    private function rows_for(\stdClass $user, int $level, array $path = []): array {
        $this->setUser($user);
        return level_service::get_level_payload($this->report, $level, $path, \context_system::instance(),
            (int) $user->id)['rows'];
    }

    public function test_only_the_roles_given_to_the_report_can_open_it(): void {
        global $DB;
        $editingteacher = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        report_roles::set((int) $this->report->id, [$editingteacher]);

        // A site report: the role must be held at site or category level, not only inside a course.
        $this->assertFalse($this->can_view($this->teacher), 'A teacher of one course does not open a site report');
        $category = $this->getDataGenerator()->create_category();
        $categoryteacher = $this->getDataGenerator()->create_user();
        role_assign($editingteacher, $categoryteacher->id, \context_coursecat::instance($category->id));
        $this->assertTrue($this->can_view($categoryteacher), 'A teacher of a category does');
        $this->assertFalse($this->can_view($this->student), 'A student does not');
        $this->assertTrue($this->can_view(get_admin()), 'Report managers always do');

        // Shared with nobody: only those who manage it.
        report_roles::set((int) $this->report->id, []);
        $this->assertSame([], report_roles::get((int) $this->report->id));
        $this->assertFalse($this->can_view($categoryteacher));
        $this->assertTrue($this->can_view(get_admin()));
    }

    public function test_course_reports_need_the_role_in_that_course(): void {
        global $DB;
        $report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_report(
            ['name' => 'Course A report', 'courseid' => $this->coursea->id]);
        report_roles::set((int) $report->id, [(int) $DB->get_field('role', 'id', ['shortname' => 'student'])]);
        $context = \context_course::instance($this->coursea->id);
        $this->assertTrue(report_roles::can_view($report, (int) $this->student->id, $context));
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($other->id, $this->courseb->id, 'student');
        $this->assertFalse(report_roles::can_view($report, (int) $other->id, $context), 'A student of another course');
        // The teacher of course A was not given the report.
        $this->assertFalse(report_roles::can_view($report, (int) $this->teacher->id, $context));
    }

    public function test_teacher_sees_only_their_courses_at_every_level(): void {
        // Level 1: the teacher's courses only; the admin sees every course.
        $this->assertSame(['Course A'], array_column($this->rows_for($this->teacher, 0), 'fullname'));
        $this->assertSame(['Course A', 'Course B'], array_column($this->rows_for(get_admin(), 0), 'fullname'));

        // Level 2 of their own course: its users.
        $users = array_column($this->rows_for($this->teacher, 1, [(string) $this->coursea->id]), 'lastname');
        $this->assertContains('Student', $users);
        $this->assertContains('Teacher', $users);

        // A hand-made URL for course B returns nothing: %%MYCOURSES%% is applied at every level.
        $this->assertSame([], $this->rows_for($this->teacher, 1, [(string) $this->courseb->id]));
        // The admin can drill into course B.
        $this->assertSame(['Student'], array_column($this->rows_for(get_admin(), 1, [(string) $this->courseb->id]), 'lastname'));
    }

    public function test_mycourses_includes_course_and_category_roles_without_enrolment(): void {
        global $DB;
        $gen = $this->getDataGenerator();
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);

        // A role in course B without enrolment ("Other users").
        $observer = $gen->create_user();
        role_assign($teacherrole, $observer->id, \context_course::instance($this->courseb->id));
        $this->assertSame([(int) $this->courseb->id], level_service::my_courses((int) $observer->id));

        // A role in a category: every course of it and of its subcategories.
        $category = $gen->create_category(['name' => 'Faculty']);
        $subcategory = $gen->create_category(['name' => 'Department', 'parent' => $category->id]);
        $c1 = $gen->create_course(['category' => $category->id]);
        $c2 = $gen->create_course(['category' => $subcategory->id]);
        $categoryteacher = $gen->create_user();
        role_assign($teacherrole, $categoryteacher->id, \context_coursecat::instance($category->id));
        $this->assertEqualsCanonicalizing([(int) $c1->id, (int) $c2->id], level_service::my_courses((int) $categoryteacher->id));

        // Enrolled teacher: their course only.
        $this->assertSame([(int) $this->coursea->id], level_service::my_courses((int) $this->teacher->id));
    }

    public function test_roles_column_is_edited_like_the_participants_roles(): void {
        global $DB;
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        $managerrole = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);

        // The admin adds two roles through the core in-place editing callback, then removes one.
        $this->setAdminUser();
        $editable = local_reportium_inplace_editable_for_test('report_roles', $this->report->id,
            json_encode([$teacherrole, $managerrole]));
        $this->assertInstanceOf(report_roles_editable::class, $editable);
        $this->assertEqualsCanonicalizing([$teacherrole, $managerrole], report_roles::get((int) $this->report->id));
        local_reportium_inplace_editable_for_test('report_roles', $this->report->id, json_encode([$teacherrole]));
        $this->assertSame([$teacherrole], report_roles::get((int) $this->report->id));

        // A teacher cannot change who sees the report.
        $this->setUser($this->teacher);
        $this->expectException(\moodle_exception::class);
        local_reportium_inplace_editable_for_test('report_roles', $this->report->id, json_encode([]));
    }
}

/**
 * Call the plugin's in-place editing callback as core_update_inplace_editable does.
 *
 * @param string $itemtype
 * @param int $itemid
 * @param string $value
 * @return mixed
 * @package local_reportium
 */
function local_reportium_inplace_editable_for_test(string $itemtype, $itemid, string $value) {
    global $CFG;
    require_once($CFG->dirroot . '/local/reportium/lib.php');
    return \local_reportium_inplace_editable($itemtype, $itemid, $value);
}
