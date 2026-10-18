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

use core_external\external_api;

/**
 * Tests of the instructions file of a report (an AGENTS.md to paste into any AI chat outside Moodle).
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\external\chat
 * @covers     \local_reportium\local\ai_instructions
 */
final class chat_test extends \advanced_testcase {
    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_demo_report();
    }

    public function test_prompt_file_for_an_ai_chat_outside_moodle(): void {
        $result = external_api::clean_returnvalue(
            chat::prompt_file_returns(),
            chat::prompt_file((int) $this->report->id, 0, 1)
        );
        $this->assertSame('AGENTS-Demo-drill-report.md', $result['filename']);
        $content = $result['content'];
        $this->assertStringStartsWith('# Reportium report: Demo drill report', $content);
        foreach (
            ['## What to do', '## What to return', '## Rules for the SQL', '## The levels of this report',
                '### Level 1', '### Level 2 (the user is editing this level now)', '### Level 3', '%%PREVIOUSVALUE%%',
                '%%LEVELVALUE:n%%', '%%MYCOURSES%%', '%%USERID%%', '%%WWWROOT%%', '%%STARTTIME%%', 'State codes'] as $part
        ) {
            $this->assertStringContainsString($part, $content);
        }
    }

    public function test_prompt_file_of_a_report_without_sql(): void {
        $empty = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_report(['name' => 'Empty']);
        $content = chat::prompt_file((int) $empty->id, 0, -1)['content'];
        $this->assertStringContainsString('No SQL saved yet', $content);
        $this->assertStringNotContainsString('editing this level now', $content);
    }

    public function test_prompt_file_needs_sql_rights(): void {
        global $DB;

        $this->assertNotSame('', chat::prompt_file((int) $this->report->id)['content']);
        // A manager may use the assistant and manage reports, but not write SQL: no file (it holds the SQL).
        $manager = $this->getDataGenerator()->create_user();
        role_assign($DB->get_field('role', 'id', ['shortname' => 'manager']), $manager->id, \context_system::instance()->id);
        $this->setUser($manager);
        $this->expectException(\moodle_exception::class);
        chat::prompt_file((int) $this->report->id);
    }
}
