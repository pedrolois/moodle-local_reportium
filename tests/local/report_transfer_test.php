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
 * Tests of report files: export and import (JSON), XML files of SQL reports, bad files.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\report_transfer
 */
final class report_transfer_test extends \advanced_testcase {

    public function test_export_and_import(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_demo_report();
        $DB->set_field('local_reportium', 'views', 'bar,table', ['id' => $report->id]);
        $report = $DB->get_record('local_reportium', ['id' => $report->id]);
        level_service::save_level_config((int) $report->id, 1, ['defaultview' => 'table']);
        $teacher = (int) $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);
        report_roles::set((int) $report->id, [$teacher]);
        set_config('cachettlminutes_report_' . $report->id, 15, 'local_reportium');

        $file = report_transfer::export($report);
        $data = json_decode($file, true);
        $this->assertSame(report_transfer::FORMAT, $data['format']);
        $this->assertSame(['editingteacher'], $data['roles']);
        $this->assertStringEndsWith('.reportium.json', report_transfer::filename($report));

        $newid = report_transfer::import($file, SITEID, (int) get_admin()->id);
        $new = $DB->get_record('local_reportium', ['id' => $newid]);
        $this->assertStringStartsWith($report->name . ' (', $new->name);
        $this->assertSame('bar,table', $new->views);
        $this->assertNull($new->template);
        $this->assertSame([$teacher], report_roles::get($newid));
        $this->assertSame('15', get_config('local_reportium', 'cachettlminutes_report_' . $newid));
        $levels = level_service::get_levels($newid);
        $this->assertSame(array_column(level_service::get_levels((int) $report->id), 'sqlquery'),
            array_column($levels, 'sqlquery'));
        $this->assertSame('table', $levels[1]['config']['defaultview']);
    }

    public function test_import_xml_of_an_sql_report(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $components = ['customsql' => ['config' => (object) ['querysql' => urlencode('SELECT id, fullname FROM {course}')]]];
        $xml = '<?xml version="1.0" encoding="UTF-8" ?><report version="2019122000">'
            . '<name><![CDATA[Old courses]]></name><type><![CDATA[sql]]></type><export><![CDATA[csv,xls,slk,]]></export>'
            . '<components><![CDATA[' . base64_encode(serialize($components)) . ']]></components></report>';
        $id = report_transfer::import($xml, SITEID, (int) get_admin()->id);
        $levels = level_service::get_levels($id);
        $this->assertCount(1, $levels);
        $this->assertSame('SELECT id, fullname FROM {course}', $levels[0]['sqlquery']);
        // The xls format becomes Excel; formats Moodle has no data format for are dropped.
        $export = explode(',', trim(\core\di::get(\moodle_database::class)->get_field('local_reportium', 'export',
            ['id' => $id]), ','));
        $this->assertContains('csv', $export);
        $this->assertContains('excel', $export);
        $this->assertNotContains('slk', $export);
        // Default role: Manager.
        $this->assertSame(report_roles::default_roles(), report_roles::get($id));
    }

    public function test_bad_files_are_refused(): void {
        $this->resetAfterTest();
        foreach (['not a report', '{"format":"other"}', '<report><name>x</name></report>',
                '<report version="1"><name>x</name><type>sql</type></report>'] as $content) {
            try {
                report_transfer::import($content, SITEID, 2);
                $this->fail('Accepted: ' . $content);
            } catch (\moodle_exception $e) {
                $this->assertSame('errorimporting', $e->errorcode);
            }
        }
    }

    public function test_export_all_and_import_them_all(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $demo = $generator->create_demo_report();
        $other = $generator->create_report(['name' => 'Second report']);
        $file = report_transfer::export_all([$demo, $other]);
        $data = json_decode($file, true);
        $this->assertSame(report_transfer::FORMAT_ALL, $data['format']);
        $this->assertCount(2, $data['reports']);
        $this->assertStringEndsWith('.json', report_transfer::filename_all());

        $before = $DB->count_records('local_reportium');
        $ids = report_transfer::import_many($file, SITEID, (int) get_admin()->id);
        $this->assertCount(2, $ids);
        $this->assertSame($before + 2, $DB->count_records('local_reportium'));
        $this->assertSame(array_column(level_service::get_levels((int) $demo->id), 'sqlquery'),
            array_column(level_service::get_levels($ids[0]), 'sqlquery'));
        // A file of one report is imported too.
        $this->assertCount(1, report_transfer::import_many(report_transfer::export($demo), SITEID, 2));
    }

    /**
     * Rows of a reports table read with a database query: components as stored (serialized).
     *
     * @return array[]
     */
    private function table_rows(): array {
        $components = fn(string $sql) => serialize(['customsql' => ['config' => (object) ['querysql' => urlencode($sql)]]]);
        return [
            ['name' => 'Courses', 'summary' => 'All courses', 'type' => 'sql', 'global' => '1', 'export' => 'csv,ods,',
                'components' => $components('SELECT id, fullname FROM {course}')],
            ['name' => 'Users; "quoted"', 'summary' => '', 'type' => 'sql', 'global' => '0', 'export' => '',
                'components' => $components("SELECT id, username\nFROM {user}")],
            // Not an SQL report: skipped.
            ['name' => 'Course report', 'summary' => '', 'type' => 'courses', 'global' => '0', 'export' => '',
                'components' => serialize([])],
        ];
    }

    public function test_import_rows_of_a_database_query(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $rows = $this->table_rows();

        // JSON: a plain list of rows, and the export of database tools that wrap the rows.
        foreach ([json_encode($rows), json_encode([['type' => 'table', 'name' => 'reports', 'data' => $rows]])] as $json) {
            $ids = report_transfer::import_many($json, SITEID, 2);
            $this->assertCount(2, $ids);
            $this->assertSame('SELECT id, fullname FROM {course}', level_service::get_levels($ids[0])[0]['sqlquery']);
        }

        // CSV, comma or semicolon separated, with quoted values over several lines.
        foreach ([',', ';'] as $delimiter) {
            $stream = fopen('php://memory', 'r+');
            fputcsv($stream, array_keys($rows[0]), $delimiter);
            foreach ($rows as $row) {
                fputcsv($stream, $row, $delimiter);
            }
            rewind($stream);
            $ids = report_transfer::import_many(stream_get_contents($stream), SITEID, 2);
            fclose($stream);
            $this->assertCount(2, $ids);
            $this->assertSame("SELECT id, username\nFROM {user}", level_service::get_levels($ids[1])[0]['sqlquery']);
        }

        // XML with several reports, components base64 encoded.
        $xml = '<?xml version="1.0" encoding="UTF-8" ?><reports>';
        foreach ($rows as $row) {
            $xml .= '<report><name><![CDATA[' . $row['name'] . ']]></name><type>' . $row['type'] . '</type>'
                . '<components>' . base64_encode($row['components']) . '</components></report>';
        }
        $this->assertCount(2, report_transfer::import_many($xml . '</reports>', SITEID, 2));
    }

    public function test_import_many_refuses_files_without_reports(): void {
        $this->resetAfterTest();
        foreach (['not a report', '{"format":"other"}', '[]', "name,type\nx,courses", '<reports></reports>'] as $content) {
            try {
                report_transfer::import_many($content, SITEID, 2);
                $this->fail('Accepted: ' . $content);
            } catch (\moodle_exception $e) {
                $this->assertSame('errorimporting', $e->errorcode);
            }
        }
    }
}
