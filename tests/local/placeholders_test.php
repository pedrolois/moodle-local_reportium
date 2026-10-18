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

namespace local_reportium\local;

/**
 * Tests of the placeholders of a level, the row filters behind the downloads, the saved level settings and the
 * context reports are viewed in.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\level_service
 */
final class placeholders_test extends \advanced_testcase {
    public function test_viewer_placeholders_are_bound(): void {
        $vars = ['userid' => 5, 'courseid' => 9, 'categoryid' => 3, 'mycourses' => [11, 12]];
        [$sql, $params] = level_service::bind_placeholders(
            'SELECT 1 WHERE a = %%USERID%% AND b = %%COURSEID%% AND c = %%CATEGORYID%% AND d IN (%%MYCOURSES%%)',
            [],
            $vars
        );
        $this->assertStringNotContainsString('%%', $sql);
        // Integers go in as integer literals (typed on every database, PostgreSQL included).
        $this->assertStringContainsString('a = 5 AND b = 9 AND c = 3 AND d IN (11, 12)', $sql);
        $this->assertSame([], $params);
    }

    public function test_mycourses_every_course_and_no_course(): void {
        [$all] = level_service::bind_placeholders('SELECT 1 WHERE c.id IN (%%MYCOURSES%%)', [], ['mycourses' => null]);
        $this->assertStringContainsString('IN (SELECT id FROM {course})', $all);
        [$none, $params] = level_service::bind_placeholders('SELECT 1 WHERE c.id IN (%%MYCOURSES%%)', [], ['mycourses' => []]);
        $this->assertStringContainsString('IN (-1)', $none);
        $this->assertSame([], $params);
    }

    public function test_other_placeholders(): void {
        global $CFG;
        $before = time();
        [$sql, $params] = level_service::bind_placeholders(
            "SELECT 1 FROM prefix_course WHERE t < %%NOW%% AND u = %%WWWROOT%% AND x BETWEEN %%STARTTIME%% AND %%ENDTIME%%"
            . " %%FILTER_COURSES:c.id%% %%FILTER_USERS_profile_org:uo.data:~%% %%DEBUG%%",
            []
        );
        $this->assertStringContainsString($CFG->prefix . 'course', $sql);
        $this->assertStringNotContainsString('%%', $sql);
        $this->assertSame([], $params);
        $this->assertMatchesRegularExpression('/t < (\d+) AND/', $sql);
        preg_match('/t < (\d+) AND/', $sql, $now);
        $this->assertGreaterThanOrEqual($before, (int) $now[1]);
        // The site address goes in as text (so it also works inside the quotes of a string in the SQL).
        $this->assertStringContainsString("u = '" . $CFG->wwwroot . "'", $sql);
        $this->assertStringContainsString('x BETWEEN 0 AND 2145938400', $sql);
    }

    public function test_viewer_placeholders_without_viewer_are_removed(): void {
        // Without viewer values (no $vars), the keywords cannot stay in the SQL: they are removed.
        [$sql, $params] = level_service::bind_placeholders('SELECT 1 WHERE 1 = 1 %%USERID%%', []);
        $this->assertStringNotContainsString('%%', $sql);
        $this->assertSame([], $params);
    }

    public function test_like_queries_keep_their_percent_signs(): void {
        [$sql] = level_service::bind_placeholders("SELECT 1 WHERE name LIKE '%abc%' AND x = %%PREVIOUSVALUE%%", ['v']);
        $this->assertStringContainsString("LIKE '%abc%'", $sql);
    }

