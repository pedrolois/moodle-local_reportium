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

namespace local_reportium\task;

/**
 * Tests of the task that recalculates the grades of courses whose gradebook needs it.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\task\regrade_courses
 */
final class regrade_courses_test extends \advanced_testcase {
    /**
     * A course with a grade item and a student graded, then marked as "needs update".
     *
     * @return \stdClass course
     */
    private function course_needing_regrade(): \stdClass {
        global $CFG, $DB;
        require_once($CFG->libdir . '/gradelib.php');
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_user();
        $gen->enrol_user($student->id, $course->id, 'student');
        $item = $gen->create_grade_item(['courseid' => $course->id, 'grademax' => 10]);
        $gradeitem = \grade_item::fetch(['id' => $item->id]);
        $gradeitem->update_final_grade($student->id, 8, 'test');
        grade_regrade_final_grades($course->id);
        $DB->set_field('grade_items', 'needsupdate', 1, ['courseid' => $course->id]);
        return $course;
    }

    public function test_courses_needing_it_are_recalculated(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('regradetask', 1, 'local_reportium');
        $course = $this->course_needing_regrade();
        $this->assertTrue($DB->record_exists('grade_items', ['courseid' => $course->id, 'needsupdate' => 1]));

        $this->expectOutputRegex('/1 course\(s\) checked/');
        (new regrade_courses())->execute();
        $this->assertFalse($DB->record_exists('grade_items', ['courseid' => $course->id, 'needsupdate' => 1]));
    }

    public function test_switched_off_in_the_settings(): void {
        global $DB;
        $this->resetAfterTest();
        set_config('regradetask', 0, 'local_reportium');
        $course = $this->course_needing_regrade();

        $this->expectOutputRegex('/switched off/');
        (new regrade_courses())->execute();
        $this->assertTrue($DB->record_exists('grade_items', ['courseid' => $course->id, 'needsupdate' => 1]));
    }

    public function test_task_is_registered(): void {
        $task = \core\task\manager::get_scheduled_task(regrade_courses::class);
        $this->assertNotEmpty($task);
        $this->assertSame('*/15', $task->get_minute());
        $this->assertNotEmpty((new regrade_courses())->get_name());
    }
}
