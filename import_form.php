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

/**
 * Form of the Reports manager to import a report file.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

/**
 * Import report files: a Reportium file (one report or all), .xml of SQL reports, or the rows of a reports table
 * exported as .csv or .json from a database query. Custom data: the course id.
 *
 * @package    local_reportium
 */
class import_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition(): void {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'userfile', get_string('file'), null,
            ['accepted_types' => ['.json', '.xml', '.csv']]);
        $mform->addHelpButton('userfile', 'importfile', 'local_reportium');
        $mform->addRule('userfile', null, 'required');

        $mform->addElement('hidden', 'courseid', $this->_customdata);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(false, get_string('importreports', 'local_reportium'));
    }
}
