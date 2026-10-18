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
 * Capabilities of Reportium.
 *
 * Who sees a report is decided twice: the user needs local/reportium:view, and one of the roles the report is shared
 * with (chosen per report). Those who manage a report (local/reportium:manage, or its owner with
 * local/reportium:create) always see it.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$capabilities = [

    // Open the reports shared with one of the user's roles.
    'local/reportium:view' => [
        'captype' => 'read',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'coursecreator' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'student' => CAP_ALLOW,
            'user' => CAP_ALLOW,
        ],
    ],

    // Download and print the data of the reports the user can open.
    'local/reportium:download' => [
        'captype' => 'read',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Create reports, and edit, hide, duplicate and delete the user's own reports.
    'local/reportium:create' => [
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Edit, hide, duplicate and delete every report of the context (on the site: of every course).
    'local/reportium:manage' => [
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL | RISK_DATALOSS,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Choose the roles a report the user can edit is shared with.
    'local/reportium:share' => [
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
        ],
    ],

    // Write and change the SQL of reports: it reads any table of the database.
    'local/reportium:writesql' => [
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL | RISK_XSS | RISK_CONFIG | RISK_DATALOSS,
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
    ],

    // Import report files and export reports (with their SQL) to files.
    'local/reportium:transfer' => [
        'captype' => 'write',
        'riskbitmask' => RISK_CONFIG,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Email reports on a schedule (the user's own schedules; local/reportium:manage manages every schedule).
    'local/reportium:schedule' => [
        'captype' => 'write',
        'riskbitmask' => RISK_PERSONAL | RISK_SPAM,
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // Use the AI assistant of reports, and its instructions file for AI chats outside Moodle.
    'local/reportium:useai' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

];
