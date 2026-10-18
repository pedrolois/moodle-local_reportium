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

namespace local_reportium\external;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * More rules of the level web services: what SQL is refused, paths, %%MYCOURSES%% for teachers, hidden reports.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\external\levels
 * @covers     \local_reportium\local\report_data_helper
 */
final class levels_rules_test extends \externallib_advanced_testcase {

    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $cat = $gen->create_category(['name' => 'Rules Cat']);
        $gen->create_course(['category' => $cat->id, 'fullname' => 'Rules Course']);
        $this->report = $gen->get_plugin_generator('local_reportium')->create_demo_report();
    }

    /**
     * Call an external function and clean the result like the web service layer does.
     *
     * @param string $method
     * @param array $args
     * @return array
     */
    private function call(string $method, array $args): array {
        $result = call_user_func_array([levels::class, $method], $args);
        return \core_external\external_api::clean_returnvalue(call_user_func([levels::class, $method . '_returns']), $result);
    }

    /**
     * Data provider of SQL the editor refuses, with the error string expected.
     *
     * @return array
     */
    public static function refused_sql_provider(): array {
        return [
            'delete' => ['DELETE FROM {course}', 'notallowedwords'],
            'update' => ['UPDATE {course} SET fullname = 1', 'notallowedwords'],
            'insert' => ['INSERT INTO {course} (id) VALUES (1)', 'notallowedwords'],
            'drop' => ['SELECT 1; DROP TABLE {course}', 'notallowedwords'],
            'create' => ['CREATE TABLE x (id INT)', 'notallowedwords'],
            'two statements' => ['SELECT 1; SELECT 2', 'nosemicolon'],
            'explicit prefix' => ['SELECT id FROM PREFIXcourse', 'noexplicitprefix'],
        ];
    }

    /**
     * Each refused query fails with its error.
     *
     * @dataProvider refused_sql_provider
     * @param string $sql
     * @param string $error
     */
    public function test_refused_sql(string $sql, string $error): void {
        global $CFG, $DB;
        $sql = str_replace('PREFIX', $CFG->prefix, $sql);
        $before = $DB->get_field('local_reportium_level', 'sqlquery', ['reportid' => $this->report->id, 'drilllevel' => 0]);
        $result = $this->call('save_level', [(int) $this->report->id, 0, $sql, '']);
        $this->assertFalse($result['success']);
        $this->assertSame(get_string($error, 'local_reportium'), $result['error']);
        $this->assertSame($before, $DB->get_field('local_reportium_level', 'sqlquery',
            ['reportid' => $this->report->id, 'drilllevel' => 0]));
    }

    public function test_accepted_sql_with_every_placeholder(): void {
        $sql = 'SELECT c.id, c.fullname FROM {course} c WHERE c.id IN (%%MYCOURSES%%) AND c.timecreated < %%NOW%%'
            . ' AND c.id <> %%USERID%% AND c.category = %%PREVIOUSVALUE%% AND %%LEVELVALUE:0%% IS NOT NULL';
        $result = $this->call('save_level', [(int) $this->report->id, 1, $sql, 'Courses']);
        $this->assertTrue($result['success'], $result['error']);
        $levels = $this->call('get_levels', [(int) $this->report->id, 0]);
        $this->assertSame('Courses', $levels['levels'][1]['label']);
    }

    public function test_levels_listing(): void {
        $levels = $this->call('get_levels', [(int) $this->report->id, 0]);
        $this->assertSame([0, 1, 2], array_column($levels['levels'], 'level'));
        $this->assertTrue($levels['canmanagesql']);
    }

    public function test_paths_must_reach_the_level(): void {
        $this->expectException(\moodle_exception::class);
        // Level 2 needs two values in the path.
        levels::get_level_data((int) $this->report->id, 2, ['Rules Cat'], 0);
    }

    public function test_missing_level_and_report(): void {
        try {
            levels::get_level_data((int) $this->report->id, 7, [], 0);
            $this->fail('A level that does not exist must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('levelnotfound', $e->errorcode);
        }
        $this->expectException(\moodle_exception::class);
        levels::get_level_data(999999, 0, [], 0);
    }

    public function test_hidden_reports_are_only_for_managers(): void {
        global $DB;
        $DB->set_field('local_reportium', 'visible', 0, ['id' => $this->report->id]);
        // Admin still sees it.
        $this->assertNotEmpty(json_decode($this->call('get_level_data', [(int) $this->report->id, 0, [], 0])['rows'], true));
        // A user with only the report role does not.
        $viewer = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        role_assign($roleid, $viewer->id, \context_system::instance()->id);
        \local_reportium\local\report_roles::set((int) $this->report->id, [$roleid]);
        $this->setUser($viewer);
        $this->expectException(\moodle_exception::class);
        levels::get_level_data((int) $this->report->id, 0, [], 0);
    }

    public function test_mycourses_limits_rows_to_the_teachers_courses(): void {
        global $CFG;
        $gen = $this->getDataGenerator();
        $mine = $gen->create_course(['fullname' => 'Mine']);
        $gen->create_course(['fullname' => 'Not mine']);
        $teacher = $gen->create_user();
        $gen->enrol_user($teacher->id, $mine->id, 'editingteacher');
        $report = $gen->get_plugin_generator('local_reportium')->create_report(['name' => 'My courses']);
        $gen->get_plugin_generator('local_reportium')->create_level($report->id, 0,
            'SELECT c.id, c.fullname FROM {course} c WHERE c.id IN (%%MYCOURSES%%) ORDER BY c.fullname');
        // A site report: shared with every logged-in user (a role held only inside a course does not open it).
        \local_reportium\local\report_roles::set((int) $report->id, [(int) $CFG->defaultuserroleid]);

        $admin = json_decode($this->call('get_level_data', [(int) $report->id, 0, [], 0])['rows'], true);
        $this->assertContains('Not mine', array_column($admin, 'fullname'));

        $this->setUser($teacher);
        $rows = json_decode($this->call('get_level_data', [(int) $report->id, 0, [], 0])['rows'], true);
        $this->assertSame(['Mine'], array_column($rows, 'fullname'));
    }
}
