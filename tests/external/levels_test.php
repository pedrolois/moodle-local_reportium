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
 * Tests for the level web services, especially who may read data, read SQL and change things.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\external\levels
 */
final class levels_test extends \externallib_advanced_testcase {
    /** @var \stdClass */
    private $report;
    /** @var \stdClass a user with no special role */
    private $student;
    /** @var \stdClass a site manager (manage, but not writesql) */
    private $manager;

    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $cat = $gen->create_category(['name' => 'WS Cat']);
        $course = $gen->create_course(['category' => $cat->id, 'fullname' => 'WS Course']);
        $this->student = $gen->create_user();
        $gen->enrol_user($this->student->id, $course->id, 'student');
        $this->manager = $gen->create_user();
        $managerrole = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($managerrole, $this->manager->id, \context_system::instance()->id);
        $this->setAdminUser();
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
     * Admin gets the same payload shape the front-end expects.
     */
    public function test_get_level_data_admin(): void {
        $root = $this->call('get_level_data', [(int) $this->report->id, 0, [], 0]);
        $this->assertTrue($root['hasnext']);
        $rows = json_decode($root['rows'], true);
        $mapping = json_decode($root['mapping'], true);
        $this->assertContains('WS Cat', array_column($rows, 'name'));
        $this->assertSame('name', $mapping['labelcolumn']);

        $courses = $this->call('get_level_data', [(int) $this->report->id, 1, ['WS Cat'], 0]);
        $this->assertSame(['WS Course'], array_column(json_decode($courses['rows'], true), 'fullname'));
        $this->assertSame(['id', 'fullname', 'users'], array_column($courses['columns'], 'key'));
    }

    /**
     * A user without access cannot read report data.
     */
    public function test_get_level_data_denied_for_student(): void {
        $this->setUser($this->student);
        $this->expectException(\moodle_exception::class);
        $this->call('get_level_data', [(int) $this->report->id, 0, [], 0]);
    }

    /**
     * Managers can view levels but never see or edit SQL (writesql is admin-only by default).
     */
    public function test_manager_cannot_touch_sql(): void {
        $this->setUser($this->manager);
        $levels = $this->call('get_levels', [(int) $this->report->id, 0]);
        $this->assertFalse($levels['canmanagesql']);
        $this->assertTrue($levels['canconfigure']);
        foreach ($levels['levels'] as $level) {
            $this->assertSame('', $level['sqlquery']);
        }
        $this->expectException(\required_capability_exception::class);
        levels::save_level((int) $this->report->id, 1, 'SELECT 1', 'x');
    }

    /**
     * Admin sees SQL; save validates before writing; delete only removes the deepest level.
     */
    public function test_save_and_delete_level(): void {
        global $DB;
        $levels = $this->call('get_levels', [(int) $this->report->id, 0]);
        $this->assertTrue($levels['canmanagesql']);
        $this->assertStringContainsString('%%PREVIOUSVALUE%%', $levels['levels'][1]['sqlquery']);

        $bad = $this->call('save_level', [(int) $this->report->id, 3, 'SELECT nocolumn FROM {course}', 'Broken']);
        $this->assertFalse($bad['success']);
        $this->assertNotEmpty($bad['error']);
        $this->assertFalse($DB->record_exists('local_reportium_level', ['reportid' => $this->report->id, 'drilllevel' => 3]));

        $good = $this->call('save_level', [(int) $this->report->id, 3,
            'SELECT id, fullname FROM {course} WHERE id = %%PREVIOUSVALUE%%', 'Extra']);
        $this->assertTrue($good['success']);
        $this->assertTrue($DB->record_exists('local_reportium_level', ['reportid' => $this->report->id, 'drilllevel' => 3]));

        // Not the deepest → refused; deepest → deleted; root → refused.
        try {
            levels::delete_level((int) $this->report->id, 1);
            $this->fail('Deleting a middle level must fail');
        } catch (\moodle_exception $e) {
            $this->assertSame('cannotdeletelevel', $e->errorcode);
        }
        $this->assertTrue($this->call('delete_level', [(int) $this->report->id, 3])['success']);
        $this->assertFalse($DB->record_exists('local_reportium_level', ['reportid' => $this->report->id, 'drilllevel' => 3]));
    }

    /**
     * Chart settings: managers may save them, students may not; bad JSON is rejected.
     */
    public function test_save_level_config(): void {
        $this->setUser($this->manager);
        $this->assertTrue($this->call('save_level_config', [(int) $this->report->id, 1, '{"defaultview":"pie"}'])['success']);
        $courses = $this->call('get_level_data', [(int) $this->report->id, 1, ['WS Cat'], 0]);
        $this->assertSame('pie', json_decode($courses['mapping'], true)['defaultview']);

        try {
            levels::save_level_config((int) $this->report->id, 1, 'not json');
            $this->fail('Invalid JSON must be rejected');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('JSON', $e->getMessage());
        }

        $this->setUser($this->student);
        $this->expectException(\moodle_exception::class);
        levels::save_level_config((int) $this->report->id, 1, '{}');
    }
}
