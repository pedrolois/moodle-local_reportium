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

use moodle_exception;

/**
 * Tests of calculated columns: the formula grammar, its calculation row by row, and the validation of the settings.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\formula
 */
final class formula_test extends \basic_testcase {

    /** @var array Columns of the tests. */
    const COLUMNS = [
        ['key' => 'done', 'label' => 'Done', 'type' => 'number'],
        ['key' => 'total', 'label' => 'Total enrolled', 'type' => 'number'],
        ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
    ];

    /**
     * Value of a formula for a row.
     *
     * @param string $formula
     * @param array $row
     * @return float|null
     */
    private function value(string $formula, array $row = ['done' => 3, 'total' => 4, 'name' => 'Ana']): ?float {
        $refs = ['done' => 'done', 'total' => 'total', 'name' => 'name', 'total enrolled' => 'total', 'done ' => 'done'];
        return formula::evaluate(formula::parse($formula, $refs), $row);
    }

    public function test_arithmetic_follows_precedence_and_brackets(): void {
        $this->assertEqualsWithDelta(14.0, $this->value('2 + 3 * 4'), 1e-9);
        $this->assertEqualsWithDelta(20.0, $this->value('(2 + 3) * 4'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->value('10 - 4 - 5'), 1e-9);
        $this->assertEqualsWithDelta(2.5, $this->value('10 / 2 / 2'), 1e-9);
        $this->assertEqualsWithDelta(-7.0, $this->value('-(3 + 4)'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->value('- -1'), 1e-9);
        // Decimal comma, as typed in many languages.
        $this->assertEqualsWithDelta(1.5, $this->value('1,5'), 1e-9);
    }

    public function test_columns_by_key_or_label(): void {
        $this->assertEqualsWithDelta(75.0, $this->value('[done] / [total] * 100'), 1e-9);
        $this->assertEqualsWithDelta(75.0, $this->value('[done] / [Total enrolled] * 100'), 1e-9);
        $this->assertEqualsWithDelta(7.0, $this->value('[ done ] + [TOTAL ENROLLED]'), 1e-9);
    }

    public function test_functions(): void {
        $this->assertEqualsWithDelta(75.0, $this->value('pct([done], [total])'), 1e-9);
        $this->assertEqualsWithDelta(3.33, $this->value('round(10 / 3, 2)'), 1e-9);
        $this->assertEqualsWithDelta(3.0, $this->value('round(2.5)'), 1e-9);
        $this->assertEqualsWithDelta(-3.0, $this->value('round(-2.5)'), 1e-9);
        $this->assertEqualsWithDelta(5.0, $this->value('abs(-5)'), 1e-9);
        $this->assertEqualsWithDelta(1.0, $this->value('min(4, 1, 3)'), 1e-9);
        $this->assertEqualsWithDelta(4.0, $this->value('max([done], [total])'), 1e-9);
        $this->assertEqualsWithDelta(4.0, $this->value('MAX([done], [total])'), 1e-9);
    }

    public function test_empty_text_and_division_by_zero_give_no_value(): void {
        $this->assertNull($this->value('[done] / [total]', ['done' => 3, 'total' => 0]));
        $this->assertNull($this->value('pct([done], [total])', ['done' => 3, 'total' => 0]));
        $this->assertNull($this->value('[done] + 1', ['done' => '', 'total' => 1]));
        $this->assertNull($this->value('[done] + 1', ['done' => null, 'total' => 1]));
        $this->assertNull($this->value('[name] * 2'));
        $this->assertNull($this->value('max([done], [name])'));
        // Numbers written as text are numbers.
        $this->assertEqualsWithDelta(4.5, $this->value('[done] + 1', ['done' => '3.5', 'total' => 1]), 1e-9);
    }

    /**
     * Formulas that are not valid (or try to run something).
     *
     * @return array
     */
    public static function invalid_formulas(): array {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'unclosed bracket' => ['(1 + 2'],
            'extra bracket' => ['1 + 2)'],
            'dangling operator' => ['1 +'],
            'two operators' => ['1 * / 2'],
            'two numbers' => ['1 2'],
            'unknown function' => ['system(1)'],
            'php call' => ['exec("dir")'],
            'function without brackets' => ['round 2'],
            'too many arguments' => ['abs(1, 2)'],
            'too few arguments' => ['pct(1)'],
            'sql' => ['1; DROP TABLE mdl_user'],
            'quotes' => ["'1' + 1"],
            'power' => ['2 ^ 3'],
            'variable' => ['$CFG'],
            'empty brackets' => ['[] + 1'],
            'too long' => [str_repeat('1+', 300) . '1'],
        ];
    }

    /**
     * Invalid formulas are refused.
     *
     * @dataProvider invalid_formulas
     * @param string $formula
     */
    public function test_invalid_formulas_are_refused(string $formula): void {
        $this->expectException(moodle_exception::class);
        $this->value($formula);
    }

    public function test_an_unknown_column_is_named_in_the_error(): void {
        try {
            $this->value('[nope] + 1');
            $this->fail('Unknown column accepted');
        } catch (moodle_exception $e) {
            $this->assertSame('formulaunknowncolumn', $e->errorcode);
            $this->assertStringContainsString('nope', $e->getMessage());
        }
    }

    public function test_clean_list_keeps_valid_columns_only(): void {
        $list = [
            ['key' => 'calc_rate', 'label' => 'Rate', 'formula' => 'pct([done], [total])', 'format' => 'progress'],
            ['key' => 'calc_twice', 'label' => 'Twice', 'formula' => '[calc_rate] * 2', 'format' => 'weird'],
            ['key' => 'calc_bad', 'label' => 'Bad', 'formula' => 'system(1)'],
            ['key' => 'calc_unknown', 'label' => 'Unknown', 'formula' => '[nope]'],
            ['key' => 'done', 'label' => 'Clash', 'formula' => '1'],
            ['key' => 'calc_UPPER', 'label' => 'Upper', 'formula' => '1'],
            ['key' => 'calc_noname', 'label' => '  ', 'formula' => '1'],
            ['key' => 'calc_html', 'label' => '<b>Bold</b>', 'formula' => '1'],
            'not an array',
        ];
        $clean = formula::definitions($list, self::COLUMNS);
        $this->assertSame(['calc_rate', 'calc_twice', 'calc_html'], array_column($clean, 'key'));
        $this->assertSame('progress', $clean[0]['format']);
        // Unknown formats fall back to a number.
        $this->assertSame('text', $clean[1]['format']);
        $this->assertSame('Bold', $clean[2]['label']);
        $this->assertArrayNotHasKey('tree', $clean[0]);
        $this->assertSame([], formula::definitions('nope', self::COLUMNS));
    }

    public function test_a_column_cannot_use_a_later_one(): void {
        $list = [
            ['key' => 'calc_a', 'label' => 'A', 'formula' => '[calc_b] + 1'],
            ['key' => 'calc_b', 'label' => 'B', 'formula' => '1'],
        ];
        $this->assertSame(['calc_b'], array_column(formula::definitions($list, self::COLUMNS), 'key'));
    }

    public function test_at_most_ten_columns(): void {
        $list = [];
        for ($i = 0; $i < 15; $i++) {
            $list[] = ['key' => 'calc_' . $i, 'label' => 'C' . $i, 'formula' => (string) $i];
        }
        $this->assertCount(formula::MAX_COLUMNS, formula::definitions($list, self::COLUMNS));
    }

    public function test_apply_adds_the_columns_and_their_values(): void {
        $rows = [['done' => 3, 'total' => 4, 'name' => 'Ana'], ['done' => 1, 'total' => 3, 'name' => 'Ben'],
            ['done' => 1, 'total' => 0, 'name' => 'Cy']];
        $list = [['key' => 'calc_rate', 'label' => 'Rate', 'formula' => 'pct([done], [total])', 'format' => 'progress']];
        [$columns, $rows] = formula::apply(self::COLUMNS, $rows, $list);
        $added = end($columns);
        $this->assertSame('calc_rate', $added['key']);
        $this->assertSame(column_inspector::TYPE_NUMBER, $added['type']);
        $this->assertSame('progress', $added['format']);
        $this->assertTrue($added['calculated']);
        // Rounded to two decimals; nothing on a division by zero.
        $this->assertSame([75.0, 33.33, null], array_column($rows, 'calc_rate'));
        // Applying again replaces the columns calculated before.
        [$columns] = formula::apply($columns, $rows, $list);
        $this->assertCount(4, $columns);
        [$columns] = formula::apply($columns, $rows, []);
        $this->assertCount(3, $columns);
    }
}
