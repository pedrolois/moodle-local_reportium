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

use local_reportium\external\levels;

/**
 * Tests of the history of the SQL of levels: versions on save and delete, events, restore, limits and access.
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\sql_history
 * @covers     \local_reportium\external\levels
 * @covers     \local_reportium\event\level_saved
 */
final class sql_history_test extends \advanced_testcase {
    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->report = $this->getDataGenerator()->get_plugin_generator('local_reportium')->create_report(['name' => 'History']);
    }

    public function test_saves_and_deletes_keep_versions_and_log_events(): void {
        $sink = $this->redirectEvents();
        $this->assertTrue(levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id, 2 AS n')['success']);
        // The same SQL again: no new version.
        levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id, 2 AS n');
        levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id, 3 AS n');
        levels::save_level((int) $this->report->id, 1, 'SELECT 1 AS id, %%PREVIOUSVALUE%% AS v');
        levels::delete_level((int) $this->report->id, 1);
        $events = array_map(fn($e) => get_class($e), $sink->get_events());
        $sink->close();

        $versions = levels::get_level_history((int) $this->report->id, 0)['versions'];
        $this->assertCount(2, $versions);
        $this->assertSame('SELECT 1 AS id, 3 AS n', $versions[0]['sqlquery']);
        $this->assertSame(fullname(get_admin()), $versions[0]['userfullname']);
        $deleted = levels::get_level_history((int) $this->report->id, 1)['versions'];
        $this->assertSame(['deleted', 'saved'], array_column($deleted, 'action'));
        $this->assertContains(\local_reportium\event\level_saved::class, $events);
        $this->assertContains(\local_reportium\event\level_deleted::class, $events);
        $this->assertCount(4, array_filter($events, fn($class) => strpos($class, 'local_reportium\\event\\level_') === 0));
    }

    public function test_restore_a_version_and_a_deleted_level(): void {
        levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id, 2 AS n');
        levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id, 3 AS n');
        $first = end(levels::get_level_history((int) $this->report->id, 0)['versions']);
        $result = levels::restore_level_version((int) $this->report->id, $first['id']);
        $this->assertTrue($result['success']);
        $this->assertSame('SELECT 1 AS id, 2 AS n', level_service::get_root_sql((int) $this->report->id));
        $this->assertSame('restored', levels::get_level_history((int) $this->report->id, 0)['versions'][0]['action']);

        // A deleted level comes back as the next level.
        levels::save_level((int) $this->report->id, 1, 'SELECT 1 AS id, %%PREVIOUSVALUE%% AS v');
        levels::delete_level((int) $this->report->id, 1);
        $deleted = levels::get_level_history((int) $this->report->id, 1)['versions'][0];
        $this->assertSame(1, levels::restore_level_version((int) $this->report->id, $deleted['id'])['level']);
        $this->assertCount(2, level_service::get_levels((int) $this->report->id));
    }

    public function test_a_deleted_level_cannot_leave_a_gap(): void {
        levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id');
        levels::save_level((int) $this->report->id, 1, 'SELECT 1 AS id, %%PREVIOUSVALUE%% AS v');
        levels::save_level((int) $this->report->id, 2, 'SELECT 1 AS id, %%PREVIOUSVALUE%% AS w');
        levels::delete_level((int) $this->report->id, 2);
        levels::delete_level((int) $this->report->id, 1);
        $level3 = levels::get_level_history((int) $this->report->id, 2)['versions'][0];
        $this->expectException(\moodle_exception::class);
        levels::restore_level_version((int) $this->report->id, $level3['id']);
    }

    public function test_restoring_validates_the_sql_and_old_versions_are_trimmed(): void {
        global $DB;
        levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id');
        $bad = $DB->insert_record(sql_history::TABLE, (object) ['reportid' => $this->report->id, 'drilllevel' => 0,
            'label' => '', 'sqlquery' => 'DELETE FROM {user}', 'action' => 'saved', 'timecreated' => 1, 'usermodified' => 2]);
        $result = levels::restore_level_version((int) $this->report->id, $bad);
        $this->assertFalse($result['success']);
        $this->assertSame('SELECT 1 AS id', level_service::get_root_sql((int) $this->report->id));

        for ($i = 0; $i < sql_history::KEEP + 5; $i++) {
            sql_history::record($this->report, 3, '', "SELECT $i AS id", 'saved', 2);
        }
        $this->assertSame(sql_history::KEEP, $DB->count_records(sql_history::TABLE, ['reportid' => $this->report->id,
            'drilllevel' => 3]));
        // The newest versions are the ones kept.
        $newest = sql_history::versions((int) $this->report->id, 3);
        $this->assertSame('SELECT ' . (sql_history::KEEP + 4) . ' AS id', $newest[0]['sqlquery']);
        $this->assertSame('SELECT 5 AS id', end($newest)['sqlquery']);
        sql_history::delete_report((int) $this->report->id);
        $this->assertSame(0, $DB->count_records(sql_history::TABLE, ['reportid' => $this->report->id]));
    }

    public function test_only_sql_writers_see_the_history(): void {
        levels::save_level((int) $this->report->id, 0, 'SELECT 1 AS id');
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);
        $this->expectException(\required_capability_exception::class);
        levels::get_level_history((int) $this->report->id, 0);
    }
}
