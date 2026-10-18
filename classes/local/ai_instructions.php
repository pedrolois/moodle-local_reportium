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

use stdClass;

/**
 * Instructions file of a report (an AGENTS.md: what the report is, the SQL of its levels, the placeholders and the
 * rules) to paste into any AI chat outside Moodle. Values that look personal or secret are replaced by examples.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_instructions {
    /**
     * Instructions file (Markdown, like an AGENTS.md) to paste into any AI chat (ChatGPT, Codex, Claude...) when
     * the site has no AI provider: what to do and return, the SQL of every level, the placeholders and rules.
     *
     * Without a report (plugin settings): the same file for any report, with no levels.
     *
     * @param stdClass|null $report
     * @param int $editing level the user is editing in the SQL Inspector (-1 = none)
     * @return string
     */
    public static function prompt_file(?stdClass $report, int $editing = -1): string {
        // Markdown code fence.
        $fence = str_repeat(chr(96), 3);
        $levels = [];
        $replaced = 0;
        foreach ($report ? report_data_helper::get_saved_sqls((int) $report->id) : [] as $index => $saved) {
            [$sql, $count] = self::redact(trim($saved['sqlquery']));
            $replaced += $count;
            $levels[] = '### Level ' . ($index + 1) . ($index === $editing ? ' (the user is editing this level now)' : '') .
                "\n\n" . $fence . "sql\n" . $sql . "\n" . $fence;
        }
        $summary = '';
        if ($report) {
            [$summary, $count] = self::redact(trim(html_to_text(format_text(
                (string) $report->summary,
                (int) $report->summaryformat
            ), 0, false)));
            $replaced += $count;
        }
        $lines = [
            $report ? '# Reportium report: ' . format_string($report->name) : '# Reportium reports',
            '',
            '> This file holds the SQL of the report and the rules of Reportium, never data from the database.' .
                ($replaced ? ' Values in the SQL that looked personal or secret (emails, IP addresses, keys) were ' .
                    'replaced by examples (user@example.com, 192.0.2.1, REDACTED): keep them as they are in your answer, ' .
                    'the user puts the real values back.' : ''),
            '',
            'You are helping to write the SQL of a report of a Moodle site made with Reportium, ' .
                'a Moodle plugin of interactive SQL reports. Answer in the language the user writes in.',
            $summary !== '' ? "\nWhat the report is for: " . $summary : '',
            '',
            '## How a Reportium report works',
            '',
            '- A report is a chain of levels. Each level is one SQL query; its rows are shown as a bar, pie or line chart ' .
                'and as a table, with search, column filters and downloads.',
            '- Clicking a row (bar, slice) of a level opens the next level, which receives the value clicked as ' .
                '%%PREVIOUSVALUE%% (and the earlier choices as %%LEVELVALUE:n%%, n starting at 0).',
            '- Level 1 is the first one the user sees; Level 2 opens from a row of Level 1, and so on.',
            '',
            '## What to do',
            '',
            '- The user tells you what they need: a new level, a change to a level, a fix for an error, or an explanation.',
            '- Write or fix the SQL of the levels following the rules below.',
            '- When the request is short or vague (e.g. "write the next level"), do not only ask: propose the most useful ' .
                'next level for this report, with its complete SQL, and then offer two or three other ideas in one line ' .
                'each. Ask first only when you really cannot guess (which Moodle data, what should open when a row is ' .
                'clicked).',
            '',
            '## What to return',
            '',
            '- For every level you write or change: its number ("Level 2") and ONE complete query in a ' . $fence . 'sql block.',
            '- A short explanation of what the query shows and of the columns.',
            '- The user pastes each query into the SQL Inspector of that level in Reportium and clicks "Validate and save" ' .
                '(Reportium runs it once to check it).',
            '',
            '## Rules for the SQL',
            '',
            '- Read only: one SELECT (WITH ... SELECT is fine). No INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, GRANT, ' .
                'TRUNCATE or SET, and no semicolons.',
            '- Moodle table names in braces, without the prefix: {course}, {user}, {course_categories}... ' .
                '(prefix_course also works; never the real prefix such as mdl_).',
            '- Portable across MySQL, PostgreSQL and MSSQL. No LIMIT (Reportium pages the rows itself).',
            '- When the next level must receive an id, put the id column first (it is hidden in the table and passed ' .
                'to the next level); otherwise the next level receives the label of the row.',
            '- Give every column a clear alias: the alias is the column title, and some words in it give the column a ' .
                'format (see "Column keywords" below).',
            '',
            '## The levels of this report',
            '',
            $levels ? implode("\n\n", $levels) : ($report ? '(No SQL saved yet: start with Level 1.)'
                : '(The user pastes here the SQL of the levels their report already has, from the SQL Inspector, ' .
                    'or asks for Level 1.)'),
            '',
            '## Example of a request',
            '',
            $report ? self::example_request($report) : get_string('ai_help_example_first', 'local_reportium'),
            '',
            '## Reportium reference (placeholders, column keywords, states)',
            '',
            instructions::for_prompt(),
            '',
        ];
        return implode("\n", $lines);
    }

    /**
     * Replace values that look personal or secret by neutral examples, before text leaves Moodle for an AI chat
     * outside the site: emails, IPv4 addresses, and long keys or tokens.
     *
     * @param string $text
     * @return array [string text, int values replaced]
     */
    public static function redact(string $text): array {
        $count = 0;
        $patterns = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => 'user@example.com',
            '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '192.0.2.1',
            // Keys, tokens, hashes: long runs of letters and digits with both in them.
            '/\b(?=[A-Za-z0-9_\-]*\d)(?=[A-Za-z0-9_\-]*[A-Za-z])[A-Za-z0-9_\-]{32,}\b/' => 'REDACTED',
        ];
        foreach ($patterns as $pattern => $example) {
            $text = preg_replace($pattern, $example, $text, -1, $n);
            $count += $n;
        }
        return [$text, $count];
    }

    /**
     * Example request for an AI chat: the next level after the last saved one (or Level 1 of a report without SQL).
     *
     * @param stdClass $report
     * @return string
     */
    public static function example_request(stdClass $report): string {
        $count = count(report_data_helper::get_saved_sqls((int) $report->id));
        return $count ? get_string('ai_help_example', 'local_reportium', (object) ['next' => $count + 1, 'last' => $count])
            : get_string('ai_help_example_first', 'local_reportium');
    }

    /**
     * File name of the instructions file of a report.
     *
     * @param stdClass|null $report null = the file without a report
     * @return string
     */
    public static function prompt_filename(?stdClass $report): string {
        return $report ? 'AGENTS-' . clean_filename(str_replace(' ', '-', format_string($report->name))) . '.md'
            : 'AGENTS-Reportium.md';
    }
}
