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
 * Tests of what downloads add to the rows: totals row and KPI cards, calculated columns and the user's column order.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\exporter
 * @covers     \local_reportium\local\level_service
 * @covers     \local_reportium\local\column_inspector
 */
final class exporter_summary_test extends \advanced_testcase {

    /** @var array Columns of the summary tests. */
    const COLUMNS = [
        ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'format' => 'text'],
        ['key' => 'users', 'label' => 'Users', 'type' => 'number', 'format' => 'text'],
        ['key' => 'pct', 'label' => 'Pct', 'type' => 'number', 'format' => 'progress'],
    ];

    /** @var array Rows of the summary tests. */
    const ROWS = [
        ['name' => 'A', 'users' => 10, 'pct' => 50],
        ['name' => 'B', 'users' => 30, 'pct' => 100],
        ['name' => 'B', 'users' => '', 'pct' => null],
    ];

    public function test_aggregate_of_each_calculation(): void {
        $this->assertSame(3.0, exporter::aggregate(self::ROWS, '_rows', 'count'));
        $this->assertSame(40.0, exporter::aggregate(self::ROWS, 'users', 'sum'));
        $this->assertSame(20.0, exporter::aggregate(self::ROWS, 'users', 'avg'));
        $this->assertSame(10.0, exporter::aggregate(self::ROWS, 'users', 'min'));
        $this->assertSame(30.0, exporter::aggregate(self::ROWS, 'users', 'max'));
        // Empty values are not counted.
        $this->assertSame(2.0, exporter::aggregate(self::ROWS, 'users', 'count'));
        $this->assertSame(2.0, exporter::aggregate(self::ROWS, 'name', 'distinct'));
        $this->assertNull(exporter::aggregate(self::ROWS, 'name', 'sum'));
        $this->assertNull(exporter::aggregate([], 'users', 'avg'));
    }

    public function test_aggregate_text_and_names(): void {
        $this->resetAfterTest();
        $this->assertSame('75%', exporter::aggregate_text(75.0, self::COLUMNS[2], 'avg'));
        $this->assertSame('2', exporter::aggregate_text(2.0, self::COLUMNS[2], 'count'));
        $this->assertSame('33.3', exporter::aggregate_text(100 / 3, self::COLUMNS[1], 'avg'));
        $this->assertSame('-', exporter::aggregate_text(null, self::COLUMNS[1], 'sum'));
        $date = ['key' => 'd', 'label' => 'D', 'type' => column_inspector::TYPE_DATE];
        $this->assertSame(userdate(86400 * 365, str_replace(['%y', ', '], ['%Y', ' '],
            get_string('strftimedatefullshort', 'langconfig'))), exporter::aggregate_text(86400 * 365, $date, 'max'));
        $this->assertSame('Latest', exporter::aggregate_name('max', $date));
        $this->assertSame('Earliest', exporter::aggregate_name('min', $date));
        $this->assertSame('Average', exporter::aggregate_name('avg', self::COLUMNS[1]));
    }

    public function test_clean_kpis_drops_unknown_columns_calculations_and_twins(): void {
        $kpis = exporter::clean_kpis([
            ['key' => '_rows', 'agg' => 'sum'],
            ['key' => 'users', 'agg' => 'sum'],
            ['key' => 'users', 'agg' => 'sum'],
            ['key' => 'users', 'agg' => 'avg'],
            ['key' => 'gone', 'agg' => 'sum'],
            ['key' => 'pct', 'agg' => 'median'],
            'nonsense',
        ], self::COLUMNS);
        $this->assertSame([['key' => '_rows', 'agg' => 'count'], ['key' => 'users', 'agg' => 'sum'],
            ['key' => 'users', 'agg' => 'avg']], $kpis);
    }

    public function test_summary_lines_hold_the_totals_row_and_the_cards(): void {
        $this->resetAfterTest();
        $kpis = exporter::clean_kpis([['key' => '_rows', 'agg' => 'count'], ['key' => 'users', 'agg' => 'sum'],
            ['key' => 'pct', 'agg' => 'avg']], self::COLUMNS);
        $lines = exporter::summary_lines(self::COLUMNS, self::ROWS, $kpis, true);
        // Totals row: one value per column, its label in the first one.
        $this->assertSame(['Totals', '40', '75%'], $lines[0]);
        // Then a blank line, the title and one line per card.
        $this->assertSame(['', '', ''], $lines[1]);
        $this->assertSame(['KPI cards', '', ''], $lines[2]);
        $this->assertSame(['Rows', '3', ''], $lines[3]);
        $this->assertSame(['Users', '40', 'Total'], $lines[4]);
        $this->assertSame(['Pct', '75%', 'Average'], $lines[5]);
        // Without the totals row, only the cards.
        $this->assertSame(['', '', ''], exporter::summary_lines(self::COLUMNS, self::ROWS, $kpis, false)[0]);
        // Nothing without cards or rows.
        $this->assertSame([], exporter::summary_lines(self::COLUMNS, self::ROWS, [], true));
        $this->assertSame([], exporter::summary_lines(self::COLUMNS, [], $kpis, true));
    }

    public function test_lines_are_as_wide_as_a_narrow_table(): void {
        $this->resetAfterTest();
        $two = [['key' => 'name', 'label' => 'Name', 'type' => 'text'], ['key' => 'n', 'label' => 'N', 'type' => 'number']];
        $rows = [['name' => 'A', 'n' => 2], ['name' => 'B', 'n' => 3]];
        $kpis = [['key' => '_rows', 'agg' => 'count'], ['key' => 'n', 'agg' => 'sum']];
        foreach (exporter::summary_lines($two, $rows, $kpis, true) as $line) {
            $this->assertCount(2, $line);
        }
        $this->assertSame(['N', '5'], exporter::summary_lines($two, $rows, $kpis, true)[4]);
        $one = [['key' => 'n', 'label' => 'N', 'type' => 'number']];
        $lines = exporter::summary_lines($one, $rows, [['key' => 'n', 'agg' => 'sum']], false);
        foreach ($lines as $line) {
            $this->assertCount(1, $line);
        }
        $this->assertSame(['N: 5'], end($lines));
    }

    public function test_the_first_column_with_a_calculation_keeps_its_label(): void {
        $this->resetAfterTest();
        $columns = [['key' => 'n', 'label' => 'N', 'type' => 'number', 'format' => 'text']];
        $lines = exporter::summary_lines($columns, [['n' => 2], ['n' => 3]], [['key' => 'n', 'agg' => 'sum']], true);
        $this->assertSame(['Totals: 5'], $lines[0]);
    }

    public function test_user_order_moves_the_dragged_columns_first(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $columns = [['key' => 'a'], ['key' => 'b'], ['key' => 'c'], ['key' => 'd']];
        $keys = fn(array $list) => array_column($list, 'key');
        // No preference: the order of the SQL.
        $this->assertSame(['a', 'b', 'c', 'd'], $keys(exporter::user_order($columns, 7, 0, $user->id)));
        set_user_preference(exporter::ORDER_PREFERENCE . 7, json_encode(['0' => ['c', 'a', 'gone'], '1' => ['d']]), $user);
        // Columns not in the order keep theirs, after the others.
        $this->assertSame(['c', 'a', 'b', 'd'], $keys(exporter::user_order($columns, 7, 0, $user->id)));
        $this->assertSame(['d', 'a', 'b', 'c'], $keys(exporter::user_order($columns, 7, 1, $user->id)));
        // Another level, another report or another user: untouched.
        $this->assertSame(['a', 'b', 'c', 'd'], $keys(exporter::user_order($columns, 7, 2, $user->id)));
        $this->assertSame(['a', 'b', 'c', 'd'], $keys(exporter::user_order($columns, 8, 0, $user->id)));
        $this->assertSame(['a', 'b', 'c', 'd'], $keys(exporter::user_order($columns, 7, 0, get_admin()->id)));
        // A broken preference is ignored.
        set_user_preference(exporter::ORDER_PREFERENCE . 7, '{broken', $user);
        $this->assertSame(['a', 'b', 'c', 'd'], $keys(exporter::user_order($columns, 7, 0, $user->id)));
    }

    public function test_the_preference_is_the_users_own(): void {
        $this->resetAfterTest();
        $definition = \core_user::get_preference_definition(exporter::ORDER_PREFERENCE . 12);
        $this->assertSame(PARAM_RAW, $definition['type']);
        $this->assertSame('{}', $definition['default']);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        $this->assertTrue(\core_user::can_edit_preference(exporter::ORDER_PREFERENCE . 12, $user));
        $this->assertFalse(\core_user::can_edit_preference(exporter::ORDER_PREFERENCE . 12, $other));
    }

    public function test_view_rows_hold_the_calculated_columns_in_the_users_order(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $report = $generator->create_report();
        $generator->create_level($report->id, 0, "SELECT 'A' AS name, 2 AS done, 4 AS total UNION ALL SELECT 'B', 3, 3",
            ['calculated' => [['key' => 'calc_rate', 'label' => 'Rate', 'formula' => 'pct([done], [total])',
                'format' => 'progress']]]);
        set_user_preference(exporter::ORDER_PREFERENCE . $report->id, json_encode(['0' => ['calc_rate', 'name']]));
        [$columns, $rows] = exporter::view_rows($report, \context_system::instance(), get_admin()->id, ['level' => 0]);
        $this->assertSame(['calc_rate', 'name', 'done', 'total'], array_column($columns, 'key'));
        $this->assertSame([50.0, 100.0], array_column($rows, 'calc_rate'));
        // Changed on screen and not saved yet: the view brings its own.
        [$columns, $rows] = exporter::view_rows($report, \context_system::instance(), get_admin()->id, ['level' => 0,
            'override' => ['calculated' => [['key' => 'calc_left', 'label' => 'Left', 'formula' => '[total] - [done]']]]]);
        $this->assertContains('calc_left', array_column($columns, 'key'));
        $this->assertNotContains('calc_rate', array_column($columns, 'key'));
        $this->assertSame([2.0, 0.0], array_column($rows, 'calc_left'));
        // Filters and sort work on a calculated column too.
        [, $rows] = exporter::view_rows($report, \context_system::instance(), get_admin()->id, ['level' => 0,
            'filters' => ['calc_rate' => ['min' => 60]]]);
        $this->assertSame(['B'], array_column($rows, 'name'));
        [, $rows] = exporter::view_rows($report, \context_system::instance(), get_admin()->id, ['level' => 0,
            'sort' => 'calc_rate:desc']);
        $this->assertSame(['B', 'A'], array_column($rows, 'name'));
    }

    public function test_level_payload_keeps_the_definitions_in_its_mapping(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $report = $generator->create_report();
        $generator->create_level($report->id, 0, "SELECT 'A' AS name, 2 AS done",
            ['calculated' => [['key' => 'calc_x', 'label' => 'X', 'formula' => '[done] * 10'],
                ['key' => 'calc_bad', 'label' => 'Bad', 'formula' => 'system(1)']],
                'kpis' => [['key' => 'calc_x', 'agg' => 'sum']]]);
        $payload = level_service::get_level_payload($report, 0, [], \context_system::instance(), get_admin()->id);
        $this->assertSame(['name', 'done', 'calc_x'], array_column($payload['columns'], 'key'));
        $this->assertSame(20.0, $payload['rows'][0]['calc_x']);
        $this->assertSame([['key' => 'calc_x', 'label' => 'X', 'formula' => '[done] * 10', 'format' => 'text']],
            $payload['mapping']['calculated']);
        // A KPI card may use a calculated column.
        $this->assertSame([['key' => 'calc_x', 'agg' => 'sum']], $payload['mapping']['kpis']);
    }
}
