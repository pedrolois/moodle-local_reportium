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
 * Admin settings of Reportium and its entries in Site administration.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

// Site administration › Reports: open the site-level reports of Reportium.
$ADMIN->add(
    'reports',
    new admin_externalpage(
        'local_reportium_sitereports',
        get_string('adminlinkname', 'local_reportium'),
        new moodle_url('/local/reportium/managereport.php', ['courseid' => SITEID]),
        ['local/reportium:manage', 'local/reportium:create']
    )
);

if ($hassiteconfig) {
    $ADMIN->add(
        'localplugins',
        new admin_externalpage(
            'local_reportium_manage',
            get_string('pluginname', 'local_reportium'),
            new moodle_url('/local/reportium/managereport.php', ['courseid' => SITEID]),
            ['local/reportium:manage', 'local/reportium:create']
        )
    );

    // First setup assistant.
    $ADMIN->add(
        'localplugins',
        new admin_externalpage(
            'local_reportium_setup',
            get_string('setup_title', 'local_reportium'),
            new moodle_url('/local/reportium/setup.php'),
            'moodle/site:config'
        )
    );

    // One settings page with a tab per group of settings (the same tabs as theme settings).
    $settings = new theme_boost_admin_settingspage_tabs(
        'local_reportium_settings',
        get_string('settingspage', 'local_reportium')
    );

    if ($ADMIN->fulltree) {
        // General.
        $page = new admin_settingpage('local_reportium_general', get_string('reportiumheading', 'local_reportium'));
        $page->add(new admin_setting_heading(
            'local_reportium/heading',
            '',
            get_string('reportiumheading_desc', 'local_reportium')
        ));
        // The first setup assistant, to walk through it again at any time (a guided tour of the main options).
        $page->add(new admin_setting_heading(
            'local_reportium/setuptour',
            '',
            html_writer::div(
                html_writer::tag('i', '', ['class' => 'fa fa-route fa-map-signs reportium-tour-icon', 'aria-hidden' => 'true']) .
                html_writer::div(html_writer::tag('strong', get_string('setup_tour_title', 'local_reportium')) .
                    html_writer::div(get_string('setup_tour_desc', 'local_reportium'), 'small text-muted'), 'reportium-tour-text') .
                html_writer::link(
                    new moodle_url('/local/reportium/setup.php'),
                    get_string('setup_tour_open', 'local_reportium'),
                    ['class' => 'btn btn-primary', 'data-action' => 'open-setup-tour']
                ),
                'reportium-tour-card'
            )
        ));
        $page->add(new admin_setting_configtext(
            'local_reportium/reportlimit',
            get_string('reportlimit', 'local_reportium'),
            get_string('reportlimitinfo', 'local_reportium'),
            '5000',
            PARAM_INT,
            6
        ));
        $page->add(new admin_setting_configcheckbox(
            'local_reportium/autolinks',
            get_string('settings_autolinks', 'local_reportium'),
            get_string('settings_autolinks_desc', 'local_reportium'),
            1
        ));
        $page->add(new admin_setting_configtext(
            'local_reportium/slowquerysecs',
            get_string('settings_slowquerysecs', 'local_reportium'),
            get_string('settings_slowquerysecs_desc', 'local_reportium'),
            (string) \local_reportium\local\sql_advisor::DEFAULT_SECONDS,
            PARAM_FLOAT,
            6
        ));
        // Data freshness: the "Live data" / "Cached" badge of reports and the cache of new reports.
        $page->add(new admin_setting_heading(
            'local_reportium/freshness_heading',
            get_string('settings_freshness', 'local_reportium'),
            get_string('settings_freshness_desc', 'local_reportium')
        ));
        $page->add(new admin_setting_configcheckbox(
            'local_reportium/showfreshness',
            get_string('settings_showfreshness', 'local_reportium'),
            get_string('settings_showfreshness_desc', 'local_reportium'),
            1
        ));
        $page->add(new admin_setting_configtext(
            'local_reportium/defaultcachettl',
            get_string('settings_defaultcachettl', 'local_reportium'),
            get_string('settings_defaultcachettl_desc', 'local_reportium'),
            0,
            PARAM_INT,
            6
        ));
        // Grades: recalculate the courses whose gradebook needs it (scheduled task).
        $page->add(new admin_setting_heading(
            'local_reportium/grades_heading',
            get_string('settings_grades', 'local_reportium'),
            ''
        ));
        $page->add(new admin_setting_configcheckbox(
            'local_reportium/regradetask',
            get_string('settings_regradetask', 'local_reportium'),
            get_string('settings_regradetask_desc', 'local_reportium', (new moodle_url(
                '/admin/tool/task/scheduledtasks.php',
                ['action' => 'edit', 'task' => 'local_reportium\task\regrade_courses']
            ))->out()),
            1
        ));
        $settings->add($page);

        // Column formats: which columns of the SQL are shown as progress bars or state labels, and their colours.
        $formatdefaults = [];
        foreach (\local_reportium\local\instructions::get()['formats'] ?? [] as $rule) {
            $formatdefaults[$rule['format'] ?? ''] = $rule;
        }
        $page = new admin_settingpage('local_reportium_formats', get_string('settings_formats', 'local_reportium'));
        $page->add(new admin_setting_heading(
            'local_reportium/formats_heading',
            '',
            get_string('settings_formats_desc', 'local_reportium')
        ));
        $page->add(new admin_setting_configtext(
            'local_reportium/progresskeywords',
            get_string('settings_progresskeywords', 'local_reportium'),
            get_string('settings_progresskeywords_desc', 'local_reportium'),
            implode(', ', $formatdefaults['progress']['keywords'] ?? []),
            PARAM_TEXT,
            60
        ));
        $progresscolors = $formatdefaults['progress']['colors'] ?? [];
        foreach (['low' => '#c71c22', 'mid' => '#f0ad4e', 'high' => '#73a839'] as $name => $color) {
            $page->add(new \local_reportium\admin\setting_colour(
                'local_reportium/progresscolor' . $name,
                get_string('settings_progresscolor' . $name, 'local_reportium'),
                // The fade is explained once, under the first colour.
                $name === 'low' ? get_string('settings_progresscolor_desc', 'local_reportium') : '',
                $progresscolors[$name] ?? $color
            ));
        }
        $page->add(new admin_setting_configtext(
            'local_reportium/statekeywords',
            get_string('settings_statekeywords', 'local_reportium'),
            get_string('settings_statekeywords_desc', 'local_reportium'),
            implode(', ', $formatdefaults['state']['keywords'] ?? []),
            PARAM_TEXT,
            60
        ));
        $page->add(new \local_reportium\admin\setting_states());
        $settings->add($page);

        // AI assistant (Moodle's AI providers).
        $page = new admin_settingpage('local_reportium_ai', get_string('ai_settings_heading', 'local_reportium'));
        // The AI assistant inside the SQL Inspector is a Reportium Pro feature: shown, switched off, until Pro is installed.
        if (\local_reportium\local\extensions::has_sql_assistant()) {
            $page->add(new admin_setting_heading('local_reportium/ai_pro', '', get_string(
                'ai_pro_installed',
                'local_reportium',
                (new moodle_url('/admin/settings.php', ['section' => 'local_reportium_pro']))->out()
            )));
        } else {
            $wand = html_writer::tag('i', '', ['class' => 'fa fa-wand-magic-sparkles fa-magic', 'aria-hidden' => 'true']);
            $page->add(new admin_setting_heading(
                'local_reportium/ai_pro',
                '',
                html_writer::div(
                    html_writer::empty_tag('input', ['type' => 'checkbox', 'disabled' => 'disabled', 'id' => 'reportium-ai-pro',
                        'class' => 'form-check-input']) .
                    html_writer::label(
                        $wand . ' ' .
                        html_writer::span(
                            get_string('pro_feature', 'local_reportium'),
                            'badge bg-warning text-dark badge-warning'
                        ) .
                        ' ' . get_string('enable_ai_chat', 'local_reportium'),
                        'reportium-ai-pro',
                        false,
                        ['class' => 'form-check-label']
                    ) .
                    html_writer::div(get_string('ai_pro_desc', 'local_reportium'), 'small text-muted mt-1'),
                    // On hover: not ready yet, a paid feature coming soon.
                    'form-check reportium-pro-setting',
                    ['title' => get_string('ai_pro_soon', 'local_reportium'),
                    'data-toggle' => 'tooltip',
                    'data-bs-toggle' => 'tooltip',
                    'data-placement' => 'top']
                )
            ));
        }
        // The instructions file to paste into any AI chat outside Moodle.
        $page->add(new admin_setting_heading(
            'local_reportium/ai_instructions',
            html_writer::tag('i', '', ['class' => 'fa fa-wand-magic-sparkles fa-magic', 'aria-hidden' => 'true']) . ' ' .
            get_string('ai_help_title', 'local_reportium'),
            get_string(
                'ai_settings_instructions',
                'local_reportium',
                (new moodle_url('/local/reportium/aiinstructions.php'))->out()
            ) .
            html_writer::tag(
                'ol',
                implode('', array_map(
                    static fn(int $n) => html_writer::tag(
                        'li',
                        get_string('ai_step' . $n, 'local_reportium')
                    ),
                    [1, 2, 3, 4]
                )),
                ['class' => 'reportium-ai-steps mt-2']
            )
        ));
        $settings->add($page);
    }

    $ADMIN->add('localplugins', $settings);
}
