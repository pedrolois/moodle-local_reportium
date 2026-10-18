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
 * Tests of who may view, edit and write the SQL of reports.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\report_roles
 */
final class report_access_test extends \advanced_testcase {
    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_demo_report();
    }

    /**
     * Give a user a role at site level.
     *
     * @param string $shortname
     * @return \stdClass user
     */
    private function user_with_role(string $shortname): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => $shortname]), $user->id, \context_system::instance()->id);
        return $user;
    }

    public function test_new_reports_are_for_managers(): void {
        global $DB;
        report_roles::set_defaults((int) $this->report->id);
        $this->assertSame(
            [(int) $DB->get_field('role', 'id', ['shortname' => 'manager'])],
            report_roles::get((int) $this->report->id)
        );
        // Defaults do not replace roles already chosen.
        $teacher = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        report_roles::set((int) $this->report->id, [$teacher]);
        report_roles::set_defaults((int) $this->report->id);
        $this->assertSame([$teacher], report_roles::get((int) $this->report->id));
    }

    public function test_set_ignores_unknown_and_repeated_roles(): void {
        global $DB;
        $manager = (int) $DB->get_field('role', 'id', ['shortname' => 'manager']);
        report_roles::set((int) $this->report->id, [$manager, $manager, 999999]);
        $this->assertSame([$manager], report_roles::get((int) $this->report->id));
        $many = report_roles::get_many([(int) $this->report->id, 12345]);
        $this->assertSame([$manager], $many[$this->report->id]);
        $this->assertArrayNotHasKey(12345, array_filter($many));
    }

    public function test_view_rules(): void {
        global $DB;
        $context = \context_system::instance();
        report_roles::set_defaults((int) $this->report->id);
        $manager = $this->user_with_role('manager');
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertTrue(report_roles::can_view($this->report, (int) get_admin()->id, $context));
        $this->assertTrue(report_roles::can_view($this->report, (int) $manager->id, $context));
        $this->assertFalse(report_roles::can_view($this->report, (int) $nobody->id, $context));

        // Hidden reports: only those who manage reports.
        $hidden = clone($this->report);
        $hidden->visible = 0;
        $coursecreator = $this->user_with_role('coursecreator');
        report_roles::set((int) $this->report->id, [(int) $DB->get_field('role', 'id', ['shortname' => 'coursecreator'])]);
        $this->assertTrue(report_roles::can_view($this->report, (int) $coursecreator->id, $context));
        $this->assertFalse(report_roles::can_view($hidden, (int) $coursecreator->id, $context));
        $this->assertTrue(report_roles::can_view($hidden, (int) get_admin()->id, $context));
    }

    public function test_owner_can_view_and_edit_own_reports(): void {
        global $DB;
        $owner = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('local/reportium:create', CAP_ALLOW, $roleid, \context_system::instance()->id);
        role_assign($roleid, $owner->id, \context_system::instance()->id);
        $DB->set_field('local_reportium', 'ownerid', $owner->id, ['id' => $this->report->id]);
        $report = $DB->get_record('local_reportium', ['id' => $this->report->id]);
        report_roles::set_defaults((int) $report->id);

        $this->setUser($owner);
        $this->assertTrue(report_roles::can_edit($report));
        $this->assertTrue(report_roles::can_view($report, (int) $owner->id, \context_system::instance()));
        $other = $this->getDataGenerator()->create_user();
        role_assign($roleid, $other->id, \context_system::instance()->id);
        $this->setUser($other);
        $this->assertFalse(report_roles::can_edit($report));
    }

    public function test_reports_shared_with_nobody_are_only_for_those_who_manage_them(): void {
        global $DB;
        $DB->delete_records('local_reportium_role', ['reportid' => $this->report->id]);
        $teacher = $this->user_with_role('editingteacher');
        $this->assertFalse(report_roles::can_view($this->report, (int) $teacher->id, \context_system::instance()));
        $this->assertTrue(report_roles::can_view($this->report, (int) get_admin()->id, \context_system::instance()));
        $this->assertTrue(report_roles::can_view(
            $this->report,
            (int) $this->user_with_role('manager')->id,
            \context_system::instance()
        ));
    }

    public function test_a_shared_role_also_needs_the_view_capability(): void {
        global $DB;
        $teacherrole = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        report_roles::set((int) $this->report->id, [$teacherrole]);
        $teacher = $this->user_with_role('editingteacher');
        $this->assertTrue(report_roles::can_view($this->report, (int) $teacher->id, \context_system::instance()));
        assign_capability('local/reportium:view', CAP_PROHIBIT, $teacherrole, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertFalse(report_roles::can_view($this->report, (int) $teacher->id, \context_system::instance()));
    }

    public function test_writing_sql_is_the_writesql_capability(): void {
        $context = \context_system::instance();
        $this->assertTrue(report_roles::can_manage_sql($context));
        $this->assertTrue(report_roles::can_write_sql($this->report, $context));
        // Managers manage reports but do not write SQL by default.
        $this->setUser($this->user_with_role('manager'));
        $this->assertTrue(report_roles::can_edit($this->report));
        $this->assertFalse(report_roles::can_manage_sql($context));
        $this->assertFalse(report_roles::can_write_sql($this->report, $context));
    }

    public function test_default_capabilities_of_each_role(): void {
        global $DB;
        $context = \context_system::instance();
        report_roles::set((int) $this->report->id, [(int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher'])]);
        // Teacher: opens, downloads, creates and shares, but does not manage other reports, move files or use the AI.
        $this->setUser($this->user_with_role('editingteacher'));
        $this->assertTrue(report_roles::can_use_manager($context));
        $this->assertTrue(report_roles::can_download($context));
        $this->assertTrue(has_capability('local/reportium:create', $context));
        $this->assertFalse(report_roles::can_edit($this->report));
        $this->assertFalse(report_roles::can_share($this->report));
        $this->assertFalse(has_capability('local/reportium:transfer', $context));
        $this->assertFalse(has_capability('local/reportium:useai', $context));
        // Manager: every report, sharing, files and AI.
        $this->setUser($this->user_with_role('manager'));
        $this->assertTrue(report_roles::can_edit($this->report));
        $this->assertTrue(report_roles::can_share($this->report));
        $this->assertTrue(has_capability('local/reportium:transfer', $context));
        $this->assertTrue(has_capability('local/reportium:useai', $context));
        // Student: views what is shared with them, never downloads.
        $this->setUser($this->user_with_role('student'));
        $this->assertTrue(report_roles::can_use_manager($context));
        $this->assertFalse(report_roles::can_download($context));
        $this->assertFalse(has_capability('local/reportium:create', $context));
    }
    public function test_options_leave_out_guest_roles(): void {
        $options = report_roles::options(\context_system::instance());
        $this->assertNotEmpty($options);
        global $DB;
        $guest = (int) $DB->get_field('role', 'id', ['shortname' => 'guest']);
        $this->assertArrayNotHasKey($guest, $options);
    }
}
