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
 * Tests of the column format rules: words and states from the plugin settings, defaults from instructions.json.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\instructions
 */
final class instructions_test extends \advanced_testcase {

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    public function test_detect_format_with_default_words(): void {
        unset_config('progresskeywords', 'local_reportium');
        unset_config('statekeywords', 'local_reportium');
        $this->assertSame('progress', instructions::detect_format('completion_pct', 'number'));
        $this->assertSame('progress', instructions::detect_format('porcentaje_total', 'number'));
        $this->assertSame('state', instructions::detect_format('course_status', 'text'));
        $this->assertSame('state', instructions::detect_format('estado', 'text'));
        // Types and positions: a text "progress" column and a state word that does not end the name stay text.
        $this->assertSame('text', instructions::detect_format('progress', 'text'));
        $this->assertSame('text', instructions::detect_format('status_date', 'text'));
    }

    public function test_detect_format_with_words_from_settings(): void {
        set_config('progresskeywords', 'avance, Ratio', 'local_reportium');
        set_config('statekeywords', 'situacion', 'local_reportium');
        $this->assertSame('progress', instructions::detect_format('avance', 'number'));
        $this->assertSame('progress', instructions::detect_format('ratio_done', 'number'));
        // The default words no longer apply.
        $this->assertSame('text', instructions::detect_format('completion_pct', 'number'));
        $this->assertSame('state', instructions::detect_format('user_situacion', 'text'));
        $this->assertSame('text', instructions::detect_format('status', 'text'));

        // Empty: no automatic format.
        set_config('progresskeywords', '', 'local_reportium');
        $this->assertSame('text', instructions::detect_format('avance', 'number'));
    }

    public function test_states_from_settings(): void {
        set_config('states', '', 'local_reportium');
        $defaults = instructions::states();
        $this->assertSame('completed', $defaults[0]['code']);
        $this->assertContains('completado', $defaults[0]['keys']);

        set_config('states', json_encode([
            ['code' => 'review', 'color' => '#20c997', 'aliases' => ['revision', 'enrevision'], 'label' => 'In review'],
            ['code' => 'late', 'color' => 'not a colour', 'aliases' => []],
        ]), 'local_reportium');
        $states = instructions::states();
        $this->assertCount(2, $states);
        $this->assertSame(['review', 'revision', 'enrevision'], $states[0]['keys']);
        $this->assertSame('In review', $states[0]['label']);
        $this->assertSame('#1d2125', $states[0]['textcolor']);
        // Invalid colours fall back to grey; no label and no translation: the code.
        $this->assertSame('#6c757d', $states[1]['color']);
        $this->assertSame('Late', $states[1]['label']);
        $this->assertStringContainsString('review/revision/enrevision', instructions::for_prompt());
    }

    public function test_normalise_and_colours(): void {
        $this->assertSame('inprogress', instructions::normalise(' In-Progress '));
        $this->assertSame('si', instructions::normalise('Sí'));
        $this->assertSame('enrevision', instructions::normalise('en revisión'));
        $this->assertSame(['avance', 'ratio'], instructions::word_list('Avance, ratio,, avance'));
        $this->assertSame('#aabbcc', instructions::color('#ABC', '#000000'));
        $this->assertSame('#000000', instructions::color('red', '#000000'));
        $this->assertSame('#ffffff', instructions::text_color('#c71c22'));
        $this->assertSame('#1d2125', instructions::text_color('#f0ad4e'));

        set_config('progresscolorlow', '#112233', 'local_reportium');
        set_config('progresscolormid', 'bad', 'local_reportium');
        $colors = instructions::progress_colors();
        $this->assertSame('#112233', $colors['low']);
        $this->assertSame('#f0ad4e', $colors['mid']);
    }
}
