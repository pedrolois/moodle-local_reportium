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
 * Tests for the column inspector (type detection and automatic chart mapping).
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\column_inspector
 */
final class column_inspector_test extends \basic_testcase {

    /**
     * Data provider for type detection.
     *
     * @return array
     */
    public static function type_provider(): array {
        return [
            'id column' => ['id', ['1', '2', '30'], column_inspector::TYPE_ID],
            'foreign id' => ['userid', ['5', '7'], column_inspector::TYPE_ID],
            'snake foreign id' => ['course_id', ['5'], column_inspector::TYPE_ID],
            'count' => ['enrolled_users', ['101', '3', '0'], column_inspector::TYPE_NUMBER],
            'decimal' => ['progress', ['7.8', '0', '100'], column_inspector::TYPE_NUMBER],
            'timestamp' => ['timecreated', ['1700000000', '0'], column_inspector::TYPE_DATE],
            'small number named like a date' => ['startcount', ['3', '4'], column_inspector::TYPE_NUMBER],
            'text' => ['fullname', ['Ana', 'Bob'], column_inspector::TYPE_TEXT],
            'mixed' => ['code', ['12', 'A3'], column_inspector::TYPE_TEXT],
            'empty column' => ['notes', [], column_inspector::TYPE_TEXT],
            'id-like text' => ['idnumber', ['ABC', 'X1'], column_inspector::TYPE_TEXT],
        ];
    }

    /**
     * Column types are detected from name and values.
     *
     * @dataProvider type_provider
     * @param string $key
     * @param array $values
     * @param string $expected
     */
    public function test_detect_type(string $key, array $values, string $expected): void {
        $this->assertSame($expected, column_inspector::detect_type($key, $values));
    }

    /**
     * Describe ignores empty values and humanises labels.
     */
    public function test_describe(): void {
        $rows = [
            ['id' => '1', 'fullname' => 'course 1', 'enrolled_users' => '10'],
            ['id' => '2', 'fullname' => 'course 2', 'enrolled_users' => ''],
        ];
        $columns = column_inspector::describe(['id', 'fullname', 'enrolled_users'], $rows);
        $this->assertSame(['id', 'text', 'number'], array_column($columns, 'type'));
        $this->assertSame('Enrolled users', $columns[2]['label']);
    }

    /**
     * Default mapping: first text column as label, id passed down, numbers as metrics.
     */
    public function test_default_mapping(): void {
        $columns = [
            ['key' => 'id', 'label' => 'Id', 'type' => 'id'],
            ['key' => 'fullname', 'label' => 'Fullname', 'type' => 'text'],
            ['key' => 'users', 'label' => 'Users', 'type' => 'number'],
            ['key' => 'completed', 'label' => 'Completed', 'type' => 'number'],
        ];
        $mapping = column_inspector::default_mapping($columns);
        $this->assertSame('fullname', $mapping['labelcolumn']);
        $this->assertSame('id', $mapping['valuecolumn']);
        $this->assertSame(['users', 'completed'], $mapping['metrics']);
        $this->assertSame('bar', $mapping['defaultview']);
    }

    /**
     * Without an id column, the label itself is passed down; a date label defaults to a line chart.
     */
    public function test_default_mapping_without_id_and_with_date(): void {
        $mapping = column_inspector::default_mapping([
            ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
            ['key' => 'logins', 'label' => 'Logins', 'type' => 'number'],
        ]);
        $this->assertSame('day', $mapping['labelcolumn']);
        $this->assertSame('day', $mapping['valuecolumn']);
        $this->assertSame('line', $mapping['defaultview']);
    }

    /**
     * Saved overrides win, unknown columns and views are dropped.
     */
    public function test_merge_mapping(): void {
        $columns = [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'a', 'label' => 'A', 'type' => 'number'],
            ['key' => 'b', 'label' => 'B', 'type' => 'number'],
        ];
        $defaults = column_inspector::default_mapping($columns);
        $merged = column_inspector::merge_mapping($defaults, [
            'metrics' => ['b', 'gone'],
            'labelcolumn' => 'missing',
            'defaultview' => 'pie',
            'pagesize' => '-3',
            'stacked' => 1,
        ], $columns);
        $this->assertSame(['b'], $merged['metrics']);
        $this->assertSame('name', $merged['labelcolumn']);
        $this->assertSame('pie', $merged['defaultview']);
        $this->assertSame(1, $merged['pagesize']);
        $this->assertTrue($merged['stacked']);

        // Combined labels "column|other column" are accepted when both exist.
        $combo = column_inspector::merge_mapping($defaults, ['labelcolumn' => 'name|a'], $columns);
        $this->assertSame('name|a', $combo['labelcolumn']);
        $badcombo = column_inspector::merge_mapping($defaults, ['labelcolumn' => 'name|gone'], $columns);
        $this->assertSame('name', $badcombo['labelcolumn']);

        $invalid = column_inspector::merge_mapping($defaults, ['defaultview' => 'radar', 'metrics' => ['gone']], $columns);
        $this->assertSame('bar', $invalid['defaultview']);
        $this->assertSame(['a', 'b'], $invalid['metrics']);
    }
}
