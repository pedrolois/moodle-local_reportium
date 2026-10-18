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
 * In-place editable "Roles" of a report in the Reports manager: the same multi-select (chips) as the roles column
 * of the core Participants page (core_user\output\user_roles_editable), saved through core_update_inplace_editable.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_roles_editable extends \core\output\inplace_editable {
    /** @var string[] roleid => localised name */
    protected $rolenames = [];

    /**
     * Constructor.
     *
     * @param stdClass $report
     * @param int[] $roleids roles of the report
     */
    public function __construct(stdClass $report, array $roleids) {
        $context = report_roles::report_context($report);
        $this->rolenames = report_roles::options($context);
        $value = json_encode(array_values(array_map('intval', $roleids)));
        parent::__construct('local_reportium', 'report_roles', (int) $report->id, report_roles::can_share($report), $value, $value);

        $name = format_string($report->name, true, ['context' => $context]);
        $this->edithint = get_string('reportroles_edit', 'local_reportium', $name);
        $this->editlabel = get_string('reportroles_edit', 'local_reportium', $name);
        $this->set_type_autocomplete($this->rolenames, ['multiple' => true]);
    }

    /**
     * Export for template: the role names, or "Default permissions" when none is chosen.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output) {
        $names = [];
        foreach ((array) json_decode($this->value) as $roleid) {
            if (isset($this->rolenames[(int) $roleid])) {
                $names[] = $this->rolenames[(int) $roleid];
            }
        }
        $this->displayvalue = $names ? s(implode(', ', $names)) : get_string('reportroles_none', 'local_reportium');
        return parent::export_for_template($output);
    }

    /**
     * Save new roles (called by core_update_inplace_editable through local_reportium_inplace_editable()).
     *
     * @param int $itemid report id
     * @param string $newvalue JSON array of role ids
     * @return self
     */
    public static function update($itemid, $newvalue) {
        global $DB;
        $report = $DB->get_record('local_reportium', ['id' => clean_param($itemid, PARAM_INT)], '*', MUST_EXIST);
        external_api::validate_context(report_roles::report_context($report));
        if (!report_roles::can_share($report)) {
            throw new \required_capability_exception(
                report_roles::report_context($report),
                'local/reportium:share',
                'nopermissions',
                ''
            );
        }
        $options = report_roles::options(report_roles::report_context($report));
        $roleids = array_filter(array_map(static function ($roleid) {
            return clean_param($roleid, PARAM_INT);
        }, (array) json_decode((string) $newvalue, true)), static function ($roleid) use ($options) {
            return isset($options[$roleid]);
        });
        report_roles::set((int) $report->id, $roleids);
        return new self($report, report_roles::get((int) $report->id));
    }
}
