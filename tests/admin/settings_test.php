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

namespace local_reportium\admin;

/**
 * Tests of the plugin's own admin settings: the states table and the compact colour input.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\admin\setting_states
 * @covers     \local_reportium\admin\setting_colour
 */
final class settings_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    public function test_states_are_saved_clean(): void {
        $setting = new setting_states();
        $error = $setting->write_setting([
            'present' => 1,
            'code' => ['In Review', '', 'late', 'LATE', 'Sí'],
            'color' => ['#20C997', '#000000', 'red', '#111111', '#abc'],
            'aliases' => ['revision, En revisión, in review', 'x', '', 'dup', ''],
            'label' => ['  In review ', '', '<b>Late</b>', '', ''],
        ]);
        $this->assertSame('', $error);
        $states = json_decode(get_config('local_reportium', 'states'), true);
        // Codes are normalised; empty and repeated codes are dropped.
        $this->assertSame(['inreview', 'late', 'si'], array_column($states, 'code'));
        // Colours: lower case, short form expanded, invalid ones grey.
        $this->assertSame(['#20c997', '#6c757d', '#aabbcc'], array_column($states, 'color'));
        // The code itself is not repeated among its values.
        $this->assertSame(['revision', 'enrevision'], $states[0]['aliases']);
        $this->assertSame('In review', $states[0]['label']);
        $this->assertSame('Late', $states[1]['label']);
    }

    public function test_removing_every_state_leaves_none(): void {
        $setting = new setting_states();
        $this->assertSame('', $setting->write_setting(['present' => 1]));
        $this->assertSame('[]', get_config('local_reportium', 'states'));
        $this->assertSame([], \local_reportium\local\instructions::states());
        // Not an array: back to the defaults of instructions.json.
        $this->assertSame('', $setting->write_setting(''));
        $this->assertNotEmpty(\local_reportium\local\instructions::states());
    }

    public function test_states_table_html(): void {
        $setting = new setting_states();
        $html = $setting->output_html(json_encode([['code' => 'review', 'color' => '#20c997', 'aliases' => ['revision'],
            'label' => 'In review']]));
        $this->assertStringContainsString('data-region="admin-states"', $html);
        $this->assertSame(1, substr_count($html, 'data-region="state-row"') - 1);
        $this->assertStringContainsString('value="review"', $html);
        $this->assertStringContainsString('value="#20c997"', $html);
        $this->assertStringContainsString('value="revision"', $html);
        $this->assertStringContainsString('In review', $html);
        $this->assertStringContainsString('name="s_local_reportium_states[code][]"', $html);
        // Nothing saved: the default states are shown.
        $defaults = $setting->output_html(null);
        $this->assertGreaterThan(10, substr_count($defaults, 'data-region="state-row"'));
        $this->assertStringContainsString('value="completed"', $defaults);
    }

    public function test_colour_setting(): void {
        $setting = new setting_colour('local_reportium/progresscolorlow', 'Low', '', '#c71c22');
        $this->assertSame('', $setting->write_setting('#ff0000'));
        $this->assertSame('#ff0000', get_config('local_reportium', 'progresscolorlow'));
        $this->assertNotSame('', $setting->write_setting('not a colour'));
        $this->assertSame('#ff0000', get_config('local_reportium', 'progresscolorlow'));
        $html = $setting->output_html('#ff0000');
        $this->assertStringContainsString('type="color"', $html);
        $this->assertStringContainsString('value="#ff0000"', $html);
        $this->assertStringContainsString('<code', $html);
        // A stored value that is not a colour shows the default.
        $this->assertStringContainsString('value="#c71c22"', $setting->output_html('broken'));
    }

    public function test_settings_page_lists_the_column_formats(): void {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $page = admin_get_root(true)->locate('local_reportium_settings');
        $this->assertNotNull($page);
        // One tab per group of settings.
        $this->assertTrue(method_exists($page, 'get_tabs'));
        $tabs = $page->get_tabs();
        $this->assertSame(
            ['local_reportium_general', 'local_reportium_formats', 'local_reportium_ai'],
            array_values(array_map(fn($tab) => $tab->name, $tabs))
        );
        $names = [];
        foreach ($tabs as $tab) {
            foreach ((array) $tab->settings as $setting) {
                $names[] = $setting->name;
            }
        }
        foreach (
            ['progresskeywords', 'progresscolorlow', 'progresscolormid', 'progresscolorhigh', 'statekeywords', 'states',
                'reportlimit'] as $name
        ) {
            $this->assertContains($name, $names);
        }
        foreach (['openai_api_key', 'anthropic_api_key', 'dbhost', 'sqlsecurity'] as $gone) {
            $this->assertNotContains($gone, $names);
        }
    }
}
