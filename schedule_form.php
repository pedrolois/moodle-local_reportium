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
 * Form of a scheduled email delivery of a report.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

use local_reportium\local\date_range;
use local_reportium\local\exporter;
use local_reportium\local\report_roles;

/**
 * Schedule: what is sent (level, period, format), when, and to whom. Custom data: report, levels (level => name).
 *
 * @package    local_reportium
 */
class schedule_form extends moodleform {

    /**
     * Form definition.
     */
    public function definition(): void {
        $mform = $this->_form;
        $report = $this->_customdata['report'];
        $context = report_roles::report_context($report);

        // What is sent.
        $mform->addElement('header', 'whatheader', get_string('schedule_what', 'local_reportium'));
        $mform->setExpanded('whatheader');
        $mform->addElement('select', 'level', get_string('schedule_level', 'local_reportium'), $this->_customdata['levels']);
        $mform->addHelpButton('level', 'schedule_level', 'local_reportium');
        // The row of each level before the chosen one (e.g. which category, then which course): schedule_form.js.
        $mform->addElement('static', 'schedulepath', '', html_writer::div('', 'reportium-schedule-path',
            ['data-region' => 'schedule-path']));
        $mform->addElement('select', 'daterange', get_string('daterange', 'local_reportium'), date_range::options(false));
        $mform->addHelpButton('daterange', 'schedule_daterange', 'local_reportium');
        $mform->setDefault('daterange', (string) ($report->daterange ?? ''));
        $mform->addElement('select', 'format', get_string('schedule_format', 'local_reportium'), exporter::available());
        $mform->setDefault('format', isset(exporter::available()['excel']) ? 'excel' : 'csv');
        // The chart of a PDF (drawn like the print).
        $charts = [];
        foreach (\local_reportium\local\scheduler::PDF_CHARTS as $chart) {
            $charts[$chart] = get_string('schedule_chart_' . ($chart === '' ? 'report' : $chart), 'local_reportium');
        }
        $mform->addElement('select', 'chart', get_string('schedule_chart', 'local_reportium'), $charts);
        $mform->addHelpButton('chart', 'schedule_chart', 'local_reportium');
        $mform->hideIf('chart', 'format', 'neq', 'pdf');

        // Alert: send only if.
        $mform->addElement('header', 'condheader', get_string('schedule_cond', 'local_reportium'));
        $mform->addElement('select', 'condtype', get_string('schedule_cond_type', 'local_reportium'), [
            '' => get_string('schedule_cond_always', 'local_reportium'),
            'rows' => get_string('schedule_cond_rows', 'local_reportium'),
            'value' => get_string('schedule_cond_value', 'local_reportium'),
        ]);
        $mform->addHelpButton('condtype', 'schedule_cond', 'local_reportium');
        // The columns of the chosen level: schedule_form.js (kept in the hidden condcolumn).
        $mform->addElement('static', 'condcolumnregion', get_string('schedule_cond_column', 'local_reportium'),
            html_writer::div('', 'reportium-schedule-condcolumn', ['data-region' => 'cond-column']));
        $mform->addElement('hidden', 'condcolumn', '');
        $mform->setType('condcolumn', PARAM_RAW_TRIMMED);
        $mform->addElement('hidden', 'condcolumnlabel', '');
        $mform->setType('condcolumnlabel', PARAM_TEXT);
        $aggs = [];
        foreach (\local_reportium\local\column_inspector::AGGREGATES as $agg) {
            $aggs[$agg] = get_string('ui_kpi_' . $agg, 'local_reportium');
        }
        $mform->addElement('select', 'condagg', get_string('schedule_cond_agg', 'local_reportium'), $aggs);
        $mform->setDefault('condagg', 'avg');
        $mform->hideIf('condagg', 'condtype', 'neq', 'value');
        $mform->addElement('select', 'condop', get_string('schedule_cond_op', 'local_reportium'),
            \local_reportium\local\scheduler::OPERATORS);
        $mform->setDefault('condop', 'lt');
        $mform->hideIf('condop', 'condtype', 'neq', 'value');
        $mform->addElement('text', 'condvalue', get_string('schedule_cond_number', 'local_reportium'), ['size' => 10]);
        $mform->setType('condvalue', PARAM_RAW_TRIMMED);
        $mform->hideIf('condvalue', 'condtype', 'neq', 'value');

        // When.
        $mform->addElement('header', 'whenheader', get_string('schedule_when', 'local_reportium'));
        $mform->setExpanded('whenheader');
        $frequencies = [];
        foreach (\local_reportium\local\scheduler::FREQUENCIES as $frequency) {
            $frequencies[$frequency] = get_string('schedule_' . $frequency, 'local_reportium');
        }
        $mform->addElement('select', 'frequency', get_string('schedule_frequency', 'local_reportium'), $frequencies);
        $mform->setDefault('frequency', 'weekly');
        $weekdays = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $day) {
            $weekdays[$day] = get_string(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'][$day],
                'calendar');
        }
        $mform->addElement('select', 'weekday', get_string('schedule_weekday', 'local_reportium'), $weekdays);
        $mform->setDefault('weekday', 1);
        $mform->hideIf('weekday', 'frequency', 'neq', 'weekly');
        $mform->addElement('select', 'monthday', get_string('schedule_monthday', 'local_reportium'),
            array_combine(range(1, 28), range(1, 28)));
        $mform->hideIf('monthday', 'frequency', 'in', ['daily', 'weekly']);
        $mform->addHelpButton('monthday', 'schedule_monthday', 'local_reportium');
        $hours = [];
        foreach (range(0, 23) as $hour) {
            $hours[$hour] = sprintf('%02d:00', $hour);
        }
        $mform->addElement('select', 'hour', get_string('schedule_hour', 'local_reportium'), $hours);
        $mform->setDefault('hour', 8);
        $mform->addElement('advcheckbox', 'enabled', get_string('schedule_enabled', 'local_reportium'),
            get_string('schedule_enabled_desc', 'local_reportium'));
        $mform->setDefault('enabled', 1);