    public function test_visible_rows_filters(): void {
        $payload = [
            'columns' => [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
                ['key' => 'n', 'label' => 'N', 'type' => 'number'],
                ['key' => 'day', 'label' => 'Day', 'type' => 'date'],
            ],
            'rows' => [
                ['name' => 'Alpha', 'n' => '5', 'day' => '1767225600'],
                ['name' => 'Beta', 'n' => '15', 'day' => '1769904000'],
                ['name' => 'alphabet', 'n' => '25', 'day' => '1772323200'],
            ],
            'mapping' => ['labelcolumn' => 'name', 'metrics' => ['n'], 'defaultview' => 'bar'],
        ];
        $names = function (array $rows): array {
            return array_column($rows, 'name');
        };
        $this->assertSame(
            ['Alpha', 'alphabet'],
            $names(level_service::visible_rows($payload, '', ['name' => ['like' => 'ALPHA']]))
        );
        $this->assertSame(['Beta'], $names(level_service::visible_rows($payload, '', ['name' => ['in' => ['Beta']]])));
        $this->assertSame(['Beta', 'alphabet'], $names(level_service::visible_rows($payload, '', ['n' => ['min' => '10']])));
        $this->assertSame(['Alpha', 'Beta'], $names(level_service::visible_rows($payload, '', ['n' => ['max' => '15']])));
        // Dates filter From / To as timestamps.
        $this->assertSame(['Beta'], $names(level_service::visible_rows(
            $payload,
            '',
            ['day' => ['min' => '1768000000', 'max' => '1770000000']]
        )));
        // Unknown columns and malformed filters are ignored; search and filters add up.
        $this->assertCount(3, level_service::visible_rows($payload, '', ['gone' => ['in' => ['x']], 'n' => 'bad']));
        $this->assertSame(['alphabet'], $names(level_service::visible_rows($payload, 'alpha', ['n' => ['min' => '20']])));
        // Sort: text ignoring case, dates numerically.
        $this->assertSame(
            ['alphabet', 'Beta', 'Alpha'],
            $names(level_service::visible_rows($payload, '', [], [], 'day:desc'))
        );
        $this->assertSame(['Alpha', 'alphabet', 'Beta'], $names(level_service::visible_rows($payload, '', [], [], 'name:asc')));
    }

    public function test_save_level_config_keeps_only_valid_settings(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_demo_report();
        level_service::save_level_config((int) $report->id, 1, [
            'labelcolumn' => 'fullname<script>', 'defaultview' => 'pie', 'metrics' => ['users', ['nested']],
            'pagesize' => '-4', 'viewtypes' => ['bar', 'radar', 'table'], 'stacked' => 'yes',
            'views' => ['bar' => ['metrics' => ['users'], 'stacked' => 0], 'radar' => ['metrics' => ['x']], 'pie' => 'bad'],
            'formats' => ['users' => 'progress', 'fullname' => 'html', 5 => 'state'],
            'evil' => 'drop me',
        ]);
        $config = json_decode($DB->get_field(level_service::TABLE, 'config', ['reportid' => $report->id, 'drilllevel' => 1]), true);
        $this->assertSame('fullname', $config['labelcolumn']);
        $this->assertSame('pie', $config['defaultview']);
        $this->assertSame(1, $config['pagesize']);
        $this->assertSame(['bar', 'table'], $config['viewtypes']);
        $this->assertTrue($config['stacked']);
        $this->assertSame(['bar' => ['metrics' => ['users'], 'stacked' => false]], $config['views']);
        $this->assertSame(['users' => 'progress'], $config['formats']);
        $this->assertArrayNotHasKey('evil', $config);

        $this->expectException(\dml_missing_record_exception::class);
        level_service::save_level_config((int) $report->id, 9, []);
    }

    public function test_global_reports_run_in_the_course_they_are_viewed_in(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $gen = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $site = $gen->create_report(['global' => 0]);
        $global = $gen->create_report(['global' => 1]);
        $this->assertSame(\context_system::instance()->id, level_service::get_context($site, (int) $course->id)->id);
        $this->assertSame(\context_course::instance($course->id)->id, level_service::get_context($global, (int) $course->id)->id);
        $this->assertSame(\context_system::instance()->id, level_service::get_context($global)->id);

        $vars = level_service::user_vars(\context_course::instance($course->id), 2);
        $this->assertSame((int) $course->id, $vars['courseid']);
        $this->assertSame((int) $course->category, $vars['categoryid']);
        $this->assertNull($vars['mycourses']);
    }

    public function test_root_sql_is_level_zero(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_report();
        $this->assertSame('', level_service::get_root_sql((int) $report->id));
        level_service::save_root_sql((int) $report->id, 'SELECT id, fullname FROM {course}');
        level_service::save_root_sql((int) $report->id, 'SELECT id, shortname FROM {course}');
        $this->assertSame('SELECT id, shortname FROM {course}', level_service::get_root_sql((int) $report->id));
        $this->assertCount(1, level_service::get_levels((int) $report->id));
    }
}
