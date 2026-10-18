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

namespace local_reportium\event;

use local_reportium\local\sql_history;

/**
 * Tests of the events of the SQL history: what they record and how they read in the site logs.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\event\level_saved
 * @covers     \local_reportium\event\level_deleted
 * @covers     \local_reportium\event\level_restored
 */
final class level_events_test extends \advanced_testcase {

    public function test_events_of_a_course_report(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_report(['name' => 'Events',
            'courseid' => $course->id]);
        $sink = $this->redirectEvents();
        $versionid = sql_history::record($report, 1, '', 'SELECT 1 AS id', 'saved', (int) get_admin()->id);
        sql_history::record($report, 1, '', 'SELECT 1 AS id', 'deleted', (int) get_admin()->id);
        sql_history::record($report, 1, '', 'SELECT 1 AS id', 'restored', (int) get_admin()->id);
        $events = $sink->get_events();
        $sink->close();

        $this->assertInstanceOf(level_saved::class, $events[0]);
        $this->assertInstanceOf(level_deleted::class, $events[1]);
        $this->assertInstanceOf(level_restored::class, $events[2]);
        foreach ($events as $event) {
            // In the course of the report, about the report, level 2.
            $this->assertSame(\context_course::instance($course->id)->id, $event->contextid);
            $this->assertSame((int) $report->id, (int) $event->objectid);
            $this->assertSame('local_reportium', $event->objecttable);
            $this->assertSame(1, (int) $event->other['level']);
            $this->assertStringContainsString('level 2 of the report with id \'' . $report->id . '\'', $event->get_description());
            $this->assertStringEndsWith('/local/reportium/viewreport.php', $event->get_url()->get_path(false));
            $this->assertSame((string) $report->id, (string) $event->get_url()->get_param('id'));
            $this->assertSame(\core\event\base::LEVEL_OTHER, $event->edulevel);
        }
        $this->assertSame($versionid, (int) $events[0]->other['versionid']);
        $this->assertSame(['u', 'd', 'u'], array_map(fn($e) => $e->crud, $events));
        $this->assertStringContainsString('saved the SQL of', $events[0]->get_description());
        $this->assertStringContainsString('deleted', $events[1]->get_description());
        $this->assertStringContainsString('restored an earlier SQL of', $events[2]->get_description());
        $this->assertSame('Report level SQL saved', level_saved::get_name());
    }

    public function test_the_level_is_required(): void {
        $this->resetAfterTest();
        $this->expectException(\coding_exception::class);
        level_saved::create(['context' => \context_system::instance(), 'objectid' => 1, 'other' => []]);
    }
}
