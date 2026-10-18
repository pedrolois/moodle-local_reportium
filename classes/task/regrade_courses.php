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
 * Recalculate the grades of the courses whose gradebook needs it.
 *
 * When a grade changes, Moodle marks the grade items of the course as "needs update" and only recalculates the
 * totals (course total, categories) when someone opens the gradebook of that course. Reports read the grades
 * from the database, so this task recalculates those courses in the background (if enabled in the plugin settings).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class regrade_courses extends \core\task\scheduled_task {
    /**
     * Name of the task.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_regrade_courses', 'local_reportium');
    }

    /**
     * Recalculate every course with grade items that need an update.
     */
    public function execute() {
        global $CFG, $DB;
        if (!get_config('local_reportium', 'regradetask')) {
            mtrace('Grade recalculation is switched off in the Reportium settings.');
            return;
        }
        require_once($CFG->libdir . '/gradelib.php');
        $courseids = $DB->get_fieldset_sql('SELECT DISTINCT courseid FROM {grade_items} WHERE needsupdate = 1');
        foreach ($courseids as $courseid) {
            try {
                $status = grade_regrade_final_grades((int) $courseid);
                if (is_array($status)) {
                    // Errors (e.g. a broken calculation): report them and stop asking again, like the gradebook does.
                    foreach ($status as $error) {
                        mtrace("Course $courseid: $error");
                    }
                    \grade_item::fetch_course_item((int) $courseid)->regrading_finished();
                } else {
                    mtrace("Course $courseid: grades recalculated.");
                }
            } catch (\Throwable $e) {
                mtrace("Course $courseid: " . $e->getMessage());
            }
        }
        mtrace(count($courseids) . ' course(s) checked.');
    }
}
