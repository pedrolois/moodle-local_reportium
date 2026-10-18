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
 * Tests of the links to Moodle: which name columns link to a user, a course or a category, and when they do not.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\column_inspector
 * @covers     \local_reportium\local\level_service
 */
final class column_links_test extends \advanced_testcase {

    /**
     * Columns of a level from their aliases (types do not matter for links).
     *
     * @param string ...$keys
     * @return array[]
     */
    private function columns(string ...$keys): array {
        return array_map(fn($key) => ['key' => $key, 'label' => $key, 'type' => 'text'], $keys);
    }

    public function test_name_columns_next_to_their_id(): void {
        $links = column_inspector::links($this->columns('userid', 'fullname', 'courseid', 'course', 'events'));
        $this->assertSame(['kind' => 'user', 'idkey' => 'userid', 'courseidkey' => 'courseid'], $links['fullname']);
        $this->assertSame(['kind' => 'course', 'idkey' => 'courseid', 'courseidkey' => null], $links['course']);
        $this->assertArrayNotHasKey('events', $links);

        $links = column_inspector::links($this->columns('categoryid', 'category', 'courses'));
        $this->assertSame(['category' => ['kind' => 'category', 'idkey' => 'categoryid', 'courseidkey' => null]], $links);
        // Aliases in capitals too.
        $links = column_inspector::links($this->columns('UserId', 'Learner'));
        $this->assertSame('UserId', $links['Learner']['idkey']);
    }

    public function test_the_id_column_when_the_name_is_unambiguous(): void {
        // A course list.
        $this->assertSame(['course' => ['kind' => 'course', 'idkey' => 'id', 'courseidkey' => null]],
            column_inspector::links($this->columns('id', 'course', 'learners')));
        // People: a full name next to an email.
        $this->assertSame('user', column_inspector::links($this->columns('id', 'fullname', 'email'))['fullname']['kind']);
        // The "fullname" column alone also names courses: no link.
        $this->assertSame([], column_inspector::links($this->columns('id', 'fullname', 'users')));
        // Two kinds of names and only one id: no link.
        $this->assertSame([], column_inspector::links($this->columns('id', 'course', 'category')));
        // No id at all.
        $this->assertSame([], column_inspector::links($this->columns('course', 'learners')));
    }

    public function test_links_reach_the_payload_unless_switched_off(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $report = $generator->create_report(['name' => 'Links']);
        $generator->create_level($report->id, 0, "SELECT c.id, c.fullname AS course FROM {course} c");
        $payload = level_service::get_level_payload($report, 0, [], \context_system::instance(), (int) get_admin()->id);
        $this->assertSame('course', $payload['links']['course']['kind']);
        $data = \local_reportium\external\levels::get_level_data((int) $report->id);
        $this->assertSame('course', json_decode($data['links'], true)['course']['kind']);

        set_config('autolinks', 0, 'local_reportium');
        \cache::make('local_reportium', 'reportdata')->purge();
        $payload = level_service::get_level_payload($report, 0, [], \context_system::instance(), (int) get_admin()->id);
        $this->assertSame([], $payload['links']);
    }

    public function test_the_kpi_switch_is_kept_in_the_settings_of_a_level(): void {
        $columns = [['key' => 'name', 'label' => 'Name', 'type' => 'text'], ['key' => 'n', 'label' => 'N', 'type' => 'number']];
        $defaults = column_inspector::default_mapping($columns);
        $this->assertArrayNotHasKey('kpis', column_inspector::merge_mapping($defaults, [], $columns));
        $this->assertFalse(column_inspector::merge_mapping($defaults, ['kpis' => false], $columns)['kpis']);
        // A switch on (older settings) = automatic cards.
        $this->assertArrayNotHasKey('kpis', column_inspector::merge_mapping($defaults, ['kpis' => 1], $columns));
        // Chosen cards: only known columns and calculations; the rows card is always a count.
        $kpis = column_inspector::merge_mapping($defaults, ['kpis' => [['key' => '_rows', 'agg' => 'sum'],
            ['key' => 'n', 'agg' => 'avg'], ['key' => 'name', 'agg' => 'distinct'], ['key' => 'nope', 'agg' => 'sum'],
            ['key' => 'n', 'agg' => 'median'], 'junk']], $columns)['kpis'];
        $this->assertSame([['key' => '_rows', 'agg' => 'count'], ['key' => 'n', 'agg' => 'avg'],
            ['key' => 'name', 'agg' => 'distinct']], $kpis);
        // Each card once.
        $twice = column_inspector::merge_mapping($defaults, ['kpis' => [['key' => 'n', 'agg' => 'sum'],
            ['key' => 'n', 'agg' => 'sum'], ['key' => 'n', 'agg' => 'max'], ['key' => '_rows', 'agg' => 'count'],
            ['key' => '_rows', 'agg' => 'count']]], $columns)['kpis'];
        $this->assertSame([['key' => 'n', 'agg' => 'sum'], ['key' => 'n', 'agg' => 'max'],
            ['key' => '_rows', 'agg' => 'count']], $twice);
        $this->assertTrue(column_inspector::merge_mapping($defaults, ['totalsrow' => 1], $columns)['totalsrow']);
    }
}
