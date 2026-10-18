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
 * Tests of alerts: scheduled emails sent only when their condition is met (rows, or a value against a threshold).
 *
 * @package    local_reportium
 * @category   test
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_reportium\local\scheduler
 * @covers     \local_reportium\local\exporter
 */
final class schedule_alerts_test extends \advanced_testcase {

    /** @var \stdClass */
    private $report;

    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->noemailever = false;
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $this->report = $generator->create_report(['name' => 'Progress alert']);
        // Completion 40 and 60: average 50, minimum 40, sum 100.
        $generator->create_level($this->report->id, 0,
            "SELECT 1 AS id, 'Alpha' AS name, 40 AS completion_pct, 4 AS done, 10 AS total
             UNION ALL SELECT 2, 'Beta', 60, 6, 10");
    }

    /**
     * A saved schedule of the test report, sent to one user.
     *
     * @param string $condition JSON
     * @param array $view
     * @return \stdClass
     */
    private function schedule(string $condition, array $view = ['level' => 0]): \stdClass {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['email' => 'boss@example.com']);
        $schedule = (object) ['reportid' => $this->report->id, 'userid' => get_admin()->id,
            'recipients' => json_encode(['users' => [$user->id]]), 'frequency' => 'daily', 'weekday' => 1, 'monthday' => 1,
            'hour' => 8, 'format' => 'csv', 'viewstate' => json_encode($view), 'daterange' => '', 'sendcondition' => $condition,
            'subject' => '', 'message' => '', 'enabled' => 1];
        return $DB->get_record(scheduler::TABLE, ['id' => scheduler::save($schedule)]);
    }

    public function test_condition_from_the_form_is_cleaned(): void {
        $this->assertSame('', scheduler::condition_from_form('', 'x', 'avg', 'lt', '1'));
        $this->assertSame('{"type":"rows"}', scheduler::condition_from_form('rows', '', '', '', ''));
        $this->assertSame(['type' => 'value', 'column' => 'completion_pct', 'agg' => 'avg', 'op' => 'lt', 'value' => 50.5],
            json_decode(scheduler::condition_from_form('value', ' completion_pct ', 'avg', 'lt', '50,5'), true));
        // Unknown calculation, comparison, missing column or a threshold that is not a number: always sent.
        $this->assertSame('', scheduler::condition_from_form('value', 'completion_pct', 'median', 'lt', '1'));
        $this->assertSame('', scheduler::condition_from_form('value', 'completion_pct', 'avg', 'like', '1'));
        $this->assertSame('', scheduler::condition_from_form('value', '', 'avg', 'lt', '1'));
        $this->assertSame('', scheduler::condition_from_form('value', 'completion_pct', 'avg', 'lt', 'ten'));
        $this->assertSame('', scheduler::condition_from_form('hack', 'completion_pct', 'avg', 'lt', '1'));
        $this->assertNull(scheduler::condition((object) ['sendcondition' => '{broken']));
        $this->assertNull(scheduler::condition((object) []));
    }

    /**
     * Each comparison against the average (50) of the test rows.
     *
     * @return array
     */
    public static function comparisons(): array {
        return [
            'gt met' => ['gt', 49, true], 'gt not met' => ['gt', 50, false],
            'ge met' => ['ge', 50, true], 'ge not met' => ['ge', 51, false],
            'lt met' => ['lt', 51, true], 'lt not met' => ['lt', 50, false],
            'le met' => ['le', 50, true], 'le not met' => ['le', 49, false],
            'eq met' => ['eq', 50, true], 'eq not met' => ['eq', 50.5, false],
            'ne met' => ['ne', 49, true], 'ne not met' => ['ne', 50, false],
        ];
    }

    /**
     * Each comparison operator gives the expected result.
     *
     * @dataProvider comparisons
     * @param string $op
     * @param float $threshold
     * @param bool $expected
     */
    public function test_each_comparison(string $op, float $threshold, bool $expected): void {
        $columns = [['key' => 'completion_pct', 'label' => 'Completion pct']];
        $rows = [['completion_pct' => 40], ['completion_pct' => 60]];
        $condition = ['type' => 'value', 'column' => 'completion_pct', 'agg' => 'avg', 'op' => $op, 'value' => $threshold];
        [$met, $value] = scheduler::condition_met($condition, $columns, $rows);
        $this->assertSame($expected, $met);
        $this->assertEqualsWithDelta(50.0, $value, 1e-9);
    }

    public function test_rows_condition_and_missing_column(): void {
        $columns = [['key' => 'n', 'label' => 'N']];
        $this->assertSame([true, null], scheduler::condition_met(null, $columns, []));
        $this->assertSame([false, 0.0], scheduler::condition_met(['type' => 'rows'], $columns, []));
        $this->assertSame([true, 2.0], scheduler::condition_met(['type' => 'rows'], $columns, [['n' => 1], ['n' => 2]]));
        // A column the view does not have (renamed in the SQL): not met.
        $this->assertSame([false, null], scheduler::condition_met(
            ['type' => 'value', 'column' => 'gone', 'agg' => 'sum', 'op' => 'gt', 'value' => 0], $columns, [['n' => 1]]));
        // A column without numbers: not met.
        $this->assertSame([false, null], scheduler::condition_met(
            ['type' => 'value', 'column' => 'n', 'agg' => 'avg', 'op' => 'gt', 'value' => 0], $columns, [['n' => 'x']]));
    }

    public function test_summary_is_readable(): void {
        $condition = ['type' => 'value', 'column' => 'completion_pct', 'agg' => 'avg', 'op' => 'lt', 'value' => 50.0];
        $this->assertSame('Average of Completion < 50',
            scheduler::condition_summary($condition, ['completion_pct' => 'Completion']));
        $this->assertSame('Average of completion_pct < 50', scheduler::condition_summary($condition));
        $this->assertSame('The view has rows', scheduler::condition_summary(['type' => 'rows']));
        $this->assertSame('', scheduler::condition_summary(null));
        $condition['value'] = 12.5;
        $condition['op'] = 'ge';
        $this->assertStringEndsWith('≥ 12.5', scheduler::condition_summary($condition));
    }

    public function test_an_alert_not_met_sends_nothing_and_is_logged_as_skipped(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $schedule = $this->schedule(scheduler::condition_from_form('value', 'completion_pct', 'avg', 'lt', '30'));
        $log = scheduler::send($schedule);
        $this->assertSame('skipped', $log->status);
        $this->assertSame(0, $log->recipients);
        $this->assertSame(2, $log->rowcount);
        $this->assertStringContainsString('value: 50', $log->error);
        $this->assertCount(0, $sink->get_messages());
        $sink->close();
        $this->assertSame(1, $DB->count_records(scheduler::LOG, ['scheduleid' => $schedule->id, 'status' => 'skipped']));
        // It still counts as a run: the next one moves on.
        $this->assertGreaterThan(0, (int) $DB->get_field(scheduler::TABLE, 'lastrun', ['id' => $schedule->id]));
    }

    public function test_an_alert_met_is_sent(): void {
        $sink = $this->redirectEmails();
        $log = scheduler::send($this->schedule(scheduler::condition_from_form('value', 'completion_pct', 'min', 'le', '40')));
        $this->assertSame('sent', $log->status, (string) $log->error);
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    public function test_rows_alert_follows_the_filters_of_the_view(): void {
        $sink = $this->redirectEmails();
        // A search that matches nothing: no rows, nothing sent.
        $log = scheduler::send($this->schedule('{"type":"rows"}', ['level' => 0, 'search' => 'nobody']));
        $this->assertSame('skipped', $log->status);
        $this->assertSame(0, $log->rowcount);
        // A search that matches one row: sent.
        $log = scheduler::send($this->schedule('{"type":"rows"}', ['level' => 0, 'search' => 'Beta']));
        $this->assertSame('sent', $log->status, (string) $log->error);
        $this->assertSame(1, $log->rowcount);
        $this->assertCount(1, $sink->get_messages());
        $sink->close();
    }

    public function test_an_alert_on_a_calculated_column(): void {
        $generator = $this->getDataGenerator()->get_plugin_generator('local_reportium');
        $generator->create_level($this->report->id, 0,
            "SELECT 1 AS id, 'Alpha' AS name, 4 AS done, 10 AS total UNION ALL SELECT 2, 'Beta', 6, 10",
            ['calculated' => [['key' => 'calc_rate', 'label' => 'Rate', 'formula' => 'pct([done], [total])']]]);
        $sink = $this->redirectEmails();
        // Average rate 50: "sum of the rate > 99" is met (40 + 60).
        $log = scheduler::send($this->schedule(scheduler::condition_from_form('value', 'calc_rate', 'sum', 'gt', '99')));
        $this->assertSame('sent', $log->status, (string) $log->error);
        $log = scheduler::send($this->schedule(scheduler::condition_from_form('value', 'calc_rate', 'max', 'gt', '60')));
        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('Maximum of Rate > 60', $log->error);
        $sink->close();
    }
}
