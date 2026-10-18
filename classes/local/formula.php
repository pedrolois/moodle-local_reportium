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

use moodle_exception;

/**
 * Calculated columns: a formula over the columns of a row, without SQL ("[completed] / [enrolled] * 100").
 *
 * Only numbers, columns in brackets, + - * / ( ) and a few functions: round(x, decimals), abs(x), min(...), max(...),
 * pct(part, total). Nothing is executed: the formula is parsed into a tree and calculated row by row. The same
 * grammar is calculated on screen by amd/src/levels.js.
 *
 * @package    local_reportium
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class formula {
    /** @var array Functions: name => [min arguments, max arguments]. */
    const FUNCTIONS = ['round' => [1, 2], 'abs' => [1, 1], 'min' => [1, 20], 'max' => [1, 20], 'pct' => [2, 2]];

    /** @var int Calculated columns per level. */
    const MAX_COLUMNS = 10;

    /** @var int Length of a formula. */
    const MAX_LENGTH = 500;

    /** @var string[] Display formats of a calculated column. */
    const FORMATS = ['text', 'progress'];

    /** @var array Tokens of the formula being parsed. */
    protected $tokens = [];

    /** @var int Position in the tokens. */
    protected $pos = 0;

    /** @var array Columns that may be referenced: key or lowercase label => key. */
    protected $refs = [];

    /**
     * Parse a formula into a tree.
     *
     * @param string $text
     * @param array $refs key or lowercase label => key
     * @return array tree: ['n', number] | ['r', key] | ['neg', node] | ['op', '+', a, b] | ['f', name, [args]]
     * @throws moodle_exception formulainvalid
     */
    public static function parse(string $text, array $refs): array {
        $parser = new self();
        $parser->refs = $refs;
        $parser->tokens = self::tokens($text);
        $tree = $parser->expression();
        if ($parser->pos !== count($parser->tokens)) {
            throw new moodle_exception('formulainvalid', 'local_reportium');
        }
        return $tree;
    }

    /**
     * Tokens of a formula.
     *
     * @param string $text
     * @return array [[type, value]] type = num | ref | name | sym
     */
    protected static function tokens(string $text): array {
        if (trim($text) === '' || \core_text::strlen($text) > self::MAX_LENGTH) {
            throw new moodle_exception('formulainvalid', 'local_reportium');
        }
        $tokens = [];
        $offset = 0;
        $length = strlen($text);
        while ($offset < $length) {
            if (
                !preg_match('/\s*(?:(\d+(?:[.,]\d+)?)|\[([^\]\[]+)\]|([a-zA-Z]+)|([-+*\/(),]))\s*/A', $text, $m, 0, $offset)
                    || $m[0] === ''
            ) {
                throw new moodle_exception('formulainvalid', 'local_reportium');
            }
            $offset += strlen($m[0]);
            if (($m[1] ?? '') !== '') {
                $tokens[] = ['num', (float) str_replace(',', '.', $m[1])];
            } else if (($m[2] ?? '') !== '') {
                $tokens[] = ['ref', trim($m[2])];
            } else if (($m[3] ?? '') !== '') {
                $tokens[] = ['name', strtolower($m[3])];
            } else if (($m[4] ?? '') !== '') {
                $tokens[] = ['sym', $m[4]];
            }
        }
        return $tokens;
    }

    /**
     * Whether the next token is a symbol.
     *
     * @param string $symbol
     * @return bool
     */
    protected function at(string $symbol): bool {
        return isset($this->tokens[$this->pos]) && $this->tokens[$this->pos][0] === 'sym'
            && $this->tokens[$this->pos][1] === $symbol;
    }

    /**
     * expression := term (('+' | '-') term)*
     *
     * @return array
     */
    protected function expression(): array {
        $node = $this->term();
        while ($this->at('+') || $this->at('-')) {
            $op = $this->tokens[$this->pos++][1];
            $node = ['op', $op, $node, $this->term()];
        }
        return $node;
    }

    /**
     * term := unary (('*' | '/') unary)*
     *
     * @return array
     */
    protected function term(): array {
        $node = $this->unary();
        while ($this->at('*') || $this->at('/')) {
            $op = $this->tokens[$this->pos++][1];
            $node = ['op', $op, $node, $this->unary()];
        }
        return $node;
    }

    /**
     * unary := '-' unary | '+' unary | primary
     *
     * @return array
     */
    protected function unary(): array {
        if ($this->at('-')) {
            $this->pos++;
            return ['neg', $this->unary()];
        }
        if ($this->at('+')) {
            $this->pos++;
            return $this->unary();
        }
        return $this->primary();
    }

    /**
     * primary := number | [column] | function '(' arguments ')' | '(' expression ')'
     *
     * @return array
     */
    protected function primary(): array {
        $token = $this->tokens[$this->pos] ?? null;
        if (!$token) {
            throw new moodle_exception('formulainvalid', 'local_reportium');
        }
        $this->pos++;
        if ($token[0] === 'num') {
            return ['n', $token[1]];
        }
        if ($token[0] === 'ref') {
            $key = $this->refs[$token[1]] ?? ($this->refs[\core_text::strtolower($token[1])] ?? null);
            if ($key === null) {
                throw new moodle_exception('formulaunknowncolumn', 'local_reportium', '', s($token[1]));
            }
            return ['r', $key];
        }
        if ($token[0] === 'name' && isset(self::FUNCTIONS[$token[1]]) && $this->at('(')) {
            $this->pos++;
            $args = [$this->expression()];
            while ($this->at(',')) {
                $this->pos++;
                $args[] = $this->expression();
            }
            if (!$this->at(')')) {
                throw new moodle_exception('formulainvalid', 'local_reportium');
            }
            $this->pos++;
            [$min, $max] = self::FUNCTIONS[$token[1]];
            if (count($args) < $min || count($args) > $max) {
                throw new moodle_exception('formulainvalid', 'local_reportium');
            }
            return ['f', $token[1], $args];
        }
        if ($token[0] === 'sym' && $token[1] === '(') {
            $node = $this->expression();
            if (!$this->at(')')) {
                throw new moodle_exception('formulainvalid', 'local_reportium');
            }
            $this->pos++;
            return $node;
        }
        throw new moodle_exception('formulainvalid', 'local_reportium');
    }

    /**
     * Value of a tree for a row: null when a column is empty or not a number, or on a division by zero.
     *
     * @param array $node
     * @param array $row
     * @return float|null
     */
    public static function evaluate(array $node, array $row): ?float {
        switch ($node[0]) {
            case 'n':
                return $node[1];
            case 'r':
                $value = $row[$node[1]] ?? null;
                return is_numeric($value) ? (float) $value : null;
            case 'neg':
                $value = self::evaluate($node[1], $row);
                return $value === null ? null : -$value;
            case 'op':
                $a = self::evaluate($node[2], $row);
                $b = self::evaluate($node[3], $row);
                if ($a === null || $b === null) {
                    return null;
                }
                switch ($node[1]) {
                    case '+':
                        return $a + $b;
                    case '-':
                        return $a - $b;
                    case '*':
                        return $a * $b;
                    default:
                        return abs($b) < 1e-12 ? null : $a / $b;
                }
            case 'f':
                $args = array_map(static fn(array $arg) => self::evaluate($arg, $row), $node[2]);
                if (in_array(null, $args, true)) {
                    return null;
                }
                switch ($node[1]) {
                    case 'round':
                        return round($args[0], max(0, min(6, (int) ($args[1] ?? 0))));
                    case 'abs':
                        return abs($args[0]);
                    case 'min':
                        return min($args);
                    case 'max':
                        return max($args);
                    default:
                        return abs($args[1]) < 1e-12 ? null : $args[0] / $args[1] * 100;
                }
        }
        return null;
    }

    /**
     * Columns a formula may reference: key and lowercase label of each column.
     *
     * @param array[] $columns
     * @return array
     */
    protected static function refs(array $columns): array {
        $refs = [];
        foreach ($columns as $column) {
            $refs[\core_text::strtolower((string) $column['label'])] = (string) $column['key'];
        }
        foreach ($columns as $column) {
            $refs[(string) $column['key']] = (string) $column['key'];
        }
        return $refs;
    }

    /**
     * Valid calculated columns of a level setting: [{key: calc_…, label, formula, format}], each formula parsed
     * against the columns of the SQL and the calculated columns before it.
     *
     * @param mixed $list
     * @param array[] $columns columns of the SQL (calculated ones are ignored)
     * @return array[] [['key', 'label', 'formula', 'format', 'tree']]
     */
    public static function clean_list($list, array $columns): array {
        if (!is_array($list)) {
            return [];
        }
        $columns = array_values(array_filter($columns, static fn(array $c) => empty($c['calculated'])));
        $keys = array_column($columns, 'key');
        $clean = [];
        foreach (array_slice(array_values($list), 0, self::MAX_COLUMNS) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $key = (string) ($item['key'] ?? '');
            $label = trim(clean_param((string) ($item['label'] ?? ''), PARAM_TEXT));
            $text = trim((string) ($item['formula'] ?? ''));
            if (!preg_match('/^calc_[a-z0-9_]{1,30}$/', $key) || in_array($key, $keys, true) || $label === '') {
                continue;
            }
            try {
                $tree = self::parse($text, self::refs($columns));
            } catch (moodle_exception $e) {
                continue;
            }
            $format = in_array($item['format'] ?? '', self::FORMATS, true) ? $item['format'] : 'text';
            $clean[] = ['key' => $key, 'label' => \core_text::substr($label, 0, 100), 'formula' => $text, 'format' => $format,
                'tree' => $tree];
            // A later column may use this one.
            $keys[] = $key;
            $columns[] = ['key' => $key, 'label' => $label];
        }
        return $clean;
    }

    /**
     * Add the calculated columns to the columns and rows of a level (replacing those calculated before).
     *
     * @param array[] $columns
     * @param array[] $rows
     * @param mixed $list setting of the level (see clean_list())
     * @return array [columns, rows]
     */
    public static function apply(array $columns, array $rows, $list): array {
        $columns = array_values(array_filter($columns, static fn(array $c) => empty($c['calculated'])));
        $defs = self::clean_list($list, $columns);
        if (!$defs) {
            return [$columns, $rows];
        }
        foreach ($defs as $def) {
            $columns[] = ['key' => $def['key'], 'label' => $def['label'], 'type' => column_inspector::TYPE_NUMBER,
                'format' => $def['format'], 'withtime' => false, 'html' => false, 'calculated' => true];
        }
        foreach ($rows as $i => $row) {
            foreach ($defs as $def) {
                $value = self::evaluate($def['tree'], $row);
                $row[$def['key']] = $value === null || !is_finite($value) ? null : round($value, 2);
            }
            $rows[$i] = $row;
        }
        return [$columns, $rows];
    }

    /**
     * Definitions of the calculated columns as kept in the level mapping (no tree).
     *
     * @param mixed $list
     * @param array[] $columns
     * @return array[]
     */
    public static function definitions($list, array $columns): array {
        return array_map(static fn(array $def) => array_diff_key($def, ['tree' => 1]), self::clean_list($list, $columns));
    }
}
