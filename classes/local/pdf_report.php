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
 * A level of a report as a PDF like its print: title, drill path and period, KPI cards, the chart and the table (progress
 * bars and state labels in their colours, totals row).
 *
 * The chart comes either as the PNG drawn by the page (downloads) or is drawn here with the PDF primitives (scheduled
 * emails, where there is no browser): bars, lines or a pie with the colours of the charts on screen.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class pdf_report {

    /** @var string[] Colours of the series (those of the charts on screen). */
    const PALETTE = ['#5470c6', '#91cc75', '#fac858', '#ee6666', '#73c0de', '#3ba272', '#fc8452', '#9a60b4', '#ea7ccc'];

    /** @var string[] Chart types drawn here. */
    const CHARTS = ['bar', 'line', 'pie'];

    /** @var int Slices of a pie (the rest go into "Other"). */
    const MAX_SLICES = 10;

    /**
     * Build the PDF.
     *
     * @param array $doc ['title', 'subtitle', 'columns', 'rows', 'formats' (key => format), 'kpis' ([label, value, sub]),
     *     'images' (PNG binaries), 'chart' (chart_spec() or null), 'totals' (line of totals or null)]
     * @return \pdf
     */
    public static function build(array $doc): \pdf {
        global $CFG;
        require_once($CFG->libdir . '/pdflib.php');
        \core_php_time_limit::raise();
        raise_memory_limit(MEMORY_EXTRA);
        $pdf = new \pdf('L', 'mm', 'A4', true, 'UTF-8');
        $pdf->SetTitle($doc['title']);
        $pdf->SetCreator(get_string('pluginname', 'local_reportium'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();
        $width = $pdf->getPageWidth() - 24;

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->MultiCell($width, 0, $doc['title'], 0, 'L');
        $pdf->SetFont('helvetica', '', 10);
        if (($doc['subtitle'] ?? '') !== '') {
            $pdf->MultiCell($width, 0, $doc['subtitle'], 0, 'L');
        }
        $pdf->Ln(3);

        // KPI cards, as a row of boxes.
        $kpis = array_slice(array_values(array_filter($doc['kpis'] ?? [], 'is_array')), 0, 12);
        if ($kpis) {
            $cells = '';
            foreach ($kpis as $kpi) {
                $cells .= '<td style="border:1px solid #d0d7de;background-color:#f6f8fa;">'
                    . '<span style="font-size:8pt;color:#6a737b;">' . s(clean_param((string) ($kpi['label'] ?? ''), PARAM_TEXT))
                    . '</span><br><span style="font-size:14pt;font-weight:bold;">'
                    . s(clean_param((string) ($kpi['value'] ?? ''), PARAM_TEXT)) . '</span><br>'
                    . '<span style="font-size:7pt;color:#6a737b;">' . s(clean_param((string) ($kpi['sub'] ?? ''), PARAM_TEXT))
                    . '</span></td>';
            }
            $pdf->writeHTML('<table cellpadding="4" cellspacing="3"><tr>' . $cells . '</tr></table>', true, false, true);
            $pdf->Ln(2);
        }

        // The chart: images from the page, or drawn here.
        $images = $doc['images'] ?? [];
        if ($images) {
            $gap = 4;
            $each = ($width - $gap * (count($images) - 1)) / count($images);
            $top = $pdf->GetY();
            $tallest = 0;
            foreach ($images as $i => $binary) {
                $size = getimagesizefromstring($binary);
                $height = $size ? $each * $size[1] / max(1, $size[0]) : $each * 0.45;
                $height = min($height, 110);
                $pdf->Image('@' . $binary, 12 + $i * ($each + $gap), $top, $each, $height, 'PNG', '', '', true, 150, '',
                    false, false, 0, true);
                $tallest = max($tallest, $height);
            }
            $pdf->SetY($top + $tallest + 4);
        } else if (!empty($doc['chart'])) {
            $height = 92;
            if ($pdf->GetY() + $height > $pdf->getPageHeight() - 14) {
                $pdf->AddPage();
            }
            $top = $pdf->GetY();
            self::draw_chart($pdf, 12, $top, $width, $height, $doc['chart']);
            $pdf->SetY($top + $height + 4);
        }

        // The table.
        $columns = $doc['columns'];
        $format = exporter::formatter($columns);
        $colors = instructions::progress_colors();
        $states = instructions::states();
        $head = '';
        foreach ($columns as $column) {
            $head .= '<th style="font-weight:bold;background-color:#e9ecef;border-bottom:1px solid #adb5bd;">'
                . s($column['label']) . '</th>';
        }
        $body = '';
        foreach ($doc['rows'] as $i => $row) {
            $body .= '<tr' . ($i % 2 ? ' style="background-color:#f8f9fa;"' : '') . '>';
            foreach (array_values($format($row)) as $c => $value) {
                $key = $columns[$c]['key'];
                $body .= '<td>' . exporter::pdf_cell((string) ($row[$key] ?? ''), $value, $doc['formats'][$key] ?? 'text',
                    $colors, $states) . '</td>';
            }
            $body .= '</tr>';
        }
        if (!empty($doc['totals'])) {
            $body .= '<tr style="font-weight:bold;background-color:#e9ecef;">';
            foreach ($doc['totals'] as $value) {
                $body .= '<td>' . s($value) . '</td>';
            }
            $body .= '</tr>';
        }
        $pdf->SetFont('helvetica', '', count($columns) > 8 ? 7 : 8);
        $pdf->writeHTML('<table cellpadding="3"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>',
            true, false, true);
        return $pdf;
    }

    /**
     * What the chart of a level shows, as on screen: the rows of the first page, labelled by the label column, one series
     * per metric (a pie: the first metric).
     *
     * @param string $type bar | line | pie
     * @param array[] $columns
     * @param array[] $rows rows on screen, in their order
     * @param array $mapping effective mapping of the level
     * @return array|null ['type', 'labels', 'series' => [['name', 'values']], 'stacked'] or null (nothing to draw)
     */
    public static function chart_spec(string $type, array $columns, array $rows, array $mapping): ?array {
        if (!in_array($type, self::CHARTS, true) || !$rows) {
            return null;
        }
        $bykey = array_column($columns, null, 'key');
        // Each chart type may keep its own metrics and stacking.
        $own = (array) (($mapping['views'] ?? [])[$type] ?? []);
        $metrics = array_values(array_filter((array) ($own['metrics'] ?? $mapping['metrics'] ?? []),
            static fn($key) => isset($bykey[$key]) && $bykey[$key]['type'] === column_inspector::TYPE_NUMBER));
        if (!$metrics) {
            return null;
        }
        if ($type === 'pie') {
            $metrics = [$metrics[0]];
        }
        $rows = array_slice(array_values($rows), 0, max(1, (int) ($mapping['pagesize'] ?? 20)));
        [$main, $extra] = array_pad(explode('|', (string) ($mapping['labelcolumn'] ?? '')), 2, '');
        $labels = [];
        foreach ($rows as $row) {
            $value = $row[$main] ?? '';
            if (isset($bykey[$main]) && $bykey[$main]['type'] === column_inspector::TYPE_DATE && is_numeric($value)
                    && (int) $value > 0) {
                $value = userdate((int) $value, str_replace(['%y', ', '], ['%Y', ' '], get_string(
                    !empty($bykey[$main]['withtime']) ? 'strftimedatetimeshort' : 'strftimedatefullshort', 'langconfig')));
            }
            $label = trim(strip_tags((string) $value));
            if ($extra !== '' && trim((string) ($row[$extra] ?? '')) !== '') {
                $label .= ' (' . trim(strip_tags((string) $row[$extra])) . ')';
            }
            $labels[] = $label;
        }
        $series = [];
        foreach ($metrics as $key) {
            $series[] = ['name' => $bykey[$key]['label'],
                'values' => array_map(static fn($row) => is_numeric($row[$key] ?? null) ? (float) $row[$key] : 0.0, $rows)];
        }
        $stacked = $type === 'bar' && count($series) > 1 && !empty($own['stacked'] ?? $mapping['stacked'] ?? false);
        return ['type' => $type, 'labels' => $labels, 'series' => $series, 'stacked' => $stacked];
    }

    /**
     * Draw a chart in a box of the PDF.
     *
     * @param \pdf $pdf
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @param array $spec chart_spec()
     */
    public static function draw_chart(\pdf $pdf, float $x, float $y, float $w, float $h, array $spec): void {
        $pdf->SetFont('helvetica', '', 7);
        if ($spec['type'] === 'pie') {
            self::draw_pie($pdf, $x, $y, $w, $h, $spec);
            return;
        }
        $series = $spec['series'];
        $labels = $spec['labels'];
        $count = count($labels);
        // Legend on top.
        $legendy = $y + 1;
        $legendx = $x + $w / 2 - count($series) * 22;
        foreach ($series as $i => $serie) {
            $pdf->SetFillColorArray(self::rgb(self::PALETTE[$i % count(self::PALETTE)]));
            $pdf->Rect($legendx, $legendy + 0.6, 4, 2.4, 'F');
            $pdf->Text($legendx + 5, $legendy - 0.3, \core_text::substr($serie['name'], 0, 28));
            $legendx += 44;
        }
        // Plot area.
        $rotate = $count > 8;
        $longest = $labels ? max(array_map(static fn($l) => \core_text::strlen($l), $labels)) : 0;
        $bottom = $rotate ? min(34, 6 + min($longest, 30) * 1.1) : 8;
        $left = 14;
        $px = $x + $left;
        $py = $y + 8;
        $pw = $w - $left - 2;
        $ph = $h - 8 - $bottom;
        $max = 0;
        $min = 0;
        for ($i = 0; $i < $count; $i++) {
            $values = array_map(static fn($s) => $s['values'][$i], $series);
            $max = max($max, $spec['stacked'] ? array_sum(array_filter($values, static fn($v) => $v > 0)) : max($values));
            $min = min($min, min($values));
        }
        [$axismin, $axismax, $step] = self::axis($min, $max);
        $scale = $ph / max(1e-9, $axismax - $axismin);
        $ybase = $py + $ph - (0 - $axismin) * $scale;
        // Grid and values of the axis.
        $pdf->SetLineStyle(['width' => 0.1, 'color' => [224, 230, 241], 'dash' => 0]);
        for ($v = $axismin; $v <= $axismax + $step / 2; $v += $step) {
            $gy = $py + $ph - ($v - $axismin) * $scale;
            $pdf->Line($px, $gy, $px + $pw, $gy);
            $pdf->SetTextColor(110, 114, 121);
            $pdf->Text($x, $gy - 1.6, self::short_number($v));
        }
        $pdf->SetLineStyle(['width' => 0.2, 'color' => [110, 114, 121], 'dash' => 0]);
        $pdf->Line($px, $ybase, $px + $pw, $ybase);
        $slot = $pw / max(1, $count);
        // Labels of the rows.
        foreach ($labels as $i => $label) {
            $cx = $px + $slot * ($i + 0.5);
            $text = \core_text::substr($label, 0, 30);
            if ($rotate) {
                $pdf->StartTransform();
                $pdf->Rotate(45, $cx, $py + $ph + 2);
                $pdf->Text($cx - $pdf->GetStringWidth($text), $py + $ph + 1, $text);
                $pdf->StopTransform();
            } else {
                $text = \core_text::substr($label, 0, max(4, (int) ($slot / 1.4)));
                $pdf->Text($cx - $pdf->GetStringWidth($text) / 2, $py + $ph + 1.5, $text);
            }
        }
        $pdf->SetTextColor(0, 0, 0);
        if ($spec['type'] === 'bar') {
            $groups = $spec['stacked'] ? 1 : count($series);
            $barw = $slot * 0.7 / $groups;
            for ($i = 0; $i < $count; $i++) {
                $stack = 0.0;
                foreach ($series as $s => $serie) {
                    $value = $serie['values'][$i];
                    $pdf->SetFillColorArray(self::rgb(self::PALETTE[$s % count(self::PALETTE)]));
                    if ($spec['stacked']) {
                        $from = $ybase - $stack * $scale;
                        $stack += max(0, $value);
                        $bx = $px + $slot * $i + $slot * 0.15;
                        $pdf->Rect($bx, $ybase - $stack * $scale, $barw, $from - ($ybase - $stack * $scale), 'F');
                    } else {
                        $bx = $px + $slot * $i + $slot * 0.15 + $barw * $s;
                        $top = $ybase - $value * $scale;
                        $pdf->Rect($bx, min($top, $ybase), $barw, abs($ybase - $top), 'F');
                    }
                }
            }
        } else {
            foreach ($series as $s => $serie) {
                $color = self::rgb(self::PALETTE[$s % count(self::PALETTE)]);
                $pdf->SetLineStyle(['width' => 0.5, 'color' => $color, 'dash' => 0]);
                $pdf->SetFillColorArray([255, 255, 255]);
                $points = [];
                foreach ($serie['values'] as $i => $value) {
                    $points[] = [$px + $slot * ($i + 0.5), $ybase - $value * $scale];
                }
                for ($i = 1; $i < count($points); $i++) {
                    $pdf->Line($points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1]);
                }
                foreach ($points as [$cx, $cy]) {
                    $pdf->Circle($cx, $cy, 0.6, 0, 360, 'DF');
                }
            }
        }
        $pdf->SetLineStyle(['width' => 0.2, 'color' => [0, 0, 0], 'dash' => 0]);
    }

    /**
     * A pie of the first series, with a legend of the slices (name, value, share) on its right.
     *
     * @param \pdf $pdf
     * @param float $x
     * @param float $y
     * @param float $w
     * @param float $h
     * @param array $spec
     */
    protected static function draw_pie(\pdf $pdf, float $x, float $y, float $w, float $h, array $spec): void {
        $slices = [];
        foreach ($spec['labels'] as $i => $label) {
            $value = max(0, $spec['series'][0]['values'][$i]);
            if ($value > 0) {
                $slices[] = [$label, $value];
            }
        }
        if (!$slices) {
            return;
        }
        usort($slices, static fn($a, $b) => $b[1] <=> $a[1]);
        if (count($slices) > self::MAX_SLICES) {
            $rest = array_sum(array_column(array_slice($slices, self::MAX_SLICES - 1), 1));
            $slices = array_slice($slices, 0, self::MAX_SLICES - 1);
            $slices[] = [get_string('other'), $rest];
        }
        $total = array_sum(array_column($slices, 1));
        $radius = min($h / 2 - 4, $w / 4);
        $cx = $x + $w / 2 - $radius - 10;
        $cy = $y + $h / 2;
        $pdf->Text($x, $y, $spec['series'][0]['name']);
        $start = 90.0;
        $pdf->SetLineStyle(['width' => 0.3, 'color' => [255, 255, 255], 'dash' => 0]);
        foreach ($slices as $i => [, $value]) {
            $angle = 360 * $value / $total;
            $pdf->SetFillColorArray(self::rgb(self::PALETTE[$i % count(self::PALETTE)]));
            // Clockwise from the top, like the charts on screen.
            $pdf->PieSector($cx, $cy, $radius, $start - $angle, $start, 'FD', false, 0, 2);
            $start -= $angle;
        }
        $ly = $cy - count($slices) * 2.6;
        foreach ($slices as $i => [$label, $value]) {
            $pdf->SetFillColorArray(self::rgb(self::PALETTE[$i % count(self::PALETTE)]));
            $pdf->Rect($cx + $radius + 10, $ly + 0.6, 3, 3, 'F');
            $pdf->Text($cx + $radius + 15, $ly, \core_text::substr($label, 0, 45) . '  ' . self::short_number($value)
                . ' (' . round(100 * $value / $total, 1) . '%)');
            $ly += 5.2;
        }
        $pdf->SetLineStyle(['width' => 0.2, 'color' => [0, 0, 0], 'dash' => 0]);
    }

    /**
     * Round limits and step of a value axis (1, 2 or 5 times a power of ten).
     *
     * @param float $min
     * @param float $max
     * @return float[] [min, max, step]
     */
    public static function axis(float $min, float $max): array {
        if ($max <= $min) {
            $max = $min + 1;
        }
        $raw = ($max - $min) / 5;
        $power = pow(10, floor(log10($raw)));
        $step = $power * (($raw / $power) <= 1 ? 1 : (($raw / $power) <= 2 ? 2 : (($raw / $power) <= 5 ? 5 : 10)));
        return [floor($min / $step) * $step, ceil($max / $step) * $step, $step];
    }

    /**
     * A number for an axis or a legend: 1,234 · 12.5 · 1.2k · 3.4M.
     *
     * @param float $value
     * @return string
     */
    public static function short_number(float $value): string {
        // Trailing zeros of the decimals only ("12.50" → "12.5", "100" stays).
        $trim = static fn(string $s) => strpos($s, '.') === false ? $s : rtrim(rtrim($s, '0'), '.');
        $abs = abs($value);
        if ($abs >= 1e6) {
            return $trim(number_format($value / 1e6, 1, '.', '')) . 'M';
        }
        if ($abs >= 1e4) {
            return $trim(number_format($value / 1e3, 1, '.', '')) . 'k';
        }
        return $trim(number_format($value, $abs < 10 && floor($abs) != $abs ? 2 : 0, '.', ','));
    }

    /**
     * RGB of a #rrggbb colour.
     *
     * @param string $hex
     * @return int[]
     */
    protected static function rgb(string $hex): array {
        return array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    }
}
