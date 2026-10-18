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
 * Tests of the instructions file for AI chats outside Moodle: the generic file, and values that look personal or
 * secret replaced by examples.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\ai_instructions
 */
final class ai_instructions_test extends \advanced_testcase {

    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_demo_report();
    }

    public function test_generic_prompt_file_without_a_report(): void {
        $content = ai_instructions::prompt_file(null);
        $this->assertStringStartsWith('# Reportium reports', $content);
        $this->assertStringContainsString('pastes here the SQL of the levels', $content);
        $this->assertStringContainsString('%%PREVIOUSVALUE%%', $content);
        $this->assertStringNotContainsString('### Level', $content);
        $this->assertSame('AGENTS-Reportium.md', ai_instructions::prompt_filename(null));
    }

    public function test_values_that_look_personal_do_not_leave_the_site(): void {
        global $CFG, $DB;
        $sql = "SELECT u.id, u.email FROM {user} u WHERE u.email <> 'jane.doe@example.org' AND u.lastip <> '10.20.30.40'"
            . " AND u.secret <> 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8' AND u.firstname <> 'Jane'";
        [$redacted, $count] = ai_instructions::redact($sql);
        $this->assertSame(3, $count);
        $this->assertStringNotContainsString('jane.doe@example.org', $redacted);
        $this->assertStringNotContainsString('10.20.30.40', $redacted);
        $this->assertStringNotContainsString('a1b2c3d4e5f6', $redacted);
        $this->assertStringContainsString("'user@example.com'", $redacted);
        $this->assertStringContainsString("'192.0.2.1'", $redacted);
        $this->assertStringContainsString("'REDACTED'", $redacted);
        // Ordinary SQL is left alone.
        $this->assertSame([ 'SELECT c.id, c.fullname FROM {course} c WHERE c.id > 1', 0],
            ai_instructions::redact('SELECT c.id, c.fullname FROM {course} c WHERE c.id > 1'));

        // In the file: the SQL is redacted, a note says so, and the address of the site is not there.
        $DB->set_field('local_reportium_level', 'sqlquery', $sql, ['reportid' => $this->report->id, 'drilllevel' => 0]);
        $file = ai_instructions::prompt_file($this->report);
        $this->assertStringNotContainsString('jane.doe@example.org', $file);
        $this->assertStringContainsString('never data from the database', $file);
        $this->assertStringContainsString('replaced by examples', $file);
        $this->assertStringNotContainsString($CFG->wwwroot, $file);
    }
}
