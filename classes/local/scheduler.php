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

use DateTimeImmutable;
use DateTimeZone;
use moodle_url;
use stdClass;

/**
 * Scheduled email delivery of reports: when each schedule runs, who receives it, and the delivery itself.
 *
 * A schedule sends one view of a report (level, drill path, search, filters, sort) as a file, with the data its
 * creator can see, for the period chosen relative to the sending time (e.g. "last month" on the 1st of each month).
 * Every delivery is logged (local_reportium_sendlog).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scheduler {

    /** @var string Schedules. */
    const TABLE = 'local_reportium_schedule';

    /** @var string Delivery log. */
    const LOG = 'local_reportium_sendlog';

    /** @var string[] Frequencies. */
    const FREQUENCIES = ['daily', 'weekly', 'monthly', 'quarterly'];

    /** @var string[] Comparisons of a value condition. */
    const OPERATORS = ['gt' => '>', 'ge' => '≥', 'lt' => '<', 'le' => '≤', 'eq' => '=', 'ne' => '≠'];

    /**
     * Condition of a schedule, cleaned: null = always sent.
     *
     * @param stdClass $schedule
     * @return array|null ['type' => 'rows'] or ['type' => 'value', 'column', 'agg', 'op', 'value']
     */
    public static function condition(stdClass $schedule): ?array {
        $data = json_decode((string) ($schedule->sendcondition ?? ''), true);
        if (!is_array($data) || !in_array($data['type'] ?? '', ['rows', 'value'], true)) {
            return null;
        }
        if ($data['type'] === 'rows') {
            return ['type' => 'rows'];
        }
        $agg = (string) ($data['agg'] ?? '');
        $op = (string) ($data['op'] ?? '');
        if (!in_array($agg, column_inspector::AGGREGATES, true) || !isset(self::OPERATORS[$op])
                || !is_numeric($data['value'] ?? null) || trim((string) ($data['column'] ?? '')) === '') {
            return null;
        }
        $condition = ['type' => 'value', 'column' => (string) $data['column'], 'agg' => $agg, 'op' => $op,
            'value' => (float) $data['value']];
        // Name of the column when it was chosen (shown in the list of schedules).
        $label = trim(clean_param((string) ($data['label'] ?? ''), PARAM_TEXT));
        if ($label !== '') {
            $condition['label'] = \core_text::substr($label, 0, 100);
        }
        return $condition;
    }

    /**
     * Whether the rows of a view meet a condition. A value condition on a column the view does not have is not met.
     *
     * @param array|null $condition condition()
     * @param array[] $columns
     * @param array[] $rows
     * @return array [bool met, float|null value calculated (value conditions)]
     */
    public static function condition_met(?array $condition, array $columns, array $rows): array {
        if ($condition === null) {
            return [true, null];
        }
        if ($condition['type'] === 'rows') {
            return [count($rows) > 0, (float) count($rows)];
        }
        if (!in_array($condition['column'], array_column($columns, 'key'), true)) {
            return [false, null];
        }
        $value = exporter::aggregate($rows, $condition['column'], $condition['agg']);
        if ($value === null) {
            return [false, null];
        }
        $limit = $condition['value'];
        $met = [
            'gt' => $value > $limit, 'ge' => $value >= $limit, 'lt' => $value < $limit, 'le' => $value <= $limit,
            'eq' => abs($value - $limit) < 1e-9, 'ne' => abs($value - $limit) >= 1e-9,
        ][$condition['op']];
        return [$met, $value];
    }

    /**
     * Condition of the form fields as stored (JSON), '' = always.
     *
     * @param string $type '' | rows | value
     * @param string $column
     * @param string $agg
     * @param string $op
     * @param string $value
     * @param string $label name of the column
     * @return string
     */
    public static function condition_from_form(string $type, string $column, string $agg, string $op, string $value,
            string $label = ''): string {
        $data = ['type' => $type, 'column' => trim($column), 'agg' => $agg, 'op' => $op,
            'value' => str_replace(',', '.', trim($value)), 'label' => $label];
        $condition = self::condition((object) ['sendcondition' => json_encode($data)]);
        return $condition === null ? '' : json_encode($condition);
    }

    /**
     * Readable condition, e.g. "Average of Completion pct < 50".
     *
     * @param array|null $condition
     * @param array $labels column key => label (when known)
     * @return string '' = always
     */
    public static function condition_summary(?array $condition, array $labels = []): string {
        if ($condition === null) {
            return '';
        }
        if ($condition['type'] === 'rows') {
            return get_string('schedule_cond_rows', 'local_reportium');
        }
        $a = (object) ['agg' => get_string('ui_kpi_' . $condition['agg'], 'local_reportium'),
            'column' => $labels[$condition['column']] ?? $condition['label'] ?? $condition['column'],
            'op' => self::OPERATORS[$condition['op']],
            'value' => rtrim(rtrim(number_format($condition['value'], 2, '.', ''), '0'), '.')];
        return get_string('schedule_cond_value_desc', 'local_reportium', $a);
    }

    /** @var int Log entries kept per schedule. */
    const KEEP_LOG = 100;

    /**
     * Next sending time after a moment, at the hour of the schedule in the timezone of its creator.
     *
     * @param stdClass $schedule
     * @param int $after
     * @param DateTimeZone|null $timezone default the creator's timezone
     * @return int
     */
    public static function next_run(stdClass $schedule, int $after, ?DateTimeZone $timezone = null): int {
        global $DB;
        if ($timezone === null) {
            $owner = $DB->get_record('user', ['id' => $schedule->userid]);
            $timezone = \core_date::get_user_timezone_object($owner ?: null);
        }
        $hour = max(0, min(23, (int) $schedule->hour));
        $start = (new DateTimeImmutable('@' . $after))->setTimezone($timezone);
        $candidate = $start->setTime($hour, 0);
        switch ($schedule->frequency) {
            case 'daily':
                while ($candidate->getTimestamp() <= $after) {
                    $candidate = $candidate->modify('+1 day')->setTime($hour, 0);
                }
                break;
            case 'monthly':
            case 'quarterly':
                $months = $schedule->frequency === 'monthly' ? 1 : 3;
                $day = max(1, min(28, (int) $schedule->monthday));
                $month = (int) $start->format('n');
                if ($months === 3) {
                    // Quarters start in January, April, July and October.
                    $month = intdiv($month - 1, 3) * 3 + 1;
                }
                $candidate = $start->setDate((int) $start->format('Y'), $month, $day)->setTime($hour, 0);
                while ($candidate->getTimestamp() <= $after) {
                    $candidate = $candidate->modify("+$months months")->setTime($hour, 0);
                }
                break;
            default:
                // Weekly, on the day of the week of the schedule (0 = Sunday).
                $weekday = max(0, min(6, (int) $schedule->weekday));
                $candidate = $candidate->modify('+' . (($weekday - (int) $start->format('w') + 7) % 7) . ' days')
                    ->setTime($hour, 0);
                while ($candidate->getTimestamp() <= $after) {
                    $candidate = $candidate->modify('+7 days')->setTime($hour, 0);
                }
        }
        return $candidate->getTimestamp();
    }

    /**
     * Decoded recipients of a schedule.
     *
     * @param stdClass $schedule
     * @return array ['users' => int[], 'cohorts' => int[], 'roles' => int[], 'emails' => string[]]
     */
    public static function recipient_lists(stdClass $schedule): array {
        $data = json_decode((string) $schedule->recipients, true);
        $data = is_array($data) ? $data : [];
        $ints = static fn($list): array => array_values(array_unique(array_filter(array_map('intval', (array) $list))));
        return [
            'users' => $ints($data['users'] ?? []),
            'cohorts' => $ints($data['cohorts'] ?? []),
            'roles' => $ints($data['roles'] ?? []),
            'emails' => array_values(array_unique(array_filter(array_map('trim', (array) ($data['emails'] ?? [])),
                static fn(string $email): bool => validate_email($email)))),
        ];
    }

    /**
     * Who receives a schedule: its users, the members of its cohorts, the users with its roles where the report lives
     * (the course; at site or category level for a site report), and its external addresses. One email per address;
     * deleted, suspended and unconfirmed users are left out.
     *
     * @param stdClass $schedule
     * @param stdClass $report
     * @return stdClass[] users (external addresses as no-reply style users)
     */
    public static function recipients(stdClass $schedule, stdClass $report): array {
        global $DB;
        $lists = self::recipient_lists($schedule);
        $userids = $lists['users'];
        foreach ($lists['cohorts'] as $cohortid) {
            $userids = array_merge($userids, $DB->get_fieldset_select('cohort_members', 'userid', 'cohortid = ?', [$cohortid]));
        }
        if ($lists['roles']) {
            [$insql, $params] = $DB->get_in_or_equal($lists['roles']);
            if ((int) $report->courseid === (int) SITEID) {
                $sql = "SELECT DISTINCT ra.userid
                          FROM {role_assignments} ra
                          JOIN {context} ctx ON ctx.id = ra.contextid
                         WHERE ra.roleid $insql AND ctx.contextlevel IN (?, ?)";
                $params = array_merge($params, [CONTEXT_SYSTEM, CONTEXT_COURSECAT]);
            } else {
                $context = \context_course::instance($report->courseid);
                [$ctxsql, $ctxparams] = $DB->get_in_or_equal(array_merge([$context->id], $context->get_parent_context_ids()));
                $sql = "SELECT DISTINCT ra.userid FROM {role_assignments} ra WHERE ra.roleid $insql AND ra.contextid $ctxsql";
                $params = array_merge($params, $ctxparams);
            }
            $userids = array_merge($userids, $DB->get_fieldset_sql($sql, $params));
        }
        $recipients = [];
        $userids = array_values(array_unique(array_map('intval', $userids)));
        if ($userids) {
            [$insql, $params] = $DB->get_in_or_equal($userids);
            foreach ($DB->get_records_select('user', "id $insql AND deleted = 0 AND suspended = 0 AND confirmed = 1",
                    $params) as $user) {
                if (validate_email($user->email)) {
                    $recipients[\core_text::strtolower($user->email)] = $user;
                }
            }
        }
        foreach ($lists['emails'] as $email) {
            $key = \core_text::strtolower($email);
            if (!isset($recipients[$key])) {
                $external = clone(\core_user::get_noreply_user());
                $external->reportiumexternal = true;
                $external->email = $email;
                $external->firstname = $email;
                $external->lastname = '';
                $external->mailformat = 1;
                $external->maildisplay = 0;
                $recipients[$key] = $external;
            }
        }
        return array_values($recipients);
    }

    /**
     * Send a schedule now and log the delivery. The next run is not changed (see run_due()).
     *
     * @param stdClass $schedule
     * @return stdClass the log entry (status sent or error)
     */
    public static function send(stdClass $schedule): stdClass {
        global $CFG, $DB;
        $log = (object) ['scheduleid' => (int) $schedule->id, 'timesent' => time(), 'status' => 'sent', 'recipients' => 0,
            'rowcount' => 0, 'error' => null];
        try {
            $report = $DB->get_record('local_reportium', ['id' => $schedule->reportid], '*', MUST_EXIST);
            $owner = $DB->get_record('user', ['id' => $schedule->userid, 'deleted' => 0, 'suspended' => 0]);
            $context = report_roles::report_context($report);
            if (!$owner || !report_roles::can_view($report, (int) $owner->id, $context)
                    || !has_capability('local/reportium:schedule', $context, $owner)) {
                throw new \moodle_exception('scheduleownerlost', 'local_reportium');
            }
            $recipients = self::recipients($schedule, $report);
            if (!$recipients) {
                throw new \moodle_exception('schedulenorecipients', 'local_reportium');
            }
            $timezone = \core_date::get_user_timezone_object($owner);
            $range = date_range::resolve((string) $schedule->daterange, $log->timesent, 0, 0, $timezone);
            $view = json_decode((string) $schedule->viewstate, true);
            $view = is_array($view) ? $view : [];
            $period = (string) $schedule->daterange === '' ? [] : $range;
            // Alert: only sent when its condition is met.
            $condition = self::condition($schedule);
            if ($condition !== null) {
                [$columns, $viewrows] = exporter::view_rows($report, $context, (int) $owner->id, $view, $period);
                [$met, $value] = self::condition_met($condition, $columns, $viewrows);
                $log->rowcount = count($viewrows);
                if (!$met) {
                    $log->status = 'skipped';
                    $log->error = get_string('schedule_cond_notmet', 'local_reportium', (object) [
                        'condition' => self::condition_summary($condition, array_column($columns, 'label', 'key')),
                        'value' => $value === null ? '-' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.')]);
                    throw new \local_reportium\local\schedule_skipped();
                }
            }
            [$path, $filename, $rows] = exporter::write_file($report, $context, (int) $owner->id, (string) $schedule->format,
                $view, $period);
            $log->rowcount = $rows;

            $from = \core_user::get_noreply_user();
            foreach ($recipients as $recipient) {
                // Each recipient gets the message with their own name (placeholders).
                [$subject, $text, $html] = self::message($schedule, $report, $range, $rows, $recipient);
                // A site with email switched off (noemailever) sends nothing; core reports it as sent, so do we
                // (without the debugging notice of email_to_user()).
                if (!empty($CFG->noemailever) || email_to_user($recipient, $from, $subject, $text, $html, $path, $filename)) {
                    $log->recipients++;
                }
            }
            if (!$log->recipients) {
                throw new \moodle_exception('schedulenotsent', 'local_reportium');
            }
        } catch (schedule_skipped $e) {
            // Condition not met: logged as skipped, nothing sent.
            $log->status = 'skipped';
        } catch (\Throwable $e) {
            $log->status = 'error';
            $log->error = $e instanceof \moodle_exception ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage();
        }
        $log->id = $DB->insert_record(self::LOG, $log);
        $DB->set_field(self::TABLE, 'lastrun', $log->timesent, ['id' => $schedule->id]);
        // Only the newest deliveries are kept.
        $old = $DB->get_fieldset_sql('SELECT id FROM {' . self::LOG . '} WHERE scheduleid = ? ORDER BY timesent DESC, id DESC',
            [$schedule->id]);
        if (count($old) > self::KEEP_LOG) {
            [$insql, $params] = $DB->get_in_or_equal(array_slice($old, self::KEEP_LOG));
            $DB->delete_records_select(self::LOG, "id $insql", $params);
        }
        return $log;
    }

    /** @var string[] Placeholders of the subject and the message of a schedule. */
    const PLACEHOLDERS = ['firstname', 'lastname', 'fullname', 'username', 'email', 'reportname', 'period', 'rows', 'date',
        'link', 'linkchart', 'sitename'];

    /** @var string[] Charts of a scheduled PDF: '' = as the report, none = the table only. */
    const PDF_CHARTS = ['', 'bar', 'line', 'pie', 'none'];

    /**
     * Values of the placeholders for a recipient (%firstname%, %reportname%, %linkchart%...).
     *
     * @param stdClass $schedule
     * @param stdClass $report
     * @param int[] $range
     * @param int $rows
     * @param stdClass|null $recipient
     * @return string[] "%name%" => value
     */
    public static function placeholders(stdClass $schedule, stdClass $report, array $range, int $rows,
            ?stdClass $recipient = null): array {
        global $SITE;
        $view = json_decode((string) $schedule->viewstate, true);
        $view = is_array($view) ? $view : [];
        $steps = array_map(static fn($step) => ['v' => (string) ($step['v'] ?? ''), 'l' => (string) ($step['l'] ?? '')],
            array_values(array_filter((array) ($view['steps'] ?? []), 'is_array')));
        $params = ['id' => $report->id];
        if ($steps) {
            $params['rpath'] = json_encode($steps);
        }
        if (in_array($view['chart'] ?? '', ['bar', 'line', 'pie'], true)) {
            $params['rview'] = $view['chart'];
        }
        if ((string) $schedule->daterange !== '') {
            $params['rrange'] = (string) $schedule->daterange;
        }
        $isuser = $recipient && !empty($recipient->id) && $recipient->id > 0 && empty($recipient->reportiumexternal);
        $values = [
            'firstname' => $isuser ? (string) $recipient->firstname : '',
            'lastname' => $isuser ? (string) $recipient->lastname : '',
            'fullname' => $isuser ? fullname($recipient) : '',
            'username' => $isuser ? (string) $recipient->username : '',
            'email' => $recipient ? (string) $recipient->email : '',
            'reportname' => format_string($report->name, true, ['context' => report_roles::report_context($report)]),
            'period' => (string) $schedule->daterange === '' ? '' : date_range::label($range[0], $range[1]),
            'rows' => (string) $rows,
            'date' => userdate(time(), get_string('strftimedatefullshort', 'langconfig')),
            'link' => (new moodle_url('/local/reportium/viewreport.php', ['id' => $report->id]))->out(false),
            'linkchart' => (new moodle_url('/local/reportium/viewreport.php', $params))->out(false),
            'sitename' => format_string($SITE->fullname),
        ];
        // An external address has no name: "Hello ," reads badly, so its first name is the address.
        if (!$isuser && $recipient) {
            $values['firstname'] = $values['fullname'] = (string) $recipient->email;
        }
        $out = [];
        foreach ($values as $name => $value) {
            $out['%' . $name . '%'] = $value;
        }
        return $out;
    }

    /**
     * Subject and body of a delivery for a recipient: the schedule's own (with its placeholders replaced), else the default.
     *
     * @param stdClass $schedule
     * @param stdClass $report
     * @param int[] $range
     * @param int $rows
     * @param stdClass|null $recipient
     * @return string[] [subject, text, html]
     */
    public static function message(stdClass $schedule, stdClass $report, array $range, int $rows,
            ?stdClass $recipient = null): array {
        $values = self::placeholders($schedule, $report, $range, $rows, $recipient);
        $fill = static fn(string $text) => strtr($text, $values);
        $a = (object) ['report' => $values['%reportname%'], 'period' => $values['%period%'], 'rows' => $rows,
            'url' => $values['%link%']];
        $subject = trim((string) $schedule->subject) !== '' ? $fill(trim((string) $schedule->subject))
            : get_string($a->period !== '' ? 'schedule_subject_period' : 'schedule_subject', 'local_reportium', $a);
        $template = trim((string) $schedule->message);
        if ($template !== '') {
            $text = $fill($template);
            // Plain text as HTML: escaped, line breaks kept, addresses as links.
            $html = nl2br(preg_replace_callback('~https?://[^\s<]+~', static fn($m) => \html_writer::link(
                html_entity_decode($m[0]), $m[0]), s($text)));
            return [$subject, $text, $html];
        }
        $text = get_string('schedule_body', 'local_reportium', $a)
            . ($a->period !== '' ? "\n" . get_string('schedule_body_period', 'local_reportium', $a) : '')
            . "\n\n" . get_string('schedule_openreport', 'local_reportium') . ': ' . $values['%linkchart%'];
        $html = \html_writer::tag('p', s(get_string('schedule_body', 'local_reportium', $a)))
            . ($a->period !== '' ? \html_writer::tag('p', s(get_string('schedule_body_period', 'local_reportium', $a))) : '')
            . \html_writer::tag('p', \html_writer::link($values['%linkchart%'],
                get_string('schedule_openreport', 'local_reportium')));
        return [$subject, $text, $html];
    }

    /**
     * Send every enabled schedule that is due, and set its next run (scheduled task).
     *
     * @param int $now
     * @return int deliveries attempted
     */
    public static function run_due(int $now = 0): int {
        global $DB;
        $now = $now ?: time();
        $count = 0;
        $due = $DB->get_records_select(self::TABLE, 'enabled = 1 AND nextrun > 0 AND nextrun <= ?', [$now], 'nextrun ASC');
        foreach ($due as $schedule) {
            // The next run is set first: a failing delivery is not repeated on every run of the task.
            $DB->set_field(self::TABLE, 'nextrun', self::next_run($schedule, $now), ['id' => $schedule->id]);
            $log = self::send($schedule);
            mtrace("  Reportium schedule {$schedule->id}: {$log->status}, {$log->recipients} recipient(s), {$log->rowcount} row(s)"
                . ($log->error ? " - {$log->error}" : ''));
            $count++;
        }
        return $count;
    }

    /**
     * Save a schedule (insert or update) and compute its next run.
     *
     * @param stdClass $schedule
     * @return int id
     */
    public static function save(stdClass $schedule): int {
        global $DB;
        $now = time();
        $schedule->timemodified = $now;
        $schedule->nextrun = !empty($schedule->enabled) ? self::next_run($schedule, $now) : 0;
        if (!empty($schedule->id)) {
            $DB->update_record(self::TABLE, $schedule);
            return (int) $schedule->id;
        }
        $schedule->timecreated = $now;
        $schedule->lastrun = 0;
        return (int) $DB->insert_record(self::TABLE, $schedule);
    }

    /**
     * Delete a schedule and its log.
     *
     * @param int $scheduleid
     * @return void
     */
    public static function delete(int $scheduleid): void {
        global $DB;
        $DB->delete_records(self::LOG, ['scheduleid' => $scheduleid]);
        $DB->delete_records(self::TABLE, ['id' => $scheduleid]);
    }

    /**
     * Delete the schedules of a report (when it is deleted).
     *
     * @param int $reportid
     * @return void
     */
    public static function delete_report(int $reportid): void {
        global $DB;
        foreach ($DB->get_fieldset_select(self::TABLE, 'id', 'reportid = ?', [$reportid]) as $id) {
            self::delete((int) $id);
        }
    }

    /**
     * Whether the current user may manage a schedule: its creator, or those who manage the report's reports.
     *
     * @param stdClass $schedule
     * @param stdClass $report
     * @return bool
     */
    public static function can_manage(stdClass $schedule, stdClass $report): bool {
        global $USER;
        $context = report_roles::report_context($report);
        return has_capability('local/reportium:manage', $context)
            || ((int) $schedule->userid === (int) $USER->id && has_capability('local/reportium:schedule', $context));
    }
    /**
     * Readable view of a schedule: level and drill path, e.g. "Level 2: Beta (with search and filters)".
     *
     * @param array $view
     * @return string escaped text
     */
    public static function view_summary(array $view): string {
        $level = (int) ($view['level'] ?? 0);
        $labels = array_filter(array_map(static fn($step) => is_array($step) ? trim((string) ($step['l'] ?? '')) : '',
            (array) ($view['steps'] ?? [])));
        $text = get_string('ui_sqltitle', 'local_reportium', $level + 1);
        if ($labels) {
            $text .= ': ' . implode(' › ', $labels);
        }
        if (trim((string) ($view['search'] ?? '')) !== '' || !empty($view['filters'])) {
            $text .= ' (' . get_string('schedule_withfilters', 'local_reportium') . ')';
        }
        return s($text);
    }

    /**
     * Names of the levels of a report for the level list: "Level 2: Courses in …".
     *
     * @param int $reportid
     * @return string[] level => name
     */
    public static function level_names(int $reportid): array {
        $names = [];
        foreach (level_service::get_levels($reportid) as $level) {
            $name = get_string('ui_sqltitle', 'local_reportium', $level['level'] + 1);
            $label = trim(str_replace('{parent}', get_string('schedule_parentrow', 'local_reportium', $level['level']),
                (string) $level['label']));
            // A label that only repeats the name ("Level 2") is not shown twice.
            $names[$level['level']] = $label !== '' && \core_text::strtolower($label) !== \core_text::strtolower($name)
                ? $name . ': ' . $label : $name;
        }
        return $names ?: [0 => get_string('ui_sqltitle', 'local_reportium', 1)];
    }

    /**
     * Labels of the levels as written (with {parent}), for the form to show the row chosen above in them.
     *
     * @param int $reportid
     * @return string[] level => label
     */
    public static function level_labels(int $reportid): array {
        $labels = [];
        foreach (level_service::get_levels($reportid) as $level) {
            $labels[$level['level']] = format_string((string) $level['label']);
        }
        return $labels;
    }

    /**
     * The view a schedule sends, from the form: the chosen level and the rows chosen in the levels before it.
     * Search, filters and sort of a view saved before are kept while the level and its rows stay the same.
     *
     * @param int $level
     * @param string $viewstate JSON written by schedule_form.js
     * @return array
     */
    public static function view_from_form(int $level, string $viewstate): array {
        $view = json_decode($viewstate, true);
        $view = is_array($view) ? $view : [];
        $steps = array_values(array_filter((array) ($view['steps'] ?? []), static fn($step) => is_array($step)));
        $steps = array_map(static fn(array $step) => ['v' => (string) ($step['v'] ?? ''),
            'l' => clean_param((string) ($step['l'] ?? ''), PARAM_TEXT)], array_slice($steps, 0, max(0, $level)));
        $same = (int) ($view['level'] ?? -1) === $level && empty($view['changed']);
        return [
            'level' => max(0, $level),
            'steps' => $steps,
            'search' => $same ? (string) ($view['search'] ?? '') : '',
            'filters' => $same ? (array) ($view['filters'] ?? []) : [],
            'override' => $same ? (array) ($view['override'] ?? []) : [],
            'sort' => $same ? (string) ($view['sort'] ?? '') : '',
        ];
    }
}
