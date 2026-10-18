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
 * Report settings form (new report and the "Settings" page of a report).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;
require_once($CFG->libdir . '/formslib.php');

/**
 * Report settings form. Custom data: courseid, and report when editing one.
 *
 * @package    local_reportium
 */
class report_edit_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition(): void {
        global $CFG;

        $mform = $this->_form;
        $report = $this->_customdata['report'] ?? null;

        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['maxlength' => 128, 'size' => 58]);
        $mform->setType('name', !empty($CFG->formatstringstriptags) ? PARAM_TEXT : PARAM_NOTAGS);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement('editor', 'summary_editor', get_string('summary'), null, $this->get_editor_options());
        $mform->setType('summary_editor', PARAM_RAW);

        // Reports are SQL reports: their data comes from the SQL of their levels.
        $mform->setType('type', PARAM_ALPHA);

        $mform->addElement('advcheckbox', 'global', get_string('global', 'local_reportium'),
            get_string('enableglobal', 'local_reportium'));
        $mform->addHelpButton('global', 'global', 'local_reportium');

        $mform->addElement('advcheckbox', 'displaytotalrecords', get_string('displaytotalrecords', 'local_reportium'),
            get_string('displaytotalrecordsdescription', 'local_reportium'));
        $mform->setDefault('displaytotalrecords', 1);

        $mform->addElement('text', 'cachettlminutes', get_string('cachettlminutes', 'local_reportium'),
            ['maxlength' => 4, 'size' => 8]);
        $mform->setType('cachettlminutes', PARAM_INT);
        $mform->setDefault('cachettlminutes', max(0, (int) get_config('local_reportium', 'defaultcachettl')));
        $mform->addHelpButton('cachettlminutes', 'cachettlminutes', 'local_reportium');

        // Period shown first, for reports whose SQL uses %%STARTTIME%% / %%ENDTIME%%.
        $mform->addElement('select', 'daterange', get_string('daterange', 'local_reportium'),
            \local_reportium\local\date_range::options(false));
        $mform->addHelpButton('daterange', 'daterange', 'local_reportium');

        // Chart types offered by the report (chips, like the roles of the Reports manager); all by default.
        $viewoptions = [];
        foreach (['bar', 'pie', 'line', 'table'] as $view) {
            $viewoptions[$view] = get_string('ui_' . $view, 'local_reportium');
        }
        $mform->addElement('autocomplete', 'views', get_string('reportviews', 'local_reportium'), $viewoptions,
            ['multiple' => true, 'noselectionstring' => get_string('reportviews_all', 'local_reportium')]);
        $mform->setDefault('views', array_keys($viewoptions));
        $mform->addHelpButton('views', 'reportviews', 'local_reportium');

        // Link to embed the saved report in other pages.
        if (!empty($report->id)) {
            $url = new moodle_url('/local/reportium/viewreport.php',
                ['id' => $report->id, 'courseid' => $this->_customdata['courseid'], 'embed' => true]);
            $mform->addElement('static', 'embedlink', get_string('embedlink', 'local_reportium'),
                html_writer::tag('pre', $url, ['class' => 'mb-0']) . get_string('embedlinkdescription', 'local_reportium'));
        }

        // KPI cards and totals row: defaults of every level (each level can change them in its Chart settings).
        $mform->addElement('header', 'kpiheader', get_string('kpisettings', 'local_reportium'));
        $mform->addElement('static', 'kpiinfo', '', get_string('kpisettings_desc', 'local_reportium'));
        $mform->addElement('advcheckbox', 'kpicards', get_string('ui_kpis', 'local_reportium'),
            get_string('kpicards_desc', 'local_reportium'));
        $mform->setDefault('kpicards', 0);
        $mform->addElement('advcheckbox', 'kpirows', get_string('ui_kpi_showrows', 'local_reportium'),
            get_string('kpirows_desc', 'local_reportium'));
        $mform->setDefault('kpirows', 0);
        $mform->hideIf('kpirows', 'kpicards', 'notchecked');
        $mform->addElement('advcheckbox', 'totalsrow', get_string('ui_totalsrow', 'local_reportium'),
            get_string('totalsrow_desc', 'local_reportium'));
        $mform->setDefault('totalsrow', 0);

        // Under the report: the print button and the download formats (Moodle data formats).
        $mform->addElement('header', 'exportoptions', get_string('exportoptions', 'local_reportium'));
        $mform->addElement('advcheckbox', 'displayprintbutton', get_string('displayprintbutton', 'local_reportium'),
            get_string('displayprintbuttondescription', 'local_reportium'));
        $mform->setDefault('displayprintbutton', 1);
        foreach (\local_reportium\local\exporter::available() as $format => $name) {
            $mform->addElement('advcheckbox', 'export_' . $format, null, $name);
            $mform->setDefault('export_' . $format, in_array($format, \local_reportium\local\exporter::DEFAULTS) ? 1 : 0);
        }
        // The chart as an image (drawn by the page).
        $mform->addElement('advcheckbox', 'export_png', null, get_string('exportpng', 'local_reportium'));
        $mform->setDefault('export_png', 1);

        if (!empty($report->id)) {
            $mform->addElement('hidden', 'id', $report->id);
            $mform->setType('id', PARAM_INT);
        }
        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(true, get_string(!empty($report->id) ? 'update' : 'add'));
    }

    /**
     * Form data, with the summary editor split into summary and summaryformat.
     *
     * @return stdClass|null
     */
    public function get_data() {
        $data = parent::get_data();
        if ($data !== null && isset($data->summary_editor)) {
            $data->summaryformat = $data->summary_editor['format'];
            $data->summary = $data->summary_editor['text'];
        }
        return $data;
    }

    /**
     * Load a report into the form.
     *
     * @param stdClass|array $defaultvalues
     */
    public function set_data($defaultvalues) {
        $defaultvalues = file_prepare_standard_editor((object) $defaultvalues, 'summary', $this->get_editor_options());
        parent::set_data($defaultvalues);
    }

    /**
     * Options of the summary editor.
     *
     * @return array
     */
    public function get_editor_options(): array {
        return ['subdirs' => 0, 'maxbytes' => 0, 'maxfiles' => 0, 'noclean' => false, 'trusttext' => false];
    }
}
