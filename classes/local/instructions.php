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
 * The keywords that act on a report (placeholders, column formats, state codes), read from instructions.json
 * in the plugin root. The same file is sent to the browser and to the AI assistant.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class instructions {

    /** @var array|null parsed file */
    protected static $data = null;

    /**
     * The whole instructions file.
     *
     * @return array
     */
    public static function get(): array {
        global $CFG;
        if (self::$data === null) {
            $json = @file_get_contents($CFG->dirroot . '/local/reportium/instructions.json');
            $data = $json === false ? null : json_decode($json, true);
            self::$data = is_array($data) ? $data : ['placeholders' => [], 'columns' => [], 'formats' => [], 'states' => []];
        }
        return self::$data;
    }

    /**
     * Format names that can be chosen for a column.
     *
     * @return string[]
     */
    public static function format_names(): array {
        return array_values(array_unique(array_merge(['text'], array_column(self::get()['formats'] ?? [], 'format'))));
    }

    /**
     * Automatic format of a column: the first format whose keyword pattern matches the column name and type.
     *
     * @param string $key column alias
     * @param string $type column type (id, number, date, text)
     * @return string
     */
    public static function detect_format(string $key, string $type): string {
        foreach (self::formats() as $rule) {
            if (empty($rule['match']) || empty($rule['format'])) {
                continue;
            }
            if (!empty($rule['types']) && !in_array($type, $rule['types'], true)) {
                continue;
            }
            if (@preg_match('/' . str_replace('/', '\/', $rule['match']) . '/i', $key)) {
                return (string) $rule['format'];
            }
        }
        return 'text';
    }

    /**
     * Column formats of instructions.json, with the words of the progress and state formats taken from the plugin
     * settings (Column formats) when they are set.
     *
     * @return array[]
     */
    public static function formats(): array {
        $formats = self::get()['formats'] ?? [];
        foreach ($formats as $i => $rule) {
            $format = $rule['format'] ?? '';
            if (!in_array($format, ['progress', 'state'], true)) {
                continue;
            }
            $words = self::keywords($format);
            if (!$words) {
                // No words: the format is only used when chosen in the chart settings.
                $formats[$i]['match'] = '';
                $formats[$i]['keyword'] = '-';
                continue;
            }
            $list = implode('|', array_map('preg_quote', $words));
            // Progress: the word anywhere between underscores (completion_pct_total); state: at the end (course_status).
            $formats[$i]['match'] = $format === 'progress' ? "(^|_)($list)(_|$)" : "(^|_)($list)$";
            $formats[$i]['keyword'] = implode(', ', $words);
        }
        return $formats;
    }

    /**
     * Words of a column name that give it a format (progress, state): the plugin setting, else instructions.json.
     *
     * @param string $format progress or state
     * @return string[] lower case words (letters, digits)
     */
    public static function keywords(string $format): array {
        $setting = get_config('local_reportium', $format . 'keywords');
        if ($setting === false || $setting === null) {
            foreach (self::get()['formats'] ?? [] as $rule) {
                if (($rule['format'] ?? '') === $format) {
                    return (array) ($rule['keywords'] ?? []);
                }
            }
            return [];
        }
        return self::word_list((string) $setting);
    }

    /**
     * Comma separated words as a clean list: lower case, letters and digits only, no repeats.
     *
     * @param string $text
     * @return string[]
     */
    public static function word_list(string $text): array {
        return array_values(array_unique(array_filter(array_map([self::class, 'normalise'], explode(',', $text)))));
    }

    /**
     * Colours of the progress bar at 0%, 50% and 100%: the plugin settings, else instructions.json.
     *
     * @return string[] ['low' => #hex, 'mid' => #hex, 'high' => #hex]
     */
    public static function progress_colors(): array {
        $colors = ['low' => '#c71c22', 'mid' => '#f0ad4e', 'high' => '#73a839'];
        foreach (self::get()['formats'] ?? [] as $rule) {
            if (($rule['format'] ?? '') === 'progress') {
                $colors = array_merge($colors, (array) ($rule['colors'] ?? []));
            }
        }
        foreach ($colors as $name => $color) {
            $setting = (string) get_config('local_reportium', 'progresscolor' . $name);
            $colors[$name] = self::color($setting !== '' ? $setting : $color, $color);
        }
        return $colors;
    }

    /**
     * State definitions: the ones edited in the plugin settings, else the ones of instructions.json.
     *
     * @return array[] [['code', 'color', 'aliases' => string[], 'label' => string, 'description' => string], ...]
     */
    public static function state_definitions(): array {
        $setting = get_config('local_reportium', 'states');
        $states = is_string($setting) && $setting !== '' ? json_decode($setting, true) : null;
        return is_array($states) ? $states : (self::get()['states'] ?? []);
    }

    /**
     * State codes with their colours and labels, for the browser.
     *
     * @return array [['code', 'keys', 'color', 'textcolor', 'label'], ...]
     */
    public static function states(): array {
        $states = [];
        foreach (self::state_definitions() as $state) {
            $code = self::normalise((string) ($state['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            // Normalised keys the value is compared with (code and aliases).
            $keys = array_map([self::class, 'normalise'], array_merge([$code], (array) ($state['aliases'] ?? [])));
            $color = self::color((string) ($state['color'] ?? ''), '#6c757d');
            $states[] = [
                'code' => $code,
                'keys' => array_values(array_unique(array_filter($keys))),
                'color' => $color,
                'textcolor' => self::text_color($color),
                'label' => self::state_label($code, (string) ($state['label'] ?? '')),
            ];
        }
        return $states;
    }

    /**
     * Label of a state: its own label, else the translated name of the code, else the code.
     *
     * @param string $code normalised code
     * @param string $label label set in the plugin settings
     * @return string
     */
    public static function state_label(string $code, string $label = ''): string {
        if (trim($label) !== '') {
            return format_string(trim($label));
        }
        if ($code !== '' && get_string_manager()->string_exists('ui_state_' . $code, 'local_reportium')) {
            return get_string('ui_state_' . $code, 'local_reportium');
        }
        return ucfirst($code);
    }

    /**
     * A #rrggbb colour, or the fallback when the value is not one.
     *
     * @param string $value
     * @param string $fallback
     * @return string
     */
    public static function color(string $value, string $fallback): string {
        $value = trim($value);
        if (preg_match('/^#[0-9a-f]{3}$/i', $value)) {
            $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }
        return preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : $fallback;
    }

    /**
     * Readable text colour on a background: dark on light colours, white on dark ones.
     *
     * @param string $background #rrggbb
     * @return string
     */
    public static function text_color(string $background): string {
        [$r, $g, $b] = array_map('hexdec', str_split(substr($background, 1), 2));
        // Relative luminance (sRGB weights).
        return (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255 > 0.6 ? '#1d2125' : '#ffffff';
    }

    /**
     * Normalise a state value or code for matching: "In Progress", "in_progress" and "in-progress" all give
     * "inprogress"; accents are ignored ("Sí" gives "si").
     *
     * @param string $value
     * @return string
     */
    public static function normalise(string $value): string {
        return preg_replace('/[^a-z0-9]/', '', \core_text::strtolower(\core_text::specialtoascii(trim($value))));
    }

    /**
     * The rules as text for the AI assistant's system prompt.
     *
     * @return string
     */
    public static function for_prompt(): string {
        $data = self::get();
        $lines = ['Placeholders (replaced in the SQL of a level):'];
        foreach ($data['placeholders'] ?? [] as $item) {
            $lines[] = "- {$item['keyword']} ({$item['levels']}): {$item['description']}";
        }
        $lines[] = 'Column keywords (how a column is detected and shown, decided by its alias):';
        foreach (array_merge($data['columns'] ?? [], self::formats()) as $item) {
            $lines[] = "- {$item['keyword']}: {$item['description']}";
        }
        $lines[] = 'State codes (values matched ignoring case, accents, spaces and underscores): ' . implode(', ',
            array_map(static function(array $state): string {
                $words = array_merge([$state['code'] ?? ''], (array) ($state['aliases'] ?? []));
                return implode('/', array_filter($words)) .
                    (!empty($state['description']) ? ' (' . $state['description'] . ')' : '');
            }, self::state_definitions()));
        foreach ($data['conventions'] ?? [] as $convention) {
            $lines[] = '- ' . $convention;
        }
        $lines[] = 'Where each setting lives:';
        foreach ($data['scopes'] ?? [] as $scope) {
            $lines[] = "- {$scope['scope']} ({$scope['where']}): {$scope['settings']}";
        }
        $lines[] = 'Features (what the user can do and where):';
        foreach ($data['features'] ?? [] as $feature) {
            $lines[] = "- {$feature['feature']} [{$feature['where']}]: {$feature['description']}";
        }
        return implode("\n", $lines);
    }
}
