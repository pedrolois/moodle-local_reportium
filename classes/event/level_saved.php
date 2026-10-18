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

namespace local_reportium\event;

/**
 * The SQL of a report level was saved.
 *
 * Other data: level (0 = level 1), versionid (local_reportium_version).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class level_saved extends \core\event\base {
    /** @var string Verb of the description. */
    protected const VERB = 'saved the SQL of';

    /**
     * Init.
     */
    protected function init() {
        $this->data['objecttable'] = 'local_reportium';
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Name of the event.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_level_saved', 'local_reportium');
    }

    /**
     * Description of the event.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' " . static::VERB . " level " . ((int) $this->other['level'] + 1) .
            " of the report with id '{$this->objectid}'.";
    }

    /**
     * The report.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/local/reportium/viewreport.php', ['id' => $this->objectid]);
    }

    /**
     * Check the other data.
     */
    protected function validate_data() {
        parent::validate_data();
        if (!isset($this->other['level'])) {
            throw new \coding_exception('The level must be set in other.');
        }
    }

    /**
     * Mapping of objectid for backup and restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_reportium', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Mapping of other for backup and restore.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['level' => \core\event\base::NOT_MAPPED, 'versionid' => \core\event\base::NOT_MAPPED];
    }
}
