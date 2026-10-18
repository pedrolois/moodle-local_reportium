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
 * Tests of periods (%%STARTTIME%% / %%ENDTIME%%): presets, custom dates, previous period, and their use in levels.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\date_range
 */
final class date_range_test extends \advanced_testcase {

    /**
     * A period as readable dates.
     *
     * @param int[] $range
     * @param \DateTimeZone $tz
     * @return string
     */
    private function dates(array $range, \DateTimeZone $tz): string {
        return (new \DateTime('@' . $range[0]))->setTimezone($tz)->format('Y-m-d H:i:s') . ' / '
            . (new \DateTime('@' . $range[1]))->setTimezone($tz)->format('Y-m-d H:i:s');
    }

    public function test_presets_relative_to_now(): void {
        $tz = new \DateTimeZone('Europe/Madrid');
        $now = (new \DateTime('2026-10-10 15:30:00', $tz))->getTimestamp();
        $cases = [
            'today' => '2026-10-10 00:00:00 / 2026-10-10 23:59:59',
            'yesterday' => '2026-10-09 00:00:00 / 2026-10-09 23:59:59',
            'last7' => '2026-10-04 00:00:00 / 2026-10-10 23:59:59',
            'last30' => '2026-09-11 00:00:00 / 2026-10-10 23:59:59',
            'thisweek' => '2026-10-05 00:00:00 / 2026-10-11 23:59:59',
            'lastweek' => '2026-09-28 00:00:00 / 2026-10-04 23:59:59',
            'thismonth' => '2026-10-01 00:00:00 / 2026-10-31 23:59:59',
            'lastmonth' => '2026-09-01 00:00:00 / 2026-09-30 23:59:59',
            'thisquarter' => '2026-10-01 00:00:00 / 2026-12-31 23:59:59',
            'lastquarter' => '2026-07-01 00:00:00 / 2026-09-30 23:59:59',
            'thisyear' => '2026-01-01 00:00:00 / 2026-12-31 23:59:59',
            'lastyear' => '2025-01-01 00:00:00 / 2025-12-31 23:59:59',
        ];
        foreach ($cases as $preset => $expected) {
            $this->assertSame($expected, $this->dates(date_range::resolve($preset, $now, 0, 0, $tz), $tz), $preset);
        }
        $this->assertSame([0, date_range::ALL_END], date_range::resolve('', $now, 0, 0, $tz));
        $this->assertSame([0, date_range::ALL_END], date_range::resolve('nonsense', $now, 0, 0, $tz));
    }

    public function test_custom_dates_cover_whole_days(): void {
        $tz = new \DateTimeZone('Europe/Madrid');
        $from = (new \DateTime('2026-03-05 12:00:00', $tz))->getTimestamp();
        $to = (new \DateTime('2026-03-07 09:00:00', $tz))->getTimestamp();
        $this->assertSame('2026-03-05 00:00:00 / 2026-03-07 23:59:59',
            $this->dates(date_range::resolve('custom', 0, $from, $to, $tz), $tz));
        // Swapped dates are put in order; no dates = all time.
        $this->assertSame('2026-03-05 00:00:00 / 2026-03-07 23:59:59',
            $this->dates(date_range::resolve('custom', 0, $to, $from, $tz), $tz));
        $this->assertSame([0, date_range::ALL_END], date_range::resolve('custom', 0, 0, 0, $tz));
    }

    public function test_previous_period(): void {
        $tz = new \DateTimeZone('Europe/Madrid');
        $now = (new \DateTime('2026-10-10 15:30:00', $tz))->getTimestamp();
        $cases = [
            'lastmonth' => '2026-08-01 00:00:00 / 2026-08-31 23:59:59',
            'thisquarter' => '2026-07-01 00:00:00 / 2026-09-30 23:59:59',
            'lastyear' => '2024-01-01 00:00:00 / 2024-12-31 23:59:59',
            'last7' => '2026-09-27 00:00:00 / 2026-10-03 23:59:59',
            // Across the change to winter time (25/10/2026): still whole days.
            'last30' => '2026-08-12 00:00:00 / 2026-09-10 23:59:59',
        ];
        foreach ($cases as $preset => $expected) {
            [$start, $end] = date_range::resolve($preset, $now, 0, 0, $tz);
            $this->assertSame($expected, $this->dates(date_range::previous($preset, $start, $end, $tz), $tz), $preset);
        }
        $this->assertNull(date_range::previous('', 0, date_range::ALL_END, $tz));
    }

