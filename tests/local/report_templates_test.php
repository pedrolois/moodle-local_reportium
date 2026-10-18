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
 * Every ready-made template installs and every level of its SQL runs on this database engine.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\report_templates
 */
final class report_templates_test extends \advanced_testcase {
    /**
     * A small site: a category with a course, a student, a forum post, a log entry and a graded item.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $cat = $gen->create_category(['name' => 'Template Cat']);
        $course = $gen->create_course(['category' => $cat->id, 'fullname' => 'Template Course', 'enablecompletion' => 1]);
        $student = $gen->create_user(['firstname' => 'Sara', 'lastname' => 'Student']);
        $gen->enrol_user($student->id, $course->id, 'student');
        $forum = $gen->create_module('forum', ['course' => $course->id]);
        $gen->get_plugin_generator('mod_forum')->create_discussion(
            ['course' => $course->id, 'forum' => $forum->id, 'userid' => $student->id]
        );
        $assign = $gen->create_module('assign', ['course' => $course->id, 'grade' => 100]);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
            'courseid' => $course->id]);
        $item->update_final_grade($student->id, 75);
    }

    /**
     * Install all templates and walk each one from the root to its last level, clicking the first row.
     */
    public function test_all_templates_install_and_every_level_runs(): void {
        global $DB, $USER;
        $context = \context_system::instance();
        $definitions = report_templates::definitions();
        $this->assertGreaterThanOrEqual(10, count($definitions));

        foreach ($definitions as $key => $definition) {
            $reportid = report_templates::install($key, SITEID, (int) $USER->id);
            $report = $DB->get_record('local_reportium', ['id' => $reportid], '*', MUST_EXIST);
            $this->assertSame(get_string('template_' . $key . '_name', 'local_reportium'), $report->name);
            $this->assertEquals(
                count($definition['levels']),
                $DB->count_records('local_reportium_level', ['reportid' => $reportid])
            );

            $path = [];
            foreach (array_keys($definition['levels']) as $level) {
                $payload = level_service::get_level_payload($report, $level, $path, $context, (int) $USER->id);
                // Columns come from the rows: an empty level (no matching data) has none, but its SQL still ran.
                if ($payload['rows']) {
                    $this->assertNotEmpty($payload['columns'], "$key level $level returned no columns");
                }
                $this->assertSame($level < count($definition['levels']) - 1, (bool) $payload['hasnext'], "$key level $level");
                // Drill into the first row (or a value that matches nothing, to still run the next SQL).
                // Prefer the last row: the generated "Template Cat" / course sort after the default ones.
                $valuecolumn = $payload['mapping']['valuecolumn'] ?? null;
                $row = $payload['rows'] ? end($payload['rows']) : [];
                $path[] = $valuecolumn !== null ? (string) ($row[$valuecolumn] ?? '0') : '0';
            }
        }
    }

    /**
     * The completion template drills by id: category → its courses → the enrolled student.
     */
    public function test_completion_template_drills_by_id(): void {
        global $DB, $USER;
        $context = \context_system::instance();
        $report = $DB->get_record(
            'local_reportium',
            ['id' => report_templates::install('completion', SITEID, (int) $USER->id)]
        );
        $cat = $DB->get_record('course_categories', ['name' => 'Template Cat']);

        $root = level_service::get_level_payload($report, 0, [], $context, (int) $USER->id);
        $this->assertSame('id', $root['mapping']['valuecolumn']);
        $courses = level_service::get_level_payload($report, 1, [(string) $cat->id], $context, (int) $USER->id);
        $this->assertSame(['Template Course'], array_column($courses['rows'], 'course'));
        $this->assertSame('1', (string) $courses['rows'][0]['enrolled']);

        $learners = level_service::get_level_payload(
            $report,
            2,
            [(string) $cat->id, (string) $courses['rows'][0]['id']],
            $context,
            (int) $USER->id
        );
        $this->assertSame(['Sara Student'], array_column($learners['rows'], 'fullname'));
    }

    /**
     * Levels added to a template reach the reports installed from it, without touching their levels.
     */
    public function test_new_template_levels_reach_installed_reports(): void {
        global $DB, $USER;
        $id = report_templates::install('siteactivity', SITEID, (int) $USER->id);
        $count = count(report_templates::definitions()['siteactivity']['levels']);
        // An older install: without its last level, and with its first level changed by the user.
        $DB->delete_records('local_reportium_level', ['reportid' => $id, 'drilllevel' => $count - 1]);
        $DB->set_field(
            'local_reportium_level',
            'sqlquery',
            'SELECT id, username FROM {user}',
            ['reportid' => $id, 'drilllevel' => 0]
        );

        $this->assertSame(1, report_templates::add_missing_levels());
        $levels = level_service::get_levels($id);
        $this->assertCount($count, $levels);
        $this->assertSame('SELECT id, username FROM {user}', $levels[0]['sqlquery']);
        $this->assertSame(
            get_string('template_siteactivity_level' . ($count - 1), 'local_reportium'),
            $levels[$count - 1]['label']
        );
        // Nothing more to add.
        $this->assertSame(0, report_templates::add_missing_levels());
    }

    /**
     * Unknown template keys are rejected.
     */
    public function test_unknown_template(): void {
        global $USER;
        $this->expectException(\moodle_exception::class);
        report_templates::install('nope', SITEID, (int) $USER->id);
    }
}
