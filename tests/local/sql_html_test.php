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
 * Tests of SQL that returns HTML (links built in the SQL), texts with "?" and ":" inside, %%WWWROOT%% and an own
 * LIMIT.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\level_service
 * @covers     \local_reportium\local\column_inspector
 */
final class sql_html_test extends \advanced_testcase {

    /**
     * Rows of a one-level report with this SQL.
     *
     * @param string $sql
     * @return array payload
     */
    private function payload(string $sql): array {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $report = $generator->create_report(['name' => 'SQL ' . random_string(4)]);
        $generator->create_level($report->id, 0, $sql);
        $this->assertNull(report_data_helper::validate_level_sql($report, 0, $sql), 'The SQL is accepted when saved');
        return level_service::get_level_payload($report, 0, [], \context_system::instance(), (int) get_admin()->id);
    }

    public function test_links_built_in_the_sql_are_shown_and_cleaned(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['shortname' => 'C1', 'fullname' => 'Course one']);
        $payload = $this->payload("SELECT c.id,
            CONCAT('<a href=\"%%WWWROOT%%/course/view.php?id=', c.id, '\" target=\"_blank\">', c.fullname, '</a>') AS course,
            '<b>Bold</b> <img src=x onerror=alert(1)><script>alert(2)</script><a href=\"javascript:alert(3)\">bad</a>' AS evil,
            c.shortname
            FROM {course} c WHERE c.id = " . (int) $course->id);
        $columns = array_column($payload['columns'], null, 'key');
        $this->assertTrue($columns['course']['html']);
        $this->assertTrue($columns['evil']['html']);
        $this->assertArrayNotHasKey('html', $columns['shortname']);
        $row = $payload['rows'][0];

        // The table: the link, with its "?id=" and the site address.
        $this->assertStringContainsString('href="' . $CFG->wwwroot . '/course/view.php?id=' . $course->id . '"',
            $row[column_inspector::HTML_KEY]['course']);
        $this->assertStringContainsString('>Course one</a>', $row[column_inspector::HTML_KEY]['course']);
        // Scripts, event handlers and javascript: links never reach the page.
        $evil = $row[column_inspector::HTML_KEY]['evil'];
        $this->assertStringContainsString('<b>Bold</b>', $evil);
        $this->assertStringNotContainsString('<script', $evil);
        $this->assertStringNotContainsString('onerror', $evil);
        $this->assertStringNotContainsString('javascript:', $evil);
        // Charts, search, filters and downloads: the text, without tags or the content of removed scripts.
        $this->assertSame('Course one', $row['course']);
        $this->assertSame('Bold bad', $row['evil']);
        $this->assertSame('C1', $row['shortname']);
    }

    public function test_question_marks_and_colons_inside_texts(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $payload = $this->payload("SELECT 1 AS id, 'Why? Because: yes' AS answer, 'a?b=:c' AS odd, '10:30' AS hour,
            'It''s %%WWWROOT%%?x' AS quoted, %%USERID%% AS me");
        $row = $payload['rows'][0];
        $this->assertSame('Why? Because: yes', $row['answer']);
        $this->assertSame('a?b=:c', $row['odd']);
        $this->assertSame('10:30', $row['hour']);
        global $CFG;
        $this->assertSame("It's " . $CFG->wwwroot . '?x', $row['quoted']);
        // Placeholders outside texts still work.
        $this->assertSame((string) get_admin()->id, $row['me']);
    }

    public function test_wwwroot_outside_quotes_becomes_a_text(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $payload = $this->payload("SELECT 1 AS id, CONCAT(%%WWWROOT%%, '/my/') AS dashboard");
        $this->assertSame($CFG->wwwroot . '/my/', $payload['rows'][0]['dashboard']);
    }

    public function test_an_own_limit_is_kept(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        foreach (range(1, 3) as $i) {
            $this->getDataGenerator()->create_course();
        }
        $payload = $this->payload('SELECT c.id, c.fullname FROM {course} c WHERE c.id > 1 ORDER BY c.id LIMIT 2');
        $this->assertCount(2, $payload['rows']);
        $this->assertTrue(level_service::has_own_limit('SELECT 1 LIMIT 5'));
        $this->assertTrue(level_service::has_own_limit("SELECT 1 FROM {x} LIMIT 5 OFFSET 10\n"));
        $this->assertTrue(level_service::has_own_limit('SELECT 1 LIMIT 10, 5'));
        $this->assertFalse(level_service::has_own_limit('SELECT 1 FROM {x} WHERE name = \'LIMIT 5\' ORDER BY 1'));
        $this->assertFalse(level_service::has_own_limit('SELECT 1'));
    }

    public function test_protect_and_restore_literals(): void {
        $sql = "SELECT 'a?b', 'x:y', 'it''s?', id FROM {t} WHERE a = :rpv0 AND b = '%H:%i'";
        $protected = level_service::protect_literals($sql);
        // Only inside texts; the parameters of Reportium stay.
        $this->assertStringContainsString('a = :rpv0', $protected);
        $this->assertStringNotContainsString('?', $protected);
        $this->assertStringContainsString("'x" . level_service::COLON . "y'", $protected);
        // The ":%" sequence is not a parameter for Moodle: left as it is.
        $this->assertStringContainsString("'%H:%i'", $protected);
        $this->assertSame("a?b x:y it's?", level_service::restore_literals('a' . level_service::QUESTIONMARK . 'b x'
            . level_service::COLON . "y it's" . level_service::QUESTIONMARK));
    }
}