    public function test_options_label_and_detection(): void {
        $this->resetAfterTest();
        $options = date_range::options();
        $this->assertSame('All time', $options['']);
        $this->assertArrayHasKey('custom', $options);
        $this->assertArrayNotHasKey('custom', date_range::options(false));
        $this->assertTrue(date_range::valid('last30'));
        $this->assertFalse(date_range::valid('forever'));
        $this->assertSame('', date_range::label(0, date_range::ALL_END));
        $this->assertTrue(date_range::used_in('WHERE l.timecreated BETWEEN %%STARTTIME%% AND %%ENDTIME%%'));
        $this->assertFalse(date_range::used_in('SELECT 1'));
    }

    public function test_levels_get_the_period(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $report = $generator->create_report(['name' => 'Period']);
        $generator->create_level($report->id, 0,
            "SELECT 1 AS id, %%STARTTIME%% AS starts, %%ENDTIME%% AS ends, 1 AS n");
        $context = \context_system::instance();
        $all = level_service::get_level_payload($report, 0, [], $context, (int) get_admin()->id);
        $this->assertSame('0', (string) $all['rows'][0]['starts']);
        $this->assertSame((string) date_range::ALL_END, (string) $all['rows'][0]['ends']);
        $period = level_service::get_level_payload($report, 0, [], $context, (int) get_admin()->id, [1000, 2000]);
        $this->assertSame('1000', (string) $period['rows'][0]['starts']);
        $this->assertSame('2000', (string) $period['rows'][0]['ends']);

        // The web service resolves the preset, and gives the dates of the period.
        $data = \local_reportium\external\levels::get_level_data((int) $report->id, 0, [], 0, 'today');
        $row = json_decode($data['rows'], true)[0];
        $this->assertSame(date_range::resolve('today')[0], (int) $row['starts']);
        $this->assertNotSame('', $data['rangelabel']);
        $previous = \local_reportium\external\levels::get_level_data((int) $report->id, 0, [], 0, 'today', 0, 0, true);
        $this->assertSame(date_range::resolve('today')[0] - DAYSECS,
            (int) json_decode($previous['rows'], true)[0]['starts']);
    }

    public function test_log_templates_filter_by_period(): void {
        global $DB;
        $this->resetAfterTest();
        report_templates::install_missing();
        $logins = $DB->get_record('local_reportium', ['template' => 'logins']);
        $this->assertSame('last30', $logins->daterange);
        $sql = level_service::get_root_sql((int) $logins->id);
        $this->assertStringContainsString(report_templates::PERIOD, $sql);
        // An installed report with the SQL of before gets the condition; a level changed by a user does not.
        $DB->set_field('local_reportium_level', 'sqlquery', str_replace(report_templates::PERIOD, '', $sql),
            ['reportid' => $logins->id, 'drilllevel' => 0]);
        $DB->set_field('local_reportium_level', 'sqlquery', 'SELECT 1 AS id', ['reportid' => $logins->id, 'drilllevel' => 1]);
        $DB->set_field('local_reportium', 'daterange', null, ['id' => $logins->id]);
        $this->assertGreaterThanOrEqual(1, report_templates::add_date_ranges());
        $this->assertSame($sql, level_service::get_root_sql((int) $logins->id));
        $this->assertSame('SELECT 1 AS id', $DB->get_field('local_reportium_level', 'sqlquery',
            ['reportid' => $logins->id, 'drilllevel' => 1]));
        $this->assertSame('last30', $DB->get_field('local_reportium', 'daterange', ['id' => $logins->id]));
    }
}
