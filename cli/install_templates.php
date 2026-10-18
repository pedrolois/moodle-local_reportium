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
 * Install ready-made Reportium report templates as site reports.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_reportium\local\report_templates;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'list' => false, 'all' => false, 'template' => '', 'courseid' => SITEID, 'force' => false],
    ['h' => 'help', 'l' => 'list', 'a' => 'all', 't' => 'template', 'f' => 'force']
);

if ($options['help'] || (!$options['list'] && !$options['all'] && $options['template'] === '')) {
    echo "Install Reportium report templates.

Options:
  -l, --list            List the available templates
  -a, --all             Install every template not installed yet (matched by name)
  -t, --template=KEY    Install one template (comma-separated keys allowed)
      --courseid=ID     Course the reports belong to (default: site)
  -f, --force           Install even if a report with the same name exists
  -h, --help            Print this help

Example:
  php local/reportium/cli/install_templates.php --all
";
    exit(0);
}

if ($options['list']) {
    foreach (report_templates::list() as $key => $name) {
        cli_writeln(str_pad($key, 18) . $name);
    }
    exit(0);
}

$admin = get_admin();
\core\session\manager::set_user($admin);
$keys = $options['all'] ? array_keys(report_templates::definitions()) : array_map('trim', explode(',', $options['template']));
$names = report_templates::list();
foreach ($keys as $key) {
    if (!isset($names[$key])) {
        cli_error("Unknown template: $key");
    }
    if (!$options['force'] && $DB->record_exists('local_reportium', ['template' => $key, 'courseid' => $options['courseid']])) {
        cli_writeln("skip  $key ({$names[$key]} already installed)");
        continue;
    }
    $id = report_templates::install($key, (int) $options['courseid'], (int) $admin->id);
    cli_writeln("ok    $key -> report $id ({$names[$key]})");
}
