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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/adminlib.php');

/**
 * Admin setting: a colour, edited with the browser's compact colour input (same validation and storage as the core
 * colour picker setting).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_colour extends \admin_setting_configcolourpicker {
    /**
     * Colour swatch input with the hexadecimal value next to it.
     *
     * @param mixed $data stored value
     * @param string $query
     * @return string
     */
    public function output_html($data, $query = '') {
        $value = \local_reportium\local\instructions::color((string) $data, (string) $this->get_defaultsetting());
        $html = \html_writer::div(
            \html_writer::empty_tag('input', ['type' => 'color', 'id' => $this->get_id(), 'name' => $this->get_full_name(),
                'value' => $value, 'class' => 'form-control reportium-admin-color',
                'oninput' => 'this.nextElementSibling.textContent = this.value']) .
            \html_writer::tag('code', s($value), ['class' => 'ml-2 ms-2']),
            'd-flex align-items-center'
        );
        return format_admin_setting(
            $this,
            $this->visiblename,
            $html,
            $this->description,
            true,
            '',
            $this->get_defaultsetting(),
            $query
        );
    }
}