        // To whom.
        $mform->addElement('header', 'whoheader', get_string('schedule_who', 'local_reportium'));
        $mform->setExpanded('whoheader');
        $mform->addElement('autocomplete', 'users', get_string('schedule_users', 'local_reportium'), [], [
            'multiple' => true,
            'ajax' => 'core_user/form_user_selector',
            'valuehtmlcallback' => static function($userid) {
                global $DB;
                $user = $DB->get_record('user', ['id' => (int) $userid, 'deleted' => 0]);
                return $user ? fullname($user) . ' (' . s($user->email) . ')' : false;
            },
        ]);
        $cohorts = [];
        foreach (cohort_get_available_cohorts($context, COHORT_ALL, 0, 0) as $cohort) {
            $cohorts[$cohort->id] = format_string($cohort->name, true, ['context' => $context]);
        }
        $mform->addElement('autocomplete', 'cohorts', get_string('schedule_cohorts', 'local_reportium'), $cohorts,
            ['multiple' => true]);
        $mform->addElement('autocomplete', 'roles', get_string('schedule_roles', 'local_reportium'),
            report_roles::options($context), ['multiple' => true]);
        $mform->addHelpButton('roles', 'schedule_roles', 'local_reportium');
        $mform->addElement('textarea', 'emails', get_string('schedule_emails', 'local_reportium'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('emails', PARAM_RAW_TRIMMED);
        $mform->addHelpButton('emails', 'schedule_emails', 'local_reportium');

        // Message.
        $mform->addElement('header', 'messageheader', get_string('schedule_messageheader', 'local_reportium'));
        $mform->addElement('text', 'subject', get_string('schedule_subjectfield', 'local_reportium'), ['size' => 60]);
        $mform->setType('subject', PARAM_TEXT);
        $mform->addHelpButton('subject', 'schedule_subjectfield', 'local_reportium');
        $mform->addElement('textarea', 'message', get_string('schedule_messagefield', 'local_reportium'),
            ['rows' => 4, 'cols' => 60]);
        $mform->setType('message', PARAM_TEXT);
        $mform->setDefault('message', get_string('schedule_message_example', 'local_reportium'));
        // The placeholders, each with what it becomes.
        $items = '';
        foreach (\local_reportium\local\scheduler::PLACEHOLDERS as $name) {
            $items .= html_writer::tag('li', html_writer::tag('code', '%' . $name . '%') . ' ' .
                get_string('schedule_ph_' . $name, 'local_reportium'));
        }
        $mform->addElement('static', 'placeholders', get_string('schedule_placeholders', 'local_reportium'),
            html_writer::div(get_string('schedule_placeholders_desc', 'local_reportium'), 'small text-muted') .
            html_writer::tag('ul', $items, ['class' => 'reportium-placeholders small']));

        $mform->addElement('hidden', 'id', $report->id);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'sid', 0);
        $mform->setType('sid', PARAM_INT);
        $mform->addElement('hidden', 'viewstate', '');
        $mform->setType('viewstate', PARAM_RAW);
        $mform->addElement('hidden', 'action', 'edit');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('schedule_save', 'local_reportium'));
    }

    /**
     * At least one recipient; external addresses must be valid.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $emails = self::split_emails((string) ($data['emails'] ?? ''));
        $invalid = array_filter($emails, static fn(string $email): bool => !validate_email($email));
        if ($invalid) {
            $errors['emails'] = get_string('schedule_invalidemails', 'local_reportium', s(implode(', ', $invalid)));
        }
        // Every level before the chosen one needs its row.
        $view = json_decode((string) ($data['viewstate'] ?? ''), true);
        $steps = array_filter((array) ($view['steps'] ?? []),
            static fn($step) => is_array($step) && (string) ($step['v'] ?? '') !== '');
        if ((int) ($data['level'] ?? 0) > count($steps)) {
            $errors['level'] = get_string('schedule_pathrequired', 'local_reportium');
        }
        if (($data['condtype'] ?? '') === 'value') {
            if (trim((string) ($data['condcolumn'] ?? '')) === '') {
                $errors['condtype'] = get_string('schedule_cond_nocolumn', 'local_reportium');
            }
            if (!is_numeric(str_replace(',', '.', (string) ($data['condvalue'] ?? '')))) {
                $errors['condvalue'] = get_string('schedule_cond_nonumber', 'local_reportium');
            }
        }
        if (empty($data['users']) && empty($data['cohorts']) && empty($data['roles']) && !$emails) {
            $errors['users'] = get_string('schedulenorecipients', 'local_reportium');
        }
        return $errors;
    }

    /**
     * Addresses of the external emails field (one per line, or separated by commas or semicolons).
     *
     * @param string $text
     * @return string[]
     */
    public static function split_emails(string $text): array {
        return array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', $text)))));
    }
}
