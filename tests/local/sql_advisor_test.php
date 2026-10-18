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
 * Tests of the slow query warning: threshold, tips found in the SQL, and who gets them.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\sql_advisor
 */
final class sql_advisor_test extends \advanced_testcase {

    public function test_threshold(): void {
        $this->resetAfterTest();
        $this->assertSame(3000, sql_advisor::threshold_ms());
        $this->assertFalse(sql_advisor::is_slow(2999));
        $this->assertTrue(sql_advisor::is_slow(3000));
        set_config('slowquerysecs', '0.5', 'local_reportium');
        $this->assertTrue(sql_advisor::is_slow(600));
        set_config('slowquerysecs', '0', 'local_reportium');
        $this->assertFalse(sql_advisor::is_slow(999999));
    }

    public function test_tips_found_in_the_sql(): void {
        $this->resetAfterTest();
        $report = (object) ['id' => 12345];
        set_config('cachettlminutes_report_12345', 15, 'local_reportium');
        $cases = [
            'SELECT l.userid, COUNT(1) AS n FROM {logstore_standard_log} l GROUP BY l.userid' => ['period'],
            'SELECT l.userid FROM {logstore_standard_log} l WHERE l.timecreated BETWEEN %%STARTTIME%% AND %%ENDTIME%%'
                => ['generic'],
            'SELECT u.id, (SELECT COUNT(1) FROM {user_enrolments} ue WHERE ue.userid = u.id) AS n FROM {user} u'
                => ['subquery'],
            "SELECT u.id FROM {user} u WHERE LOWER(u.email) = 'a@b.c'" => ['function'],
            "SELECT u.id FROM {user} u WHERE u.email LIKE '%@example.com'" => ['like'],
            'SELECT u.id FROM {user} u JOIN {role_assignments} ra ON ra.userid = u.id OR ra.modifierid = u.id' => ['or'],
            'SELECT * FROM {course}' => ['selectall'],
        ];
        foreach ($cases as $sql => $expected) {
            $this->assertSame($expected, sql_advisor::tips($sql, $report), $sql);
        }
        // Without a cache: one more tip.
        set_config('cachettlminutes_report_12345', 0, 'local_reportium');
        $this->assertSame(['selectall', 'cache'], sql_advisor::tips('SELECT * FROM {course}', $report));
    }

    public function test_only_sql_writers_get_the_warning(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('slowquerysecs', '0.001', 'local_reportium');
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $report = $generator->create_report(['name' => 'Slow']);
        $generator->create_level($report->id, 0, 'SELECT c1.id, c2.name FROM {config} c1 CROSS JOIN {config} c2');
        report_roles::set((int) $report->id, report_roles::default_roles());
        $data = \local_reportium\external\levels::get_level_data((int) $report->id);
        $this->assertNotEmpty($data['slowtips']);

        // A manager sees the report but cannot change its SQL: no warning.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);
        $this->assertSame([], \local_reportium\external\levels::get_level_data((int) $report->id)['slowtips']);
    }
}
