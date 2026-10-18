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

namespace local_reportium\output;

/**
 * Tests of the in-place editable columns of the Reports manager (chart types) and their callback.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\output\report_views_editable
 * @covers     ::local_reportium_inplace_editable
 */
final class editable_test extends \advanced_testcase {
    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/reportium/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_demo_report();
    }

    public function test_chart_types_are_saved_in_toolbar_order(): void {
        global $DB, $PAGE;
        $PAGE->set_context(\context_system::instance());
        $result = local_reportium_inplace_editable('report_views', $this->report->id, json_encode(['table', 'bar', 'radar']));
        $this->assertInstanceOf(report_views_editable::class, $result);
        $this->assertSame('bar,table', $DB->get_field('local_reportium', 'views', ['id' => $this->report->id]));
        $this->assertSame(['bar', 'table'], report_views_editable::chosen(
            $DB->get_record('local_reportium', ['id' => $this->report->id])
        ));
    }

    public function test_all_or_none_means_every_chart_type(): void {
        global $DB, $PAGE;
        $PAGE->set_context(\context_system::instance());
        local_reportium_inplace_editable('report_views', $this->report->id, json_encode(['bar', 'pie', 'line', 'table']));
        $this->assertNull($DB->get_field('local_reportium', 'views', ['id' => $this->report->id]));
        local_reportium_inplace_editable('report_views', $this->report->id, json_encode([]));
        $this->assertNull($DB->get_field('local_reportium', 'views', ['id' => $this->report->id]));
        $report = $DB->get_record('local_reportium', ['id' => $this->report->id]);
        $this->assertSame(['bar', 'pie', 'line', 'table'], report_views_editable::chosen($report));
    }

    public function test_export_for_template(): void {
        global $PAGE, $DB;
        $PAGE->set_context(\context_system::instance());
        $DB->set_field('local_reportium', 'views', 'pie', ['id' => $this->report->id]);
        $data = (new report_views_editable($DB->get_record('local_reportium', ['id' => $this->report->id])))
            ->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame('local_reportium', $data['component']);
        $this->assertSame('report_views', $data['itemtype']);
        $this->assertSame(json_encode(['pie']), $data['value']);
        $this->assertSame(get_string('ui_pie', 'local_reportium'), $data['displayvalue']);
    }

    public function test_users_who_cannot_edit_are_refused(): void {
        global $PAGE;
        $PAGE->set_context(\context_system::instance());
        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\moodle_exception::class);
        local_reportium_inplace_editable('report_views', $this->report->id, json_encode(['bar']));
    }

    public function test_roles_through_the_callback(): void {
        global $DB, $PAGE;
        $PAGE->set_context(\context_system::instance());
        $teacher = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        local_reportium_inplace_editable('report_roles', $this->report->id, json_encode([$teacher]));
        $this->assertSame([$teacher], \local_reportium\local\report_roles::get((int) $this->report->id));
    }
}
