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
 * Upgrade steps for Reportium.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_reportium_upgrade($oldversion) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026100900) {
        // Per-level chart mapping overrides (generic levels).
        $table = new xmldb_table('local_reportium_level');
        $field = new xmldb_field('config', XMLDB_TYPE_TEXT, null, null, null, null, null, 'sqlquery');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026100900, 'local', 'reportium');
    }

    if ($oldversion < 2026100904) {
        // Reports installed from a ready-made template ("Reportium default") remember the template key.
        $table = new xmldb_table('local_reportium');
        $field = new xmldb_field('template', XMLDB_TYPE_CHAR, '50', null, null, null, null, 'displayprintbutton');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        // Mark the templates installed before this field existed (by their name), then install the missing ones.
        \local_reportium\local\report_templates::mark_existing();
        \local_reportium\local\report_templates::install_missing();
        upgrade_plugin_savepoint(true, 2026100904, 'local', 'reportium');
    }

    if ($oldversion < 2026100905) {
        // Reports whose SQL was stored in their components (before levels) get it as their first level.
        $rs = $DB->get_recordset('local_reportium', null, '', 'id, type, components');
        foreach ($rs as $report) {
            $querysql = \local_reportium\local\report_transfer::components_sql((string) $report->components);
            if ($report->type === 'sql' && trim($querysql) !== ''
                    && !$DB->record_exists('local_reportium_level', ['reportid' => $report->id, 'drilllevel' => 0])) {
                \local_reportium\local\level_service::save_root_sql((int) $report->id, $querysql);
            }
        }
        $rs->close();
        upgrade_plugin_savepoint(true, 2026100905, 'local', 'reportium');
    }

    if ($oldversion < 2026100906) {
        // Roles allowed to view each report (Reports manager "Roles" column).
        $table = new xmldb_table('local_reportium_role');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('reportid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('roleid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('usermodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('fk_reportid', XMLDB_KEY_FOREIGN, ['reportid'], 'local_reportium', ['id']);
        $table->add_key('fk_roleid', XMLDB_KEY_FOREIGN, ['roleid'], 'role', ['id']);
        $table->add_index('reportid_roleid', XMLDB_INDEX_UNIQUE, ['reportid', 'roleid']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026100906, 'local', 'reportium');
    }

    if ($oldversion < 2026100907) {
        // Default role of every report without roles: Manager (users change it in the Reports manager).
        foreach ($DB->get_fieldset_select('local_reportium', 'id', '1 = 1') as $reportid) {
            \local_reportium\local\report_roles::set_defaults((int) $reportid);
        }
        upgrade_plugin_savepoint(true, 2026100907, 'local', 'reportium');
    }

    if ($oldversion < 2026100908) {
        // Chart types offered by each report (Bar, Pie, Line, Table), chosen when creating or editing it.
        $table = new xmldb_table('local_reportium');
        $field = new xmldb_field('views', XMLDB_TYPE_CHAR, '100', null, null, null, null, 'displayprintbutton');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026100908, 'local', 'reportium');
    }

    if ($oldversion < 2026100909) {
        // Report repository settings no longer exist.
        unset_config('crrepository', 'local_reportium');
        unset_config('sharedsqlrepository', 'local_reportium');
        upgrade_plugin_savepoint(true, 2026100909, 'local', 'reportium');
    }

    if ($oldversion < 2026100910) {
        // Downloads use Moodle's data formats: xls -> excel; formats without a data format (slk) are dropped.
        foreach ($DB->get_records('local_reportium', null, '', 'id, export') as $report) {
            $formats = [];
            foreach (explode(',', (string) $report->export) as $format) {
                $format = trim($format) === 'xls' ? 'excel' : trim($format);
                if ($format !== '' && $format !== 'slk' && !in_array($format, $formats, true)) {
                    $formats[] = $format;
                }
            }
            $DB->set_field('local_reportium', 'export', $formats ? implode(',', $formats) . ',' : '', ['id' => $report->id]);
        }
        upgrade_plugin_savepoint(true, 2026100910, 'local', 'reportium');
    }

    if ($oldversion < 2026100911) {
        // Settings that no longer exist: remote database, scheduled runs, write statements, table UI, CSV delimiter.
        foreach (['dbhost', 'dbname', 'dbuser', 'dbpass', 'cron_hour', 'cron_minute', 'sqlsecurity', 'reporttableui',
                'csvdelimiter', 'enable_drilldown'] as $name) {
            unset_config($name, 'local_reportium');
        }
        upgrade_plugin_savepoint(true, 2026100911, 'local', 'reportium');
    }

    if ($oldversion < 2026100912) {
        // Report fields no longer used: paging and sorting live in each level, access in the report roles,
        // the execution time is measured per level.
        $table = new xmldb_table('local_reportium');
        foreach (['pagination', 'components', 'jsordering', 'lastexecutiontime', 'cron', 'remote'] as $name) {
            $field = new xmldb_field($name);
            if ($dbman->field_exists($table, $field)) {
                $dbman->drop_field($table, $field);
            }
        }
        // Template goals are not used.
        $DB->delete_records_select('config_plugins', "plugin = 'local_reportium' AND " .
            $DB->sql_like('name', ':name'), ['name' => 'reporttemplategoal\_report\_%']);
        upgrade_plugin_savepoint(true, 2026100912, 'local', 'reportium');
    }

    if ($oldversion < 2026100914) {
        // The AI assistant uses Moodle's AI subsystem: its own provider settings are gone.
        foreach (['openai_api_key', 'openai_model', 'openai_endpoint', 'anthropic_api_key'] as $name) {
            unset_config($name, 'local_reportium');
        }
        upgrade_plugin_savepoint(true, 2026100914, 'local', 'reportium');
    }

    if ($oldversion < 2026101000) {
        // New ready-made report: Site activity (all logs).
        \local_reportium\local\report_templates::install_missing();
        upgrade_plugin_savepoint(true, 2026101000, 'local', 'reportium');
    }

    if ($oldversion < 2026101001) {
        // New ready-made reports (who did what to whom, daily activity, logins), and the event details level of the
        // activity reports.
        \local_reportium\local\report_templates::install_missing();
        \local_reportium\local\report_templates::add_missing_levels();
        upgrade_plugin_savepoint(true, 2026101001, 'local', 'reportium');
    }

    if ($oldversion < 2026101003) {
        // Every report is an SQL report: reports of other types (they cannot be opened) and the type column go away.
        $ids = $DB->get_fieldset_select('local_reportium', 'id', "type <> 'sql'");
        if ($ids) {
            [$insql, $params] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select('local_reportium_level', "reportid $insql", $params);
            $DB->delete_records_select('local_reportium_role', "reportid $insql", $params);
            $DB->delete_records_select('local_reportium', "id $insql", $params);
        }
        $table = new xmldb_table('local_reportium');
        $field = new xmldb_field('type');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026101003, 'local', 'reportium');
    }

    if ($oldversion < 2026101004) {
        // Who writes SQL is decided by local/reportium:writesql alone: the list of usernames goes away. The old
        // capabilities are replaced by view, download, create, manage, share, writesql, transfer and useai (Moodle
        // removes the old ones and gives the new ones to the default roles).
        unset_config('allowedsqlusers', 'local_reportium');
        upgrade_plugin_savepoint(true, 2026101004, 'local', 'reportium');
    }

    if ($oldversion < 2026101005) {
        // Period of the data (%%STARTTIME%% / %%ENDTIME%%), history of the SQL of levels, scheduled email delivery.
        $table = new xmldb_table('local_reportium');
        $field = new xmldb_field('daterange', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'displayprintbutton');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $structure = (new xmldb_file($CFG->dirroot . '/local/reportium/db/install.xml'));
        $structure->loadXMLStructure();
        foreach (['local_reportium_version', 'local_reportium_schedule', 'local_reportium_sendlog'] as $name) {
            $newtable = $structure->getStructure()->getTable($name);
            if (!$dbman->table_exists($name)) {
                $dbman->create_table($newtable);
            }
        }
        // Log reports filter by period: their SQL gets the date condition where it is still the original one.
        \local_reportium\local\report_templates::add_date_ranges();
        upgrade_plugin_savepoint(true, 2026101005, 'local', 'reportium');
    }

    if ($oldversion < 2026101007) {
        // Report defaults of the KPI cards and the totals row.
        $table = new xmldb_table('local_reportium');
        $fields = [
            new xmldb_field('kpicards', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'daterange'),
            new xmldb_field('kpirows', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1', 'kpicards'),
            new xmldb_field('totalsrow', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'kpirows'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_plugin_savepoint(true, 2026101007, 'local', 'reportium');
    }

    if ($oldversion < 2026101008) {
        // New reports start without KPI cards (reports that exist keep theirs).
        $table = new xmldb_table('local_reportium');
        foreach (['kpicards' => 'daterange', 'kpirows' => 'kpicards'] as $name => $previous) {
            $field = new xmldb_field($name, XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', $previous);
            $dbman->change_field_default($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026101008, 'local', 'reportium');
    }

    if ($oldversion < 2026101009) {
        // Alerts: the condition a schedule needs to be sent.
        $table = new xmldb_table('local_reportium_schedule');
        $field = new xmldb_field('sendcondition', XMLDB_TYPE_TEXT, null, null, null, null, null, 'daterange');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026101009, 'local', 'reportium');
    }

    if ($oldversion < 2026101010) {
        // The PNG of the chart becomes an option of each report: those that offered downloads keep it.
        $DB->execute("UPDATE {local_reportium} SET export = " . $DB->sql_concat('export', "'png,'") .
            " WHERE export IS NOT NULL AND export <> '' AND export NOT LIKE '%png%'");
        upgrade_plugin_savepoint(true, 2026101010, 'local', 'reportium');
    }

    return true;
}
