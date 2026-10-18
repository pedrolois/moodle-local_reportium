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
 * Tests of the downloads: formats offered, buttons under the report, and the files.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\exporter
 */
final class exporter_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * A report record with the given export field.
     *
     * @param string $export
     * @return \stdClass
     */
    private function report(string $export): \stdClass {
        return (object) ['id' => 7, 'name' => 'Demo', 'export' => $export];
    }

    public function test_available_formats_are_the_enabled_data_formats(): void {
        $available = exporter::available();
        foreach (['csv', 'excel', 'ods', 'json'] as $format) {
            $this->assertArrayHasKey($format, $available);
            $this->assertSame(get_string('dataformat', 'dataformat_' . $format), $available[$format]);
        }
        // A disabled data format is not offered.
        \core\plugininfo\dataformat::enable_plugin('ods', 0);
        \core_plugin_manager::reset_caches();
        $this->assertArrayNotHasKey('ods', exporter::available());
        $this->assertArrayNotHasKey('ods', exporter::offered($this->report('csv,ods,')));
    }

    public function test_offered_formats_use_aliases_and_drop_unknown_ones(): void {
        $offered = exporter::offered($this->report('csv,xls,slk,nonsense,json,'));
        $this->assertSame(['csv', 'excel', 'json'], array_keys($offered));
        $this->assertSame([], exporter::offered($this->report('')));
        // Default formats of new reports are all real data formats, and the image of the chart.
        foreach (array_diff(exporter::DEFAULTS, [exporter::PNG]) as $format) {
            $this->assertArrayHasKey($format, exporter::available());
        }
        $this->assertContains(exporter::PNG, exporter::DEFAULTS);
        // The image is not a data format: it is offered on its own.
        $this->assertArrayNotHasKey('png', exporter::offered($this->report('csv,png,')));
        $this->assertTrue(exporter::offers_png($this->report('csv,png,')));
        $this->assertFalse(exporter::offers_png($this->report('csv,')));
    }

    public function test_links_under_the_report(): void {
        $html = exporter::links($this->report('csv,excel,'), 1);
        $this->assertStringContainsString('reportium-downloads', $html);
        $this->assertStringContainsString(get_string('downloadreport', 'local_reportium'), $html);
        $this->assertSame(2, substr_count($html, 'download=1'));
        $this->assertStringContainsString('format=csv', $html);
        $this->assertStringContainsString('format=excel', $html);
        $this->assertStringContainsString('fa-file-csv', $html);
        $this->assertStringContainsString('fa-file-excel', $html);
        $this->assertStringNotContainsString('print-report', $html);

        $withprint = exporter::links($this->report('csv,'), 1, true);
        $this->assertStringContainsString('data-action="print-report"', $withprint);
        $this->assertStringContainsString('fa-print', $withprint);

        // Print only: no "Download report" label; nothing at all: empty.
        $printonly = exporter::links($this->report(''), 1, true);
        $this->assertStringContainsString('print-report', $printonly);
        $this->assertStringNotContainsString(get_string('downloadreport', 'local_reportium'), $printonly);
        $this->assertSame('', exporter::links($this->report(''), 1));
    }

    public function test_button(): void {
        $url = new \moodle_url('/local/reportium/viewreport.php', ['id' => 7, 'download' => 1, 'format' => 'json']);
        $labelled = exporter::button($url, 'json', 'Javascript Object Notation (.json)');
        $this->assertStringContainsString('reportium-download-json', $labelled);
        $this->assertStringContainsString('fa-file-code', $labelled);
        $this->assertStringContainsString('>JSON<', $labelled);
        $this->assertStringContainsString('title="Javascript Object Notation (.json)"', $labelled);
        $iconly = exporter::button($url, 'json', 'JSON file', false);
        $this->assertStringNotContainsString('>JSON<', $iconly);
        // A format without its own icon or short name still gets a button.
        $other = exporter::button($url, 'xml', 'XML');
        $this->assertStringContainsString('fa-file ', $other);
        $this->assertStringContainsString('>XML<', $other);
    }
}
