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

use context;
use html_writer;
use moodle_url;
use stdClass;

/**
 * Downloads of a report with Moodle's data formats (\core\dataformat): CSV, Excel, ODS, JSON, HTML, PDF...
 * The file holds what is on screen: the level, its search, filters and column order (all rows, not one page).
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exporter {

    /** Older format names stored on reports => data format. */
    const ALIASES = ['xls' => 'excel', 'xlsx' => 'excel'];

    /** Formats offered by new reports. */
    const DEFAULTS = ['csv', 'excel', 'ods', 'json', 'png'];

    /** @var string The chart as an image: not a data format, drawn by the page. */
    const PNG = 'png';

    /** Font Awesome icon of each format. */
    const ICONS = ['csv' => 'fa-file-csv', 'excel' => 'fa-file-excel', 'ods' => 'fa-table', 'json' => 'fa-file-code',
        'pdf' => 'fa-file-pdf', 'html' => 'fa-file-lines', 'print' => 'fa-print', 'png' => 'fa-file-image'];

    /** Short label of each format on its download button. */
    const SHORTNAMES = ['csv' => 'CSV', 'excel' => 'Excel', 'ods' => 'ODS', 'json' => 'JSON', 'pdf' => 'PDF',
        'html' => 'HTML'];

    /**
     * Data formats enabled on the site.
     *
     * @return string[] format => localised name
     */
    public static function available(): array {
        $formats = [];
        foreach (\core_plugin_manager::instance()->get_plugins_of_type('dataformat') as $plugin) {
            if ($plugin->is_enabled()) {
                $formats[$plugin->name] = get_string('dataformat', 'dataformat_' . $plugin->name);
            }
        }
        return $formats;
    }

    /**
     * Whether a report offers the chart as an image (PNG, drawn by the page).
     *
     * @param stdClass $report
     * @return bool
     */
    public static function offers_png(stdClass $report): bool {
        return in_array(self::PNG, array_map('trim', explode(',', (string) $report->export)), true);
    }

    /**
     * Formats a report offers (its own choice, among those available).
     *
     * @param stdClass $report
     * @return string[] format => name
     */
    public static function offered(stdClass $report): array {
        $available = self::available();
        $offered = [];
        foreach (explode(',', (string) $report->export) as $format) {
            $format = self::ALIASES[trim($format)] ?? trim($format);
            if (isset($available[$format])) {
                $offered[$format] = $available[$format];
            }
        }
        return $offered;
    }

    /**
     * "Download report" buttons under the report (and Print). The interactive view adds the on-screen state to them.
     *
     * @param stdClass $report
     * @param int $courseid
     * @param bool $print add the Print button
     * @return string HTML ('' when the report offers no format)
     */
    public static function links(stdClass $report, int $courseid, bool $print = false): string {
        $offered = self::offered($report);
        $links = $offered || self::offers_png($report)
            ? [html_writer::span(get_string('downloadreport', 'local_reportium'), 'reportium-downloads-label')] : [];
        foreach ($offered as $format => $name) {
            $url = new moodle_url('/local/reportium/viewreport.php',
                ['id' => $report->id, 'courseid' => $courseid, 'download' => 1, 'format' => $format]);
            $links[] = self::button($url, $format, $name);
        }
        // The chart as an image (drawn on the page; hidden in the table view, see levels.js), when the report offers it.
        if (self::offers_png($report)) {
            $label = get_string('downloadchartpng', 'local_reportium');
            $links[] = html_writer::link('#', self::icon('png') . html_writer::span('PNG'),
                ['class' => 'btn btn-sm btn-outline-secondary reportium-download', 'title' => $label,
                    'data-action' => 'download-png', 'aria-label' => $label]);
        }
        if ($print) {
            // Browser print (keeps the charts, which are canvases); the print stylesheet hides controls and Moodle chrome.
            $label = get_string('printreport', 'local_reportium');
            $links[] = html_writer::link('#', self::icon('print') . html_writer::span($label),
                ['class' => 'btn btn-sm btn-outline-secondary reportium-download', 'title' => $label,
                    'data-action' => 'print-report']);
        }
        if (!$links) {
            return '';
        }
        return html_writer::div(implode('', $links), 'reportium-downloads');
    }

    /**
     * Small download button of a format: Font Awesome file icon in the format colour and a short label (the full
     * format name is the tooltip).
     *
     * @param moodle_url $url
     * @param string $format data format
     * @param string $name full format name
     * @param bool $labelled show the short label next to the icon
     * @return string
     */
    public static function button(moodle_url $url, string $format, string $name, bool $labelled = true): string {
        $short = self::SHORTNAMES[$format] ?? strtoupper($format);
        return html_writer::link($url, self::icon($format) . ($labelled ? html_writer::span($short) : ''),
            ['class' => 'btn btn-sm btn-outline-secondary reportium-download reportium-download-' . $format,
                'title' => $name, 'aria-label' => $name]);
    }

    /**
     * Font Awesome icon of a data format (or "print").
     *
     * @param string $format
     * @return string
     */
    protected static function icon(string $format): string {
        return html_writer::tag('i', '', ['class' => 'fa ' . (self::ICONS[$format] ?? 'fa-file') . ' fa-fw',
            'aria-hidden' => 'true']);
    }

    /**
     * Send a level of the report as a file and stop.
     *
     * @param stdClass $report
     * @param context $context
     * @param int $userid
     * @param string $format data format
     * @param int $level
     * @param array $steps drill path [['v' => value, 'l' => label], ...]
     * @param string $search
     * @param array $filters
     * @param array $override chart settings applied on screen
     * @param string $columnsort "column:asc|desc"
     * @param int[] $range [start, end] of the period; empty = all time
     * @param array[] $kpis KPI cards on screen [['key' => column|_rows, 'agg' => ...]] (recalculated here)
     * @param bool $totals add the totals row (the calculations of the cards per column)
     * @return void
     */
    public static function download(stdClass $report, context $context, int $userid, string $format, int $level,
            array $steps, string $search, array $filters, array $override, string $columnsort, array $range = [],
            array $kpis = [], bool $totals = false): void {
        if (!isset(self::offered($report)[$format])) {
            throw new \moodle_exception('invalidformat', 'local_reportium');
        }
        [$filename, $columns, $rows] = self::prepare($report, $context, $userid, $level, $steps, $search, $filters,
            $override, $columnsort, $range);
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $formatter = self::row_formatter($columns);
        // The totals row and the KPI cards follow the rows, as lines of the same table.
        foreach (self::summary_lines($columns, $rows, self::clean_kpis($kpis, $columns), $totals) as $line) {
            $rows[] = [self::LINE_KEY => $line];
        }
        \core\dataformat::download_data($filename, $format, array_column($columns, 'label'), new \ArrayIterator($rows),
            static fn(array $row): array => isset($row[self::LINE_KEY]) ? $row[self::LINE_KEY] : $formatter($row));
        die;
    }

    /** @var string Key of a row that is a ready line of a file (totals, KPI cards). */
    const LINE_KEY = '__line';

    /**
     * KPI cards asked for by the page: known columns and calculations only, each once.
     *
     * @param array $kpis
     * @param array[] $columns
     * @return array[] [['key', 'agg']]
     */
    public static function clean_kpis(array $kpis, array $columns): array {
        $keys = array_column($columns, 'key');
        $clean = [];
        foreach ($kpis as $kpi) {
            $key = is_array($kpi) ? (string) ($kpi['key'] ?? '') : '';
            $agg = is_array($kpi) ? (string) ($kpi['agg'] ?? '') : '';
            if (in_array($agg, column_inspector::AGGREGATES, true) && ($key === '_rows' || in_array($key, $keys, true))) {
                $clean[$key === '_rows' ? '_rows' : "$key:$agg"] = ['key' => $key, 'agg' => $key === '_rows' ? 'count' : $agg];
            }
        }
        return array_values($clean);
    }

    /**
     * Lines after the rows of a file: the totals row (one value per column with a calculation), then a blank line and
     * the KPI cards (name, value, calculation).
     *
     * @param array[] $columns
     * @param array[] $rows
     * @param array[] $kpis clean_kpis()
     * @param bool $totals
     * @return array[] lines, one value per column
     */
    public static function summary_lines(array $columns, array $rows, array $kpis, bool $totals): array {
        if (!$kpis || !$rows) {
            return [];
        }
        $lines = [];
        $count = count($columns);
        $bykey = array_column($columns, null, 'key');
        if ($totals) {
            $line = [];
            foreach (array_values($columns) as $i => $column) {
                $spec = null;
                foreach ($kpis as $kpi) {
                    if ($kpi['key'] === $column['key']) {
                        $spec = $kpi;
                        break;
                    }
                }
                $value = $spec
                    ? self::aggregate_text(self::aggregate($rows, $column['key'], $spec['agg']), $column, $spec['agg'])
                    : '';
                $label = get_string('ui_totals', 'local_reportium');
                $line[] = $i === 0 ? ($value === '' ? $label : "$label: $value") : $value;
            }
            $lines[] = $line;
        }
        $lines[] = array_fill(0, $count, '');
        $lines[] = array_pad([get_string('ui_kpis', 'local_reportium')], $count, '');
        foreach ($kpis as $kpi) {
            $column = $bykey[$kpi['key']] ?? null;
            $value = self::aggregate($rows, $kpi['key'], $kpi['agg']);
            $card = [
                $column ? $column['label'] : get_string('ui_kpi_rows', 'local_reportium'),
                self::aggregate_text($value, $column, $kpi['agg']),
                $column ? self::aggregate_name($kpi['agg'], $column) : '',
            ];
            // As many values as columns (a narrow table: the calculation, then the value, are left out; JSON and the
            // spreadsheets need lines of the same width).
            if ($count < 3) {
                $card = array_slice($count === 1 ? [$card[0] . ': ' . $card[1]] : [$card[0], $card[1]], 0, $count);
            }
            $lines[] = array_pad($card, $count, '');
        }
        return $lines;
    }

    /**
     * One calculation over rows (the same as the KPI cards on screen).
     *
     * @param array[] $rows
     * @param string $key column, or _rows
     * @param string $agg sum | avg | min | max | count | distinct
     * @return float|null null when there is nothing to calculate
     */
    public static function aggregate(array $rows, string $key, string $agg): ?float {
        if ($key === '_rows') {
            return (float) count($rows);
        }
        $raw = array_values(array_filter(array_map(static fn($row) => $row[$key] ?? null, $rows),
            static fn($value) => $value !== null && (string) $value !== ''));
        if ($agg === 'count') {
            return (float) count($raw);
        }
        if ($agg === 'distinct') {
            return (float) count(array_unique(array_map('strval', $raw)));
        }
        $values = array_map('floatval', array_filter($raw, 'is_numeric'));
        if (!$values) {
            return null;
        }
        switch ($agg) {
            case 'min':
                return min($values);
            case 'max':
                return max($values);
            case 'avg':
                return array_sum($values) / count($values);
            default:
                return array_sum($values);
        }
    }

    /**
     * A calculated value as text: dates as dates, percentages with %, numbers with up to one decimal.
     *
     * @param float|null $value
     * @param array|null $column
     * @param string $agg
     * @return string
     */
    public static function aggregate_text(?float $value, ?array $column, string $agg): string {
        if ($value === null) {
            return '-';
        }
        if ($column && $column['type'] === column_inspector::TYPE_DATE && in_array($agg, ['min', 'max'], true)) {
            return userdate((int) $value, str_replace(['%y', ', '], ['%Y', ' '], get_string(
                !empty($column['withtime']) ? 'strftimedatetimeshort' : 'strftimedatefullshort', 'langconfig')));
        }
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
        return $text . ($column && ($column['format'] ?? '') === 'progress' && $agg !== 'count' && $agg !== 'distinct' ? '%' : '');
    }

    /**
     * Name of a calculation ("Average", "Latest"...).
     *
     * @param string $agg
     * @param array $column
     * @return string
     */
    public static function aggregate_name(string $agg, array $column): string {
        if ($column['type'] === column_inspector::TYPE_DATE && in_array($agg, ['min', 'max'], true)) {
            return get_string($agg === 'min' ? 'ui_kpi_earliest' : 'ui_kpi_latest', 'local_reportium');
        }
        return get_string('ui_kpi_' . $agg, 'local_reportium');
    }

    /** @var int Largest chart image accepted from the page (bytes, after decoding). */
    const MAX_CHART_BYTES = 8 * 1024 * 1024;

    /**
     * Send a level of the report as a PDF like its print: title, drill path and period, the KPI cards, the charts as
     * drawn on the page (PNG images sent by it), and the table of the rows on screen. Stops.
     *
     * @param stdClass $report
     * @param context $context
     * @param int $userid
     * @param int $level
     * @param array $steps
     * @param string $search
     * @param array $filters
     * @param array $override
     * @param string $columnsort
     * @param int[] $range
     * @param string[] $charts PNG data URLs (data:image/png;base64,...) of the charts on the page
     * @param array[] $kpis cards on the page: [['label' => ..., 'value' => ..., 'sub' => ...]]
     * @param array[] $kpispecs calculations of the cards (for the totals row)
     * @param bool $totals add the totals row
     * @return void
     */
    public static function download_pdf(stdClass $report, context $context, int $userid, int $level, array $steps,
            string $search, array $filters, array $override, string $columnsort, array $range, array $charts,
            array $kpis, array $kpispecs = [], bool $totals = false): void {
        [$filename, $columns, $rows, $mapping] = self::prepare($report, $context, $userid, $level, $steps, $search,
            $filters, $override, $columnsort, $range);
        // Charts as drawn on the page (one per pane, side by side when compared).
        $images = [];
        foreach (array_slice($charts, 0, 2) as $chart) {
            if (is_string($chart) && preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $chart, $m)) {
                $binary = base64_decode($m[1], true);
                if ($binary !== false && strlen($binary) <= self::MAX_CHART_BYTES && strncmp($binary, "\x89PNG", 4) === 0) {
                    $images[] = $binary;
                }
            }
        }
        $lines = $totals ? self::summary_lines($columns, $rows, self::clean_kpis($kpispecs, $columns), true) : [];
        $pdf = pdf_report::build([
            'title' => format_string($report->name, true, ['context' => $context]),
            'subtitle' => self::pdf_subtitle($steps, $range),
            'columns' => $columns,
            'rows' => $rows,
            'formats' => self::pdf_formats($columns, $mapping, $override),
            'kpis' => $kpis,
            'images' => $images,
            'totals' => $lines[0] ?? null,
        ]);
        $pdf->Output(clean_filename($filename) . '.pdf', 'D');
        die;
    }

    /**
     * A view of a report as a PDF file like its print, for a scheduled email: the KPI cards and the totals row of the
     * level, the chart drawn on the server and the table.
     *
     * @param stdClass $report
     * @param context $context
     * @param int $userid
     * @param array $view schedule view ([..., 'chart' => '' (as the report) | bar | line | pie | none])
     * @param int[] $range
     * @return array [string path, string file name, int rows]
     */
    public static function pdf_file(stdClass $report, context $context, int $userid, array $view, array $range = []): array {
        $steps = (array) ($view['steps'] ?? []);
        $override = (array) ($view['override'] ?? []);
        [$filename, $columns, $rows, $mapping] = self::prepare($report, $context, $userid, (int) ($view['level'] ?? 0),
            $steps, (string) ($view['search'] ?? ''), (array) ($view['filters'] ?? []), $override,
            (string) ($view['sort'] ?? ''), $range);
        $mapping = column_inspector::merge_mapping($mapping, $override, $columns);
        $chart = (string) ($view['chart'] ?? '');
        if ($chart === '') {
            // As the report: its chart type (none when it opens on the table).
            $chart = (string) ($mapping['defaultview'] ?? 'bar');
        }
        $specs = self::kpi_specs($report, $columns, $mapping);
        $totals = $specs && (isset($mapping['totalsrow']) ? !empty($mapping['totalsrow']) : !empty($report->totalsrow));
        $lines = $totals ? self::summary_lines($columns, $rows, $specs, true) : [];
        $pdf = pdf_report::build([
            'title' => format_string($report->name, true, ['context' => $context]),
            'subtitle' => self::pdf_subtitle($steps, $range),
            'columns' => $columns,
            'rows' => $rows,
            'formats' => self::pdf_formats($columns, $mapping, $override),
            'kpis' => self::kpi_cards($specs, $columns, $rows),
            'chart' => pdf_report::chart_spec($chart, $columns, $rows, $mapping),
            'totals' => $lines[0] ?? null,
        ]);
        $dir = make_request_directory();
        $path = $dir . '/' . clean_filename($filename) . '.pdf';
        $pdf->Output($path, 'F');
        return [$path, basename($path), count($rows)];
    }

    /**
     * KPI cards of a level as on screen: those chosen in its chart settings, else the report's defaults (the rows and the
     * first metrics).
     *
     * @param stdClass $report
     * @param array[] $columns
     * @param array $mapping
     * @return array[] [['key', 'agg']]
     */
    public static function kpi_specs(stdClass $report, array $columns, array $mapping): array {
        $kpis = $mapping['kpis'] ?? null;
        if ($kpis === false) {
            return [];
        }
        if (is_array($kpis)) {
            return self::clean_kpis($kpis, $columns);
        }
        if (empty($report->kpicards)) {
            return [];
        }
        $specs = !empty($report->kpirows) ? [['key' => '_rows', 'agg' => 'count']] : [];
        $metrics = (array) ($mapping['metrics'] ?? []);
        $count = 0;
        foreach ($columns as $column) {
            if ($column['type'] === column_inspector::TYPE_NUMBER && in_array($column['key'], $metrics, true) && $count++ < 4) {
                $progress = ($mapping['formats'][$column['key']] ?? $column['format'] ?? '') === 'progress';
                $avg = $progress || preg_match('/(pct|percent|avg|average|mean|rate|ratio|porcentaje|promedio|media)/i',
                    $column['key']);
                $specs[] = ['key' => $column['key'], 'agg' => $avg ? 'avg' : 'sum'];
            }
        }
        return $specs;
    }

    /**
     * KPI cards ready for the PDF: name, value and calculation of each.
     *
     * @param array[] $specs kpi_specs()
     * @param array[] $columns
     * @param array[] $rows
     * @return array[] [['label', 'value', 'sub']]
     */
    public static function kpi_cards(array $specs, array $columns, array $rows): array {
        $bykey = array_column($columns, null, 'key');
        $cards = [];
        foreach ($specs as $spec) {
            $column = $bykey[$spec['key']] ?? null;
            $cards[] = [
                'label' => $column ? $column['label'] : get_string('ui_kpi_rows', 'local_reportium'),
                'value' => self::aggregate_text(self::aggregate($rows, $spec['key'], $spec['agg']), $column, $spec['agg']),
                'sub' => $column ? self::aggregate_name($spec['agg'], $column) : '',
            ];
        }
        return $cards;
    }

    /**
     * Drill path and period under the title of a PDF.
     *
     * @param array $steps
     * @param int[] $range
     * @return string
     */
    protected static function pdf_subtitle(array $steps, array $range): string {
        $labels = array_filter(array_map(static fn($step) => clean_param((string) ($step['l'] ?? ''), PARAM_TEXT),
            array_values(array_filter($steps, 'is_array'))));
        $period = $range ? date_range::label((int) $range[0], (int) $range[1]) : '';
        return implode(' › ', $labels) . ($labels && $period !== '' ? ' · ' : '') . $period;
    }

    /**
     * Format of each column as on screen: chosen on screen, else saved for the level, else automatic.
     *
     * @param array[] $columns
     * @param array $mapping
     * @param array $override
     * @return string[] key => format
     */
    protected static function pdf_formats(array $columns, array $mapping, array $override): array {
        $formats = [];
        foreach ($columns as $column) {
            $formats[$column['key']] = (string) (($override['formats'] ?? [])[$column['key']]
                ?? ($mapping['formats'] ?? [])[$column['key']] ?? $column['format'] ?? 'text');
        }
        return $formats;
    }

    /**
     * Values of a row as written to files (see row_formatter()).
     *
     * @param array[] $columns
     * @return callable
     */
    public static function formatter(array $columns): callable {
        return self::row_formatter($columns);
    }

    /**
     * A table cell of the PDF as on screen: a progress bar in the colour of its value, a state label in its colours,
     * or the text.
     *
     * @param string $raw value of the row
     * @param string $text value as written to files (dates formatted)
     * @param string $format progress | state | text
     * @param string[] $colors progress colours (low, mid, high)
     * @param array[] $states instructions::states()
     * @return string HTML for TCPDF
     */
    public static function pdf_cell(string $raw, string $text, string $format, array $colors, array $states): string {
        if ($format === 'progress' && $raw !== '' && is_numeric($raw)) {
            $pct = max(0, min(100, (float) $raw));
            $fill = $pct <= 50 ? self::mix_color($colors['low'], $colors['mid'], $pct / 50)
                : self::mix_color($colors['mid'], $colors['high'], ($pct - 50) / 50);
            $done = (int) round($pct);
            $bar = $done <= 0 ? '<td width="100%" style="background-color:#e9ecef;"> </td>'
                : ($done >= 100 ? '<td width="100%" style="background-color:' . $fill . ';"> </td>'
                : '<td width="' . $done . '%" style="background-color:' . $fill . ';"> </td>'
                    . '<td width="' . (100 - $done) . '%" style="background-color:#e9ecef;"> </td>');
            $label = rtrim(rtrim(number_format($pct, 1, '.', ''), '0'), '.') . '%';
            return '<table cellpadding="0" cellspacing="0"><tr><td width="72%"><table cellpadding="1" cellspacing="0"><tr>'
                . $bar . '</tr></table></td><td width="28%" align="right">' . s($label) . '</td></tr></table>';
        }
        if ($format === 'state' && $raw !== '') {
            $key = instructions::normalise($raw);
            foreach ($states as $state) {
                if (in_array($key, $state['keys'], true)) {
                    return '<span style="background-color:' . $state['color'] . ';color:' . $state['textcolor'] . ';"> '
                        . s($state['label']) . ' </span>';
                }
            }
        }
        return s($text);
    }

    /**
     * A colour between two others (like the progress bars on screen).
     *
     * @param string $from #rrggbb
     * @param string $to #rrggbb
     * @param float $share 0 = from, 1 = to
     * @return string #rrggbb
     */
    protected static function mix_color(string $from, string $to, float $share): string {
        $share = max(0, min(1, $share));
        $parts = static fn(string $hex): array => array_map('hexdec', str_split(substr(ltrim($hex, '#') . '000000', 0, 6), 2));
        [$a, $b] = [$parts($from), $parts($to)];
        $mixed = '';
        foreach ($a as $i => $value) {
            $mixed .= sprintf('%02x', (int) round($value + ($b[$i] - $value) * $share));
        }
        return '#' . $mixed;
    }

    /**
     * Columns and rows of a view of a report, as its file would hold them (alerts of scheduled emails).
     *
     * @param stdClass $report
     * @param context $context
     * @param int $userid
     * @param array $view ['level', 'steps', 'search', 'filters', 'override', 'sort']
     * @param int[] $range
     * @return array [array[] columns, array[] rows]
     */
    public static function view_rows(stdClass $report, context $context, int $userid, array $view, array $range = []): array {
        [, $columns, $rows] = self::prepare($report, $context, $userid, (int) ($view['level'] ?? 0),
            (array) ($view['steps'] ?? []), (string) ($view['search'] ?? ''), (array) ($view['filters'] ?? []),
            (array) ($view['override'] ?? []), (string) ($view['sort'] ?? ''), $range);
        return [$columns, $rows];
    }

    /**
     * Write a level of the report to a file (scheduled email delivery).
     *
     * @param stdClass $report
     * @param context $context
     * @param int $userid whose access the report runs with
     * @param string $format data format (any available one)
     * @param array $view ['level' => int, 'steps' => [...], 'search' => '', 'filters' => [], 'override' => [], 'sort' => '']
     * @param int[] $range [start, end] of the period; empty = all time
     * @return array [string path of the file, string file name with extension, int rows]
     */
    public static function write_file(stdClass $report, context $context, int $userid, string $format, array $view,
            array $range = []): array {
        if (!isset(self::available()[$format])) {
            throw new \moodle_exception('invalidformat', 'local_reportium');
        }
        if ($format === 'pdf') {
            return self::pdf_file($report, $context, $userid, $view, $range);
        }
        [$filename, $columns, $rows] = self::prepare($report, $context, $userid, (int) ($view['level'] ?? 0),
            (array) ($view['steps'] ?? []), (string) ($view['search'] ?? ''), (array) ($view['filters'] ?? []),
            (array) ($view['override'] ?? []), (string) ($view['sort'] ?? ''), $range);
        $path = \core\dataformat::write_data($filename, $format, array_column($columns, 'label'), new \ArrayIterator($rows),
            self::row_formatter($columns));
        return [$path, basename($path), count($rows)];
    }

    /**
     * What a file of a level holds: its name, the columns (not ids) and the rows on screen.
     *
     * @param stdClass $report
     * @param context $context
     * @param int $userid
     * @param int $level
     * @param array $steps
     * @param string $search
     * @param array $filters
     * @param array $override
     * @param string $columnsort
     * @param int[] $range
     * @return array [string file name without extension, array[] columns, array[] rows, array mapping of the level]
     */
    protected static function prepare(stdClass $report, context $context, int $userid, int $level, array $steps,
            string $search, array $filters, array $override, string $columnsort, array $range): array {
        $steps = array_values(array_filter($steps, 'is_array'));
        $path = array_map(static function(array $step): string {
            return (string) ($step['v'] ?? '');
        }, $steps);
        $payload = level_service::get_level_payload($report, $level, $path, $context, $userid, $range);
        if (isset($override['calculated'])) {
            // Calculated columns changed on screen and not saved yet.
            [$payload['columns'], $payload['rows']] = formula::apply($payload['columns'], $payload['rows'],
                $override['calculated']);
        }
        $rows = level_service::visible_rows($payload, $search, $filters, $override, $columnsort);

        $columns = array_values(array_filter($payload['columns'], static function(array $column): bool {
            return $column['type'] !== column_inspector::TYPE_ID;
        }));
        $columns = self::user_order($columns, (int) $report->id, $level, $userid);
        $labels = array_map(static function(array $step): string {
            return clean_param((string) ($step['l'] ?? ''), PARAM_TEXT);
        }, $steps);
        $filename = clean_filename(implode(' - ', array_filter(array_merge([format_string($report->name)], $labels))));
        return [$filename, $columns, $rows, $payload['mapping'] ?? []];
    }

    /** @var string Preference with the column order of a report: prefix + report id, JSON {level: [keys]}. */
    const ORDER_PREFERENCE = 'local_reportium_columns_';

    /**
     * Columns in the order the user dragged them to on screen (the others keep their place, after them).
     *
     * @param array[] $columns
     * @param int $reportid
     * @param int $level
     * @param int $userid
     * @return array[]
     */
    public static function user_order(array $columns, int $reportid, int $level, int $userid): array {
        $saved = json_decode((string) get_user_preferences(self::ORDER_PREFERENCE . $reportid, '{}', $userid), true);
        $order = is_array($saved) && isset($saved[(string) $level]) && is_array($saved[(string) $level])
            ? array_values(array_map('strval', $saved[(string) $level])) : [];
        if (!$order) {
            return $columns;
        }
        $position = array_flip($order);
        $indexed = [];
        foreach (array_values($columns) as $i => $column) {
            $indexed[] = [$position[$column['key']] ?? count($order) + $i, $i, $column];
        }
        usort($indexed, static fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_column($indexed, 2);
    }

    /**
     * Values of a row as written to a file: dates in the format shown on screen.
     *
     * @param array[] $columns
     * @return callable
     */
    protected static function row_formatter(array $columns): callable {
        return static function(array $row) use ($columns): array {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column['key']] ?? '';
                if ($column['type'] === column_inspector::TYPE_DATE && (int) $value > 0) {
                    // Same format as on screen: the user's language, with the full year.
                    $value = userdate((int) $value, str_replace(['%y', ', '], ['%Y', ' '], get_string(
                        !empty($column['withtime']) ? 'strftimedatetimeshort' : 'strftimedatefullshort', 'langconfig')));
                }
                $line[] = (string) $value;
            }
            return $line;
        };
    }
}
