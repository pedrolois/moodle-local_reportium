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

namespace local_reportium\output;

use core_external\external_api;
use local_reportium\local\report_roles;
use stdClass;

/**
 * In-place editable "Chart types" of a report in the Reports manager (Bar, Pie, Line, Table): the same chips as the
 * Roles column, saved through core_update_inplace_editable. Same data as the "Chart types" field of the report form.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_views_editable extends \core\output\inplace_editable {

    /** Every chart type, in toolbar order. */
    const VIEWS = ['bar', 'pie', 'line', 'table'];

    /** @var string[] view => localised name */
    protected $viewnames = [];

    /**
     * Constructor.
     *
     * @param stdClass $report
     */
    public function __construct(stdClass $report) {
        foreach (self::VIEWS as $view) {
            $this->viewnames[$view] = get_string('ui_' . $view, 'local_reportium');
        }
        $value = json_encode(self::chosen($report));
        parent::__construct('local_reportium', 'report_views', (int) $report->id,
            report_roles::can_edit($report), $value, $value);
        $name = format_string($report->name, true, ['context' => report_roles::report_context($report)]);
        $this->edithint = get_string('reportviews_edit', 'local_reportium', $name);
        $this->editlabel = get_string('reportviews_edit', 'local_reportium', $name);
        $this->set_type_autocomplete($this->viewnames, ['multiple' => true]);
    }

    /**
     * Chart types offered by a report (empty field = all).
     *
     * @param stdClass $report
     * @return string[]
     */
    public static function chosen(stdClass $report): array {
        $views = !empty($report->views) ? array_values(array_intersect(self::VIEWS, explode(',', $report->views))) : [];
        return $views ?: self::VIEWS;
    }

    /**
     * Export for template: the chosen chart types, or "All chart types".
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output) {
        $views = (array) json_decode($this->value);
        $names = array_map(function($view) {
            return $this->viewnames[$view] ?? $view;
        }, $views);
        $this->displayvalue = count($views) === count(self::VIEWS) ? get_string('reportviews_all', 'local_reportium')
            : s(implode(', ', $names));
        return parent::export_for_template($output);
    }

    /**
     * Save new chart types (called by core_update_inplace_editable through local_reportium_inplace_editable()).
     *
     * @param int $itemid report id
     * @param string $newvalue JSON array of chart types
     * @return self
     */
    public static function update($itemid, $newvalue) {
        global $DB;
        $report = $DB->get_record('local_reportium', ['id' => clean_param($itemid, PARAM_INT)], '*', MUST_EXIST);
        external_api::validate_context(report_roles::report_context($report));
        if (!report_roles::can_edit($report)) {
            throw new \required_capability_exception(report_roles::report_context($report), 'local/reportium:manage',
                'nopermissions', '');
        }
        $views = array_values(array_intersect(self::VIEWS, (array) json_decode((string) $newvalue, true)));
        $DB->set_field('local_reportium', 'views', $views && count($views) < count(self::VIEWS) ? implode(',', $views) : null,
            ['id' => $report->id]);
        return new self($DB->get_record('local_reportium', ['id' => $report->id], '*', MUST_EXIST));
    }
}
