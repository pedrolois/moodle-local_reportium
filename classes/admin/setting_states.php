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

use local_reportium\local\instructions;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting: the state codes of "state" columns, edited as a table (code, colour, the values that match it,
 * optional label). Stored as JSON; empty = the states of instructions.json.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_states extends \admin_setting {
    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct(
            'local_reportium/states',
            get_string('settings_states', 'local_reportium'),
            get_string('settings_states_desc', 'local_reportium'),
            ''
        );
    }

    /**
     * Stored value (JSON), or null before the setting is saved for the first time.
     *
     * @return string|null
     */
    public function get_setting() {
        return $this->config_read($this->name);
    }

    /**
     * Save the rows of the table.
     *
     * @param mixed $data ['code' => [], 'color' => [], 'aliases' => [], 'label' => []], or '' for the defaults
     * @return string '' or an error message
     */
    public function write_setting($data) {
        if (!is_array($data)) {
            return $this->config_write($this->name, '') ? '' : get_string('errorsetting', 'admin');
        }
        $states = [];
        $seen = [];
        foreach ((array) ($data['code'] ?? []) as $i => $code) {
            $code = instructions::normalise((string) $code);
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $states[] = [
                'code' => $code,
                'color' => instructions::color((string) ($data['color'][$i] ?? ''), '#6c757d'),
                'aliases' => array_values(array_diff(instructions::word_list((string) ($data['aliases'][$i] ?? '')), [$code])),
                'label' => clean_param(trim((string) ($data['label'][$i] ?? '')), PARAM_TEXT),
            ];
        }
        return $this->config_write($this->name, json_encode($states, JSON_UNESCAPED_UNICODE)) ? ''
            : get_string('errorsetting', 'admin');
    }

    /**
     * The table of states, with Add and Remove buttons.
     *
     * @param mixed $data stored value
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        global $OUTPUT, $PAGE;
        $decoded = is_string($data) && $data !== '' ? json_decode($data, true) : null;
        $states = is_array($decoded) ? $decoded : (instructions::get()['states'] ?? []);
        $rows = [];
        foreach ($states as $state) {
            $color = instructions::color((string) ($state['color'] ?? ''), '#6c757d');
            $code = (string) ($state['code'] ?? '');
            $rows[] = [
                'code' => $code,
                'color' => $color,
                'aliases' => implode(', ', (array) ($state['aliases'] ?? [])),
                'label' => (string) ($state['label'] ?? ''),
                'preview' => instructions::state_label($code, (string) ($state['label'] ?? '')),
                'textcolor' => instructions::text_color($color),
            ];
        }
        $html = $OUTPUT->render_from_template('local_reportium/admin_states', [
            'name' => $this->get_full_name(),
            'id' => $this->get_id(),
            'rows' => $rows,
            'empty' => ['code' => '', 'color' => '#6c757d', 'aliases' => '', 'label' => '', 'preview' => '',
                'textcolor' => '#ffffff'],
        ]);
        $PAGE->requires->js_call_amd('local_reportium/admin_states', 'init', [$this->get_id()]);
        return format_admin_setting(
            $this,
            $this->visiblename,
            $html,
            $this->description,
            false,
            '',
            get_string('settings_states_default', 'local_reportium'),
            $query
        );
    }
}
