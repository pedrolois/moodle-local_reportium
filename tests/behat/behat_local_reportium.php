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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

use Behat\Mink\Exception\ExpectationException;

/**
 * Behat steps and pages of local_reportium.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_reportium extends behat_base {
    /**
     * Pages without an instance: "Reports" (the list of the site reports).
     *
     * Recognised page names are:
     * | Page     | Description                 |
     * | Reports  | Reports of the site         |
     *
     * @param string $page
     * @return moodle_url
     */
    protected function resolve_page_url(string $page): moodle_url {
        switch (strtolower($page)) {
            case 'reports':
                return new moodle_url('/local/reportium/managereport.php');
            default:
                throw new Exception("Unrecognised local_reportium page '{$page}'");
        }
    }

    /**
     * Pages of one report, by its name.
     *
     * Recognised page types are:
     * | Type             | Description                        |
     * | View             | The interactive report             |
     * | Edit             | Settings of the report             |
     * | Scheduled emails | Scheduled emails of the report     |
     *
     * @param string $type
     * @param string $identifier name of the report
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        global $DB;
        $id = $DB->get_field('local_reportium', 'id', ['name' => $identifier]);
        if (!$id) {
            throw new Exception("Unknown report '{$identifier}'");
        }
        switch (strtolower($type)) {
            case 'view':
                return new moodle_url('/local/reportium/viewreport.php', ['id' => $id]);
            case 'edit':
                return new moodle_url('/local/reportium/editreport.php', ['id' => $id]);
            case 'scheduled emails':
                return new moodle_url('/local/reportium/schedules.php', ['id' => $id]);
            default:
                throw new Exception("Unrecognised local_reportium page type '{$type}'");
        }
    }

    /**
     * Wait until the interactive report has loaded the rows of its level.
     *
     * @Given /^I wait until the Reportium report is ready$/
     */
    public function i_wait_until_the_reportium_report_is_ready(): void {
        $this->ensure_element_exists(
            '.reportium-app:not(.is-loading) [data-region="toolbar"] [data-action="view"], ' .
                '.reportium-app:not(.is-loading) [data-region="error"], ' .
                '.reportium-app:not(.is-loading) [data-region="no-sql"]',
            'css_element'
        );
        $this->execute('behat_general::wait_until_the_page_is_ready');
    }

    /**
     * Open a drawer of the report from its tab on the right edge: Filters, Chart settings or SQL.
     *
     * @Given /^I open the Reportium "(?P<panel_string>(?:[^"]|\\")*)" panel$/
     * @param string $panel filters, settings or sql
     */
    public function i_open_the_reportium_panel(string $panel): void {
        $panel = strtolower($panel);
        $this->execute('behat_general::i_click_on', [
            "[data-region='sidetabs'] [data-action='toggle-panel'][data-panel='{$panel}']",
            'css_element',
        ]);
        $this->ensure_element_is_visible("[data-region='{$panel}'] .reportium-sql-drawer.is-open", 'css_element');
        if ($panel === 'settings') {
            // Every section unfolded.
            if ($this->getSession()->getPage()->find('css', '.reportium-settings-section:not([open])')) {
                $this->execute('behat_general::i_click_on', ["[data-action='settings-toggle-all']", 'css_element']);
            }
            $this->ensure_element_does_not_exist('.reportium-settings-section:not([open])', 'css_element');
        }
    }

    /**
     * Drill down from the row of the table whose first cell is the given text, and wait for the next level.
     *
     * @Given /^I drill down into the "(?P<label_string>(?:[^"]|\\")*)" row of the Reportium report$/
     * @param string $label
     */
    public function i_drill_down_into_the_row(string $label): void {
        $literal = behat_context_helper::escape($label);
        $xpath = "//*[@data-region='table']//tbody//tr[contains(concat(' ', normalize-space(@class), ' '), ' is-drillable ')]" .
            "[normalize-space(td[1]) = {$literal}]";
        $this->execute('behat_general::i_click_on', [$xpath, 'xpath_element']);
        $this->ensure_element_exists(
            "//*[@data-region='crumb-current'][normalize-space(.) = {$literal}]",
            'xpath_element'
        );
        $this->i_wait_until_the_reportium_report_is_ready();
    }

    /**
     * Check the number of rows shown in the table of the report.
     *
     * @Then /^the Reportium table should have "(?P<count_number>\d+)" rows?$/
     * @param int $count
     */
    public function the_reportium_table_should_have_rows(int $count): void {
        // The table is redrawn after the search box waits for typing to stop: retry until it matches.
        $this->spin(
            function ($context, $count) {
                $rows = $context->getSession()->getPage()->findAll('css', "[data-region='table'] tbody tr");
                if (count($rows) !== $count) {
                    throw new ExpectationException(
                        'The report table has ' . count($rows) . " rows, {$count} expected",
                        $context->getSession()
                    );
                }
                return true;
            },
            $count,
            behat_base::get_extended_timeout()
        );
    }
}
