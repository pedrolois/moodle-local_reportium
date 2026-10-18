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

use local_reportium\local\date_range;

/**
 * Tests of the data the report page sends with itself (levels and first level): the same as the web services, and
 * only what the user may see.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\external\levels
 */
final class page_data_test extends \advanced_testcase {
    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $this->report = $generator->create_report(['name' => 'Page data']);
        $generator->create_level(
            $this->report->id,
            0,
            "SELECT 1 AS id, 'A' AS name, %%STARTTIME%% AS starts UNION ALL SELECT 2, 'B', %%ENDTIME%%"
        );
        $generator->create_level($this->report->id, 1, 'SELECT 1 AS id, %%PREVIOUSVALUE%% AS parent');
        \local_reportium\local\report_roles::set(
            (int) $this->report->id,
            \local_reportium\local\report_roles::default_roles()
        );
        \core\di::get(\moodle_database::class)->set_field('local_reportium', 'daterange', 'last7', ['id' => $this->report->id]);
        $this->report->daterange = 'last7';
    }

    public function test_levels_data_is_what_get_levels_returns(): void {
        $page = levels::levels_data($this->report, \context_system::instance());
        $service = levels::get_levels((int) $this->report->id);
        $this->assertSame($service, $page);
        $this->assertTrue($page['usesdates']);
        $this->assertSame('last7', $page['daterange']);
        $this->assertCount(2, $page['levels']);
        $this->assertNotSame('', $page['levels'][0]['sqlquery']);
    }

    public function test_level_data_is_what_get_level_data_returns(): void {
        $range = levels::range('last7');
        $page = levels::level_data($this->report, \context_system::instance(), 0, [], $range);
        $service = levels::get_level_data((int) $this->report->id, 0, [], 0, 'last7');
        foreach (['level', 'title', 'columns', 'rows', 'mapping', 'hasnext', 'rangelabel'] as $key) {
            $this->assertSame($service[$key], $page[$key], $key);
        }
        $rows = json_decode($page['rows'], true);
        $this->assertSame($range[0], (int) $rows[0]['starts']);
        $this->assertSame($range[1], (int) $rows[1]['starts']);
        $this->assertSame(date_range::label($range[0], $range[1]), $page['rangelabel']);
    }

    public function test_viewers_get_no_sql_and_no_slow_tips(): void {
        set_config('slowquerysecs', '0.001', 'local_reportium');
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);
        $page = levels::levels_data($this->report, \context_system::instance());
        $this->assertFalse($page['canmanagesql']);
        $this->assertSame(['', ''], array_column($page['levels'], 'sqlquery'));
        $data = levels::level_data($this->report, \context_system::instance(), 0, [], []);
        $this->assertSame([], $data['slowtips']);
    }

    public function test_range_of_the_web_service(): void {
        $this->assertSame([], levels::range(''));
        $this->assertSame([], levels::range('forever'));
        $this->assertSame(date_range::resolve('yesterday'), levels::range('yesterday'));
        [$start] = date_range::resolve('yesterday');
        $this->assertSame([$start - DAYSECS, $start - 1], levels::range('yesterday', 0, 0, true));
        // A custom period from the day of "from" to the end of the day of "to".
        $from = make_timestamp(2026, 2, 3, 12);
        $to = make_timestamp(2026, 2, 5, 12);
        $this->assertSame([make_timestamp(2026, 2, 3), make_timestamp(2026, 2, 6) - 1], levels::range('custom', $from, $to));
    }

    public function test_report_files_keep_the_period(): void {
        global $DB;
        $file = json_decode(\local_reportium\local\report_transfer::export($this->report), true);
        $this->assertSame('last7', $file['report']['daterange']);
        $id = \local_reportium\local\report_transfer::import(json_encode($file), SITEID, (int) get_admin()->id);
        $this->assertSame('last7', $DB->get_field('local_reportium', 'daterange', ['id' => $id]));
        // A period that is not a preset (or fixed custom dates) is not imported.
        foreach (['custom', 'forever'] as $bad) {
            $file['report']['daterange'] = $bad;
            $id = \local_reportium\local\report_transfer::import(json_encode($file), SITEID, (int) get_admin()->id);
            $this->assertNull($DB->get_field('local_reportium', 'daterange', ['id' => $id]));
        }
    }
    public function test_report_files_keep_the_kpi_defaults(): void {
        global $DB;
        $DB->update_record(
            'local_reportium',
            (object) ['id' => $this->report->id, 'kpicards' => 1, 'kpirows' => 0, 'totalsrow' => 1]
        );
        $report = $DB->get_record('local_reportium', ['id' => $this->report->id]);
        $file = json_decode(\local_reportium\local\report_transfer::export($report), true);
        $this->assertSame(
            [1, 0, 1],
            [(int) $file['report']['kpicards'], (int) $file['report']['kpirows'], (int) $file['report']['totalsrow']]
        );
        $id = \local_reportium\local\report_transfer::import(json_encode($file), SITEID, (int) get_admin()->id);
        $copy = $DB->get_record('local_reportium', ['id' => $id]);
        $this->assertSame([1, 0, 1], [(int) $copy->kpicards, (int) $copy->kpirows, (int) $copy->totalsrow]);
        // Files of before: the defaults of new reports (off).
        unset($file['report']['kpicards'], $file['report']['kpirows'], $file['report']['totalsrow']);
        $oldid = \local_reportium\local\report_transfer::import(json_encode($file), SITEID, 2);
        $old = $DB->get_record('local_reportium', ['id' => $oldid]);
        $this->assertSame([0, 0, 0], [(int) $old->kpicards, (int) $old->kpirows, (int) $old->totalsrow]);
    }
}
