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
 * Tests of the column inspector: dates written as text, column labels and formats.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\column_inspector
 */
final class column_dates_test extends \advanced_testcase {
    public function test_parse_text_date(): void {
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        $dayfirst = column_inspector::parse_text_date('06/10/2026');
        $this->assertFalse($dayfirst['withtime']);
        $this->assertSame('2026-10-06', userdate($dayfirst['timestamp'], '%Y-%m-%d', 'Europe/Madrid', false));
        $iso = column_inspector::parse_text_date('2026-10-06 14:30');
        $this->assertTrue($iso['withtime']);
        $this->assertSame('2026-10-06 14:30', userdate($iso['timestamp'], '%Y-%m-%d %H:%M', 'Europe/Madrid', false));
        $this->assertTrue(column_inspector::parse_text_date('2026-10-06T14:30:15')['withtime']);
        foreach (['31/02/2026', '2026/10/06', 'today', '', '6-10-2026', '13/13/2026', '2026-10-06 25'] as $bad) {
            $this->assertNull(column_inspector::parse_text_date($bad), $bad);
        }
    }

    public function test_text_date_columns_become_dates(): void {
        $this->resetAfterTest();
        $rows = [
            ['name' => 'A', 'when' => '06/10/2026'],
            ['name' => 'B', 'when' => '07/10/2026 09:15'],
            ['name' => 'C', 'when' => ''],
        ];
        $columns = column_inspector::describe(['name', 'when'], $rows);
        $this->assertSame('date', $columns[1]['type']);
        $this->assertTrue($columns[1]['textdate']);
        $this->assertTrue($columns[1]['withtime']);
        $converted = column_inspector::convert_text_dates($columns, $rows);
        $this->assertTrue(is_numeric($converted[0]['when']));
        $this->assertGreaterThan((int) $converted[0]['when'], (int) $converted[1]['when']);
        $this->assertSame('', $converted[2]['when']);
        $this->assertSame('A', $converted[0]['name']);

        // One value that is not a date: the column stays text.
        $mixed = column_inspector::describe(['when'], [['when' => '06/10/2026'], ['when' => 'soon']]);
        $this->assertSame('text', $mixed[0]['type']);
    }

    public function test_labels_and_formats(): void {
        $this->resetAfterTest();
        $this->assertSame('Completion pct', column_inspector::humanise('completion_pct'));
        $this->assertSame('Learning path', column_inspector::humanise('learning_path'));
        $columns = column_inspector::describe(
            ['id', 'course_status', 'progress_pct', 'timecreated'],
            [['id' => '1', 'course_status' => 'completed', 'progress_pct' => '50', 'timecreated' => '1767225600']]
        );
        $bykey = array_column($columns, null, 'key');
        $this->assertSame('id', $bykey['id']['type']);
        $this->assertSame('state', $bykey['course_status']['format']);
        $this->assertSame('progress', $bykey['progress_pct']['format']);
        $this->assertSame('date', $bykey['timecreated']['type']);
        $this->assertSame(['bar', 'pie', 'line', 'table'], column_inspector::views());
    }
}
