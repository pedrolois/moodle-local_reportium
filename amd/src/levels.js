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

/**
 * Reportium interactive report: generic drill-down levels with one data model for every view.
 *
 * Every view (bar, pie, line, table) renders the same level payload from local_reportium_get_level_data, so they
 * always agree. Filters are generated automatically from the columns the SQL returns, and the chart mapping
 * (X axis, metrics, value passed to the next level) is automatic but visible and editable.
 *
 * @module     local_reportium/levels
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getStrings} from 'core/str';
import AiHelp from 'local_reportium/aihelp';
import {setUserPreference} from 'core_user/repository';

const STRING_KEYS = [
    'ui_all', 'ui_apply', 'ui_auto', 'ui_bar', 'ui_cached', 'ui_chartsettings', 'ui_clearfilters',
    'ui_filters', 'ui_from', 'ui_line', 'ui_live', 'ui_max', 'ui_metrics', 'ui_min', 'ui_nodata', 'ui_pie',
    'ui_reset', 'ui_rowsshown', 'ui_save', 'ui_saved', 'ui_search',
    'ui_stacked', 'ui_table', 'ui_to', 'ui_valuecolumn', 'ui_view', 'ui_xaxis', 'ui_slicelabel',
    'ui_sql', 'ui_sqladdlevel', 'ui_sqldelete', 'ui_sqlhelp', 'ui_sqllabel', 'ui_sqlnewlevel', 'ui_sqlsave', 'ui_sqlsaved',
    'ui_sqltitle', 'deletelevel_title', 'deletelevel_confirm', 'ui_downloadcsv', 'ui_compare',
    'ui_sqlinspector', 'ui_close', 'ui_sqladdshort', 'ui_sqlsavedbadge', 'ui_sqlunsaved', 'ui_sqlprevhint', 'ui_sqlsaving',
    'ui_freshness_explain', 'ui_freshness_title', 'ui_freshness_live', 'ui_freshness_cached', 'totalrecords',
    'ui_clear', 'ui_closecompare', 'lastexecutiontime',
    'ui_columnformats', 'ui_format_auto', 'ui_format_text', 'ui_format_progress', 'ui_format_state',
    'ui_nosql', 'ui_nosql_add', 'reportviews', 'ui_pagesize', 'ui_pagerange', 'ui_pageof', 'ui_firstpage', 'ui_previouspage',
    'ui_nextpage', 'ui_lastpage', 'ui_sqlnote_mycourses', 'ui_filtercolumn', 'ui_contains',
    'ui_period', 'ui_previousperiod', 'ui_comparewithprevious', 'ui_rangefrom', 'ui_rangeto',
    'ui_slowquery', 'ui_slowtips', 'ui_slowbadge', 'ui_kpis', 'ui_kpi_rows', 'ui_kpi_total', 'ui_kpi_average',
    'ui_kpi_vsprevious', 'ui_openinmoodle', 'ui_kpi_sum', 'ui_kpi_avg', 'ui_kpi_min', 'ui_kpi_max', 'ui_kpi_count',
    'ui_kpi_distinct', 'ui_kpi_none', 'ui_kpi_showrows', 'ui_totalsrow', 'ui_totals', 'ui_kpi_earliest', 'ui_kpi_latest',
    'ui_kpi_help', 'ui_settings_chart', 'ui_settings_table', 'ui_settings_kpis', 'ui_settings_views',
    'ui_settings_formats_help', 'ui_settings_views_help', 'ui_kpi_edit', 'ui_kpi_add', 'ui_kpi_remove', 'ui_kpi_column',
    'ui_kpi_calculation', 'ui_collapseall', 'ui_expandall', 'ui_kpi_duplicate',
    'ui_history', 'ui_historyempty', 'ui_historyuse', 'ui_historyrestore', 'ui_historyrestored', 'ui_historychanges',
    'ui_historyaction_saved', 'ui_historyaction_deleted', 'ui_historyaction_restored', 'ui_historydeletedhint',
    'ui_calc_add', 'ui_calc_edit', 'ui_calc_name', 'ui_calc_formula', 'ui_calc_help', 'ui_calc_format', 'ui_calc_number',
    'ui_calc_remove', 'ui_calc_noname', 'ui_calc_preview', 'ui_calc_columns', 'ui_dragcolumn', 'ui_resetcolumnorder',
    'formulainvalid', 'formulaunknowncolumn',
];
const VIEWS = ['bar', 'pie', 'line', 'table'];
/** Functions of a formula: name => [min arguments, max arguments]. */
const FORMULA_FUNCTIONS = {round: [1, 2], abs: [1, 1], min: [1, 20], max: [1, 20], pct: [2, 2]};
const MAX_CALCULATED = 10;

/**
 * Cursor of the links in the tables: a pointing hand in a colour (the theme's), outlined in white.
 *
 * @param {String} color CSS colour
 * @returns {String} value of the CSS cursor property
 */
const linkCursor = (color) => {
    const fill = String(color || '').trim() || '#0f6cbf';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="26" viewBox="-24 -16 496 544">
        <path fill="${fill}" stroke="#fff" stroke-width="44" stroke-linejoin="round" paint-order="stroke"
        d="M128 40c0-22.1 17.9-40 40-40s40 17.9
        40 40v148.2c8.5-7.6 19.7-12.2 32-12.2 20.6 0 38.2 13 45 31.2 8.8-9.3 21.2-15.2 35-15.2 25.3 0 46 19.5 47.9 44.3
        8.5-7.7 19.8-12.3 32.1-12.3 26.5 0 48 21.5 48 48v112c0 70.7-57.3 128-128 128h-85.3c-5 0-9.9-.3-14.7-1
        -55.3-5.6-101.2-43.7-117.5-96.5L57.4 327.8c-7.9-25.7 6.6-53 32.3-60.8s52.9 6.4 60.8 32.2L128 340V40z"/></svg>`;
    return `url("data:image/svg+xml,${encodeURIComponent(svg.replace(/\s+/g, ' '))}") 9 1, pointer`;
};
/**
 * Parse a calculated-column formula into a tree: numbers, [column] (key or label), + - * / ( ) and
 * round, abs, min, max, pct. Same grammar as classes/local/formula.php; nothing is executed.
 *
 * @param {String} text
 * @param {Object} refs key or lowercase label => key
 * @returns {Array} tree
 * @throws {Object} {code: 'invalid'} or {code: 'unknown', name}
 */
const parseFormula = (text, refs) => {
    const source = String(text || '');
    const invalid = {code: 'invalid'};
    if (!source.trim() || source.length > 500) {
        throw invalid;
    }
    const tokens = [];
    const re = /\s*(?:(\d+(?:[.,]\d+)?)|\[([^\][]+)\]|([a-zA-Z]+)|([-+*/(),]))\s*/y;
    let offset = 0;
    while (offset < source.length) {
        re.lastIndex = offset;
        const m = re.exec(source);
        if (!m || !m[0].length) {
            throw invalid;
        }
        offset = re.lastIndex;
        if (m[1] !== undefined) {
            tokens.push(['num', parseFloat(m[1].replace(',', '.'))]);
        } else if (m[2] !== undefined) {
            tokens.push(['ref', m[2].trim()]);
        } else if (m[3] !== undefined) {
            tokens.push(['name', m[3].toLowerCase()]);
        } else {
            tokens.push(['sym', m[4]]);
        }
    }
    let pos = 0;
    const at = (symbol) => tokens[pos] && tokens[pos][0] === 'sym' && tokens[pos][1] === symbol;
    let expression = null;
    const primary = () => {
        const token = tokens[pos++];
        if (!token) {
            throw invalid;
        }
        if (token[0] === 'num') {
            return ['n', token[1]];
        }
        if (token[0] === 'ref') {
            const key = refs[token[1]] ?? refs[token[1].toLowerCase()];
            if (key === undefined) {
                throw {code: 'unknown', name: token[1]};
            }
            return ['r', key];
        }
        if (token[0] === 'name' && FORMULA_FUNCTIONS[token[1]] && at('(')) {
            pos++;
            const args = [expression()];
            while (at(',')) {
                pos++;
                args.push(expression());
            }
            if (!at(')')) {
                throw invalid;
            }
            pos++;
            const [min, max] = FORMULA_FUNCTIONS[token[1]];
            if (args.length < min || args.length > max) {
                throw invalid;
            }
            return ['f', token[1], args];
        }
        if (token[0] === 'sym' && token[1] === '(') {
            const node = expression();
            if (!at(')')) {
                throw invalid;
            }
            pos++;
            return node;
        }
        throw invalid;
    };
    const unary = () => {
        if (at('-')) {
            pos++;
            return ['neg', unary()];
        }
        if (at('+')) {
            pos++;
            return unary();
        }
        return primary();
    };
    const term = () => {
        let node = unary();
        while (at('*') || at('/')) {
            const op = tokens[pos++][1];
            node = ['op', op, node, unary()];
        }
        return node;
    };
    expression = () => {
        let node = term();
        while (at('+') || at('-')) {
            const op = tokens[pos++][1];
            node = ['op', op, node, term()];
        }
        return node;
    };
    const tree = expression();
    if (pos !== tokens.length) {
        throw invalid;
    }
    return tree;
};

/**
 * Round half away from zero (like PHP round()).
 *
 * @param {Number} value
 * @param {Number} decimals
 * @returns {Number}
 */
const roundTo = (value, decimals) => {
    const factor = Math.pow(10, decimals);
    return Math.sign(value) * Math.round(Math.abs(value) * factor) / factor;
};

/**
 * Value of a formula tree for a row: null when a column is empty or not a number, or on a division by zero.
 *
 * @param {Array} node
 * @param {Object} row
 * @returns {Number|null}
 */
const evalFormula = (node, row) => {
    const num = (v) => (v === null || v === undefined || typeof v === 'boolean' || String(v).trim() === ''
        || !isFinite(Number(v)) ? null : Number(v));
    switch (node[0]) {
        case 'n':
            return node[1];
        case 'r':
            return num(row[node[1]]);
        case 'neg': {
            const value = evalFormula(node[1], row);
            return value === null ? null : -value;
        }
        case 'op': {
            const a = evalFormula(node[2], row);
            const b = evalFormula(node[3], row);
            if (a === null || b === null) {
                return null;
            }
            return {'+': a + b, '-': a - b, '*': a * b, '/': Math.abs(b) < 1e-12 ? null : a / b}[node[1]];
        }
        case 'f': {
            const args = node[2].map((arg) => evalFormula(arg, row));
            if (args.includes(null)) {
                return null;
            }
            if (node[1] === 'round') {
                return roundTo(args[0], Math.max(0, Math.min(6, Math.trunc(args[1] || 0))));
            }
            if (node[1] === 'abs') {
                return Math.abs(args[0]);
            }
            if (node[1] === 'min') {
                return Math.min(...args);
            }
            if (node[1] === 'max') {
                return Math.max(...args);
            }
            return Math.abs(args[1]) < 1e-12 ? null : args[0] / args[1] * 100;
        }
    }
    return null;
};

/**
 * Columns a formula may reference: key and lowercase label of each column.
 *
 * @param {Array} columns
 * @returns {Object}
 */
const formulaRefs = (columns) => {
    const refs = {};
    columns.forEach((c) => {
        refs[String(c.label).toLowerCase()] = c.key;
    });
    columns.forEach((c) => {
        refs[c.key] = c.key;
    });
    return refs;
};
const MAX_SELECT_VALUES = 60;
const DEFAULT_PAGE_SIZE = 20;

let echartsPromise = null;

/**
 * Load ECharts once through RequireJS (its UMD build registers as an anonymous AMD module).
 *
 * @param {String} url URL of echarts.min.js
 * @returns {Promise<Object>}
 */
const loadEcharts = (url) => {
    if (window.echarts) {
        return Promise.resolve(window.echarts);
    }
    if (!echartsPromise) {
        echartsPromise = new Promise((resolve, reject) => {
            window.require.config({paths: {'reportium_echarts': url.replace(/\.js$/, '')}});
            window.require(['reportium_echarts'], resolve, reject);
        });
    }
    return echartsPromise;
};

/**
 * Escape text for HTML.
 *
 * @param {*} value
 * @returns {String}
 */
const esc = (value) => String(value === null || value === undefined ? '' : value)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

/**
 * Colour between two #rrggbb colours.
 *
 * @param {String} from colour at 0
 * @param {String} to colour at 1
 * @param {Number} share 0 to 1
 * @returns {String} rgb(r, g, b)
 */
const mixColor = (from, to, share) => {
    const parts = (hex) => [1, 3, 5].map((i) => parseInt(hex.substr(i, 2), 16));
    const [a, b] = [parts(from), parts(to)];
    return `rgb(${a.map((v, i) => Math.round(v + (b[i] - v) * Math.max(0, Math.min(1, share)))).join(', ')})`;
};

/**
 * Wrap a chart label on whole words: lines of at most max characters, at most lines lines, "…" when cut.
 *
 * @param {String} text
 * @param {Number} max characters per line
 * @param {Number} lines maximum number of lines
 * @returns {String} lines joined with \n
 */
const wrapLabel = (text, max, lines) => {
    const out = [];
    let line = '';
    const words = String(text).split(/\s+/).filter(Boolean);
    for (let i = 0; i < words.length; i++) {
        // A word longer than a line is cut.
        const word = words[i].length > max ? words[i].slice(0, max - 1) + '…' : words[i];
        if (line && (line + ' ' + word).length > max) {
            out.push(line);
            line = word;
        } else {
            line = line ? line + ' ' + word : word;
        }
        if (out.length === lines) {
            // No room left: end the last line with "…".
            const last = out[lines - 1];
            out[lines - 1] = (last.length >= max ? last.slice(0, max - 1) : last) + '…';
            return out.join('\n');
        }
    }
    if (line) {
        out.push(line);
    }
    return out.join('\n');
};

/** Date formats (strftime style) of the user's language, from the page; day first by default. */
let DATE_FORMATS = {date: '%d/%m/%Y', datetime: '%d/%m/%Y %H:%M'};

/**
 * Format a unix timestamp column value.
 *
 * @param {String|Number} value
 * @param {Boolean} withtime show the time of day too
 * @returns {String}
 */
const formatDate = (value, withtime = false) => {
    const n = parseInt(value, 10);
    if (!(n > 0)) {
        return '';
    }
    const date = new Date(n * 1000);
    const two = (v) => String(v).padStart(2, '0');
    const parts = {d: two(date.getDate()), e: String(date.getDate()), m: two(date.getMonth() + 1),
        Y: String(date.getFullYear()), y: String(date.getFullYear()).slice(-2), H: two(date.getHours()),
        M: two(date.getMinutes()), S: two(date.getSeconds())};
    // Moodle date formats of the user's language (strftime style), e.g. %d/%m/%Y.
    return (withtime ? DATE_FORMATS.datetime : DATE_FORMATS.date).replace(/%([a-zA-Z])/g, (m, c) => parts[c] ?? m);
};

/**
 * Format a cell according to its column type.
 *
 * @param {Object} column
 * @param {*} value
 * @returns {String}
 */
const formatCell = (column, value) => (column.type === 'date' ? formatDate(value, column.withtime)
    : String(value === null ? '' : value));

/**
 * One interactive report instance bound to a root element.
 */
class ReportApp {
    /**
     * Constructor.
     *
     * @param {HTMLElement} root
     * @param {Object} config {reportid, courseid, echartsurl, reportname, canconfigure}
     * @param {Object} strings
     */
    constructor(root, config, strings) {
        this.root = root;
        this.config = config;
        this.str = strings;
        this.charts = [];
        this.payloads = {};
        this.parentPayload = null;
        this.comparePayload = null;
        this.state = this.readUrlState();
        this.settingsOpen = false;
        // Side panel open on the right (filters, settings or sql); ?rsql=1 (e.g. right after creating a report)
        // opens the SQL Inspector.
        this.openPanel = new URL(window.location.href).searchParams.get('rsql') === '1' ? 'sql' : null;
        // Level shown in the SQL Inspector; null = follow the level being viewed.
        this.sqlSelected = null;
        this.levels = [];
        this.canmanagesql = false;
        // AI assistant shown inside the SQL Inspector (built once, kept across re-renders).
        this.chat = null;
        this.aihelp = null;
        // History of the SQL shown under the editor: its level (null = closed) and versions.
        this.historyLevel = null;
        this.history = null;
        // KPI card whose popover is open (its index, 'new' for the "+" card, null = none).
        this.openKpi = null;
        this.usesdates = false;
        // Calculated column whose popover is open (its key, 'new' for the "+" header, null = none).
        this.openCalc = null;
        // Column order dragged by the user, per level: {level: [keys]} (a user preference).
        const order = config.columnorder;
        this.columnOrder = order && typeof order === 'object' && !Array.isArray(order) ? order : {};
        this.dragColumn = null;
        // Links in the tables show a hand in the theme colour (a click on the rest of the row drills down).
        root.style.setProperty('--rp-link-cursor', linkCursor(getComputedStyle(root).getPropertyValue('--rp-primary')));
    }

    /**
     * Whether the SQL Inspector drawer is open.
     *
     * @returns {Boolean}
     */
    get sqlOpen() {
        return this.openPanel === 'sql';
    }

    /**
     * Load the list of levels (SQL included only for users who may manage SQL reports).
     *
     * @param {Object|null} initial the same data sent with the page (no request needed)
     * @returns {Promise}
     */
    loadLevels(initial = null) {
        const request = initial ? Promise.resolve(initial) : Ajax.call([{
            methodname: 'local_reportium_get_levels',
            args: {reportid: this.config.reportid, courseid: this.config.courseid},
        }])[0];
        return request.then((data) => {
            this.levels = data.levels;
            this.canmanagesql = data.canmanagesql;
            this.usesdates = data.usesdates;
            if (this.state.range.preset === null) {
                this.state.range.preset = data.daterange || '';
            }
            return data;
        });
    }

    /**
     * Initial state from the URL: rpath (JSON [{v,l}]), rview, rq (search), rf (JSON filters), rmap (JSON mapping),
     * rsort (table header sort "column:asc|desc").
     *
     * @returns {Object}
     */
    readUrlState() {
        const params = new URL(window.location.href).searchParams;
        const parse = (key, fallback) => {
            try {
                const raw = params.get(key);
                return raw ? JSON.parse(raw) : fallback;
            } catch (e) {
                return fallback;
            }
        };
        const view = params.get('rview');
        const compare = parse('rcmp', null);
        const [sortkey, sortdir] = (params.get('rsort') || '').split(':');
        return {
            compare: compare && compare.v !== undefined ? {value: String(compare.v), label: String(compare.l || compare.v)} : null,
            path: parse('rpath', [])
                .filter((p) => p && p.v !== undefined)
                .map((p) => ({value: String(p.v), label: String(p.l || p.v)})),
            view: VIEWS.includes(view) ? view : null,
            search: params.get('rq') || '',
            filters: parse('rf', {}),
            mapping: parse('rmap', null),
            tsort: sortkey && ['asc', 'desc'].includes(sortdir) ? {key: sortkey, dir: sortdir} : null,
            pages: [Math.max(0, (parseInt(params.get('rpage'), 10) || 1) - 1), 0],
            // Period of the data: null = not chosen yet (the report's own period applies); '' = all time.
            range: {
                preset: params.has('rrange') ? (params.get('rrange') === 'all' ? '' : params.get('rrange')) : null,
                from: parseInt(params.get('rfrom'), 10) || 0,
                to: parseInt(params.get('rto'), 10) || 0,
            },
            // Second pane with the period just before.
            prev: params.get('rprev') === '1',
        };
    }

    /**
     * Mirror the state in the URL so the exact view can be shared.
     */
    writeUrlState() {
        const url = new URL(window.location.href);
        const set = (key, value) => (value ? url.searchParams.set(key, value) : url.searchParams.delete(key));
        set('rpath', this.state.path.length ? JSON.stringify(this.state.path.map((p) => ({v: p.value, l: p.label}))) : '');
        set('rview', this.state.view || '');
        set('rq', this.state.search);
        set('rf', Object.keys(this.state.filters).length ? JSON.stringify(this.state.filters) : '');
        set('rmap', this.state.mapping ? JSON.stringify(this.state.mapping) : '');
        set('rcmp', this.state.compare ? JSON.stringify({v: this.state.compare.value, l: this.state.compare.label}) : '');
        set('rsort', this.state.tsort ? `${this.state.tsort.key}:${this.state.tsort.dir}` : '');
        set('rpage', this.state.pages && this.state.pages[0] ? String(this.state.pages[0] + 1) : '');
        const range = this.rangeArgs();
        set('rrange', this.usesdates && this.state.range.preset !== null ? (range.daterange || 'all') : '');
        set('rfrom', range.rangefrom ? String(range.rangefrom) : '');
        set('rto', range.rangeto ? String(range.rangeto) : '');
        set('rprev', this.state.prev && range.daterange ? '1' : '');
        window.history.replaceState(null, '', url.toString());
        this.updateExportLinks();
    }

    /**
     * Current level index.
     *
     * @returns {Number}
     */
    get level() {
        return this.state.path.length;
    }

    /**
     * Fetch (or reuse) the payload of a level.
     *
     * @param {Array} path values chosen at the previous levels (defaults to the current path)
     * @param {Boolean} previous the period just before the chosen one
     * @returns {Promise<Object>}
     */
    fetchLevel(path = this.state.path.map((p) => p.value), previous = false) {
        const range = this.rangeArgs();
        const key = JSON.stringify([path, range, previous]);
        if (this.payloads[key]) {
            return Promise.resolve(this.payloads[key]);
        }
        return Ajax.call([{
            methodname: 'local_reportium_get_level_data',
            args: Object.assign({reportid: this.config.reportid, level: path.length, path: path, courseid: this.config.courseid,
                previous: previous}, range),
        }])[0].then((data) => {
            const payload = Object.assign({}, data, {
                rows: JSON.parse(data.rows),
                mapping: JSON.parse(data.mapping),
                links: JSON.parse(data.links || '{}'),
            });
            this.payloads[key] = payload;
            return payload;
        });
    }

    /**
     * The data of the first level sent with the page: kept as the payload of level 1 when the view opens on it
     * with the same period, so it draws without a request.
     *
     * @param {Object|null} first {args: {daterange, rangefrom, rangeto}, data: get_level_data result}
     */
    seedFirstLevel(first) {
        if (!first || !first.data || this.state.path.length) {
            return;
        }
        const range = this.rangeArgs();
        if (JSON.stringify(range) !== JSON.stringify(first.args)) {
            return;
        }
        this.payloads[JSON.stringify([[], range, false])] = Object.assign({}, first.data, {
            rows: JSON.parse(first.data.rows),
            mapping: JSON.parse(first.data.mapping),
            links: JSON.parse(first.data.links || '{}'),
        });
    }

    /**
     * Period sent with every request: only for reports whose SQL uses %%STARTTIME%% / %%ENDTIME%%.
     *
     * @returns {Object} {daterange, rangefrom, rangeto}
     */
    rangeArgs() {
        const range = this.state.range;
        if (!this.usesdates || !range.preset) {
            return {daterange: '', rangefrom: 0, rangeto: 0};
        }
        const custom = range.preset === 'custom';
        return {daterange: range.preset, rangefrom: custom ? range.from : 0, rangeto: custom ? range.to : 0};
    }

    /**
     * Whether the second pane shows the period just before.
     *
     * @returns {Boolean}
     */
    get comparingPrevious() {
        return !!(this.state.prev && this.rangeArgs().daterange);
    }

    /**
     * Load the current level and render everything.
     *
     * @returns {Promise}
     */
    load() {
        if (!this.levels.length) {
            return Promise.resolve(this.renderEmpty());
        }
        this.root.classList.add('is-loading');
        const values = this.state.path.map((p) => p.value);
        const parentPath = values.slice(0, -1);
        return Promise.all([
            this.fetchLevel(values),
            // The parent level lists the items the current one can be compared with.
            values.length ? this.fetchLevel(parentPath) : Promise.resolve(null),
            // The second pane: the period just before, or a sibling item.
            this.comparingPrevious ? this.fetchLevel(values, true)
                : (values.length && this.state.compare ? this.fetchLevel(parentPath.concat([this.state.compare.value]))
                    : Promise.resolve(null)),
        ]).then(([payload, parent, compared]) => {
            this.payload = payload;
            this.parentPayload = parent;
            this.comparePayload = compared;
            this.sqlSelected = null;
            this.root.classList.remove('is-loading');
            this.render();
            return payload;
        }).catch((error) => {
            this.root.classList.remove('is-loading');
            this.root.querySelector('[data-region="body"]').innerHTML =
                '<div class="alert alert-danger" data-region="error">' + esc(error.message || error.error || error) + '</div>';
            return Notification.exception(error);
        });
    }

    /**
     * A report without SQL yet (just created): ask for the SQL of level 1, with the SQL Inspector open.
     */
    renderEmpty() {
        this.root.classList.remove('is-loading');
        this.payload = {columns: [], rows: [], mapping: {}, hasnext: false};
        this.openPanel = this.canmanagesql ? 'sql' : null;
        this.sqlSelected = 0;
        ['breadcrumbs', 'toolbar', 'filters', 'settings'].forEach((name) => {
            const region = this.root.querySelector(`[data-region="${name}"]`);
            if (region) {
                region.innerHTML = '';
            }
        });
        this.root.querySelector('[data-region="body"]').innerHTML = `<div class="alert alert-info" data-region="no-sql">
            ${esc(this.canmanagesql ? this.str.ui_nosql_add : this.str.ui_nosql)}</div>`;
        this.hasFilters = false;
        this.renderSql();
        this.renderSideTabs();
        const text = this.root.querySelector('[data-region="sql-text"]');
        if (text) {
            text.focus();
        }
    }

    /**
     * Effective mapping: automatic from the server, plus the temporary (URL) override.
     *
     * @returns {Object}
     */
    mapping() {
        const keys = this.payload.columns.map((c) => c.key);
        const base = Object.assign({stacked: false}, this.payload.mapping);
        const override = this.state.mapping || {};
        const merged = Object.assign({}, base);
        ['labelcolumn', 'valuecolumn'].forEach((field) => {
            // A label may combine two columns: "fullname|email" → "Ana Pérez (ana@x.org)".
            if (override[field] && String(override[field]).split('|').every((k) => keys.includes(k))) {
                merged[field] = override[field];
            }
        });
        if (Array.isArray(override.metrics)) {
            const metrics = override.metrics.filter((m) => keys.includes(m));
            if (metrics.length) {
                merged.metrics = metrics;
            }
        }
        ['stacked', 'kpis', 'totalsrow', 'calculated'].forEach((field) => {
            if (override[field] !== undefined) {
                merged[field] = override[field];
            }
        });
        if (override.pagesize !== undefined) {
            merged.pagesize = Math.max(1, parseInt(override.pagesize, 10) || DEFAULT_PAGE_SIZE);
        }
        if (override.formats && typeof override.formats === 'object') {
            merged.formats = Object.assign({}, base.formats || {}, override.formats);
        }
        if (Array.isArray(override.viewtypes)) {
            merged.viewtypes = override.viewtypes.filter((v) => VIEWS.includes(v));
        }
        // Each chart type keeps its own metrics and stacking ("views"); the rest is shared by the level.
        merged.views = Object.assign({}, base.views || {}, override.views || {});
        const view = this.state.view || override.defaultview || base.defaultview || 'bar';
        const own = merged.views[view] || {};
        if (Array.isArray(own.metrics)) {
            const metrics = own.metrics.filter((m) => keys.includes(m));
            if (metrics.length) {
                merged.metrics = metrics;
            }
        }
        if (own.stacked !== undefined) {
            merged.stacked = !!own.stacked;
        }
        return merged;
    }

    /**
     * Display format of a column: chosen in the chart settings, else detected from its name with the keywords of
     * instructions.json (progress bar, state label, text).
     *
     * @param {Object} column
     * @param {Object} payload level payload the column belongs to
     * @returns {String}
     */
    formatOf(column, payload = this.payload) {
        const override = (this.state.mapping || {}).formats || {};
        const saved = (payload.mapping || {}).formats || {};
        return override[column.key] || saved[column.key] || column.format || 'text';
    }

    /**
     * Display text of a row for a label spec: "col", or "col|extra" shown as "value (extra)".
     *
     * @param {Object} payload level payload (for column types)
     * @param {String} spec label column spec
     * @param {Object} row
     * @returns {String}
     */
    labelOf(payload, spec, row) {
        const [main, extra] = String(spec || '').split('|');
        const column = (key) => payload.columns.find((c) => c.key === key) || {type: 'text'};
        let text = formatCell(column(main), row[main] ?? '');
        if (extra) {
            const value = formatCell(column(extra), row[extra] ?? '');
            if (value !== '') {
                text += ` (${value})`;
            }
        }
        return text;
    }

    /**
     * Rows after search and filters, sorted by the column arrows. Every view uses these (one page at a time).
     *
     * @param {Object} payload level payload (defaults to the current level)
     * @returns {Array}
     */
    visibleRows(payload = this.payload) {
        const columns = payload.columns;
        const query = this.state.search.trim().toLowerCase();
        let rows = payload.rows.filter((row) => {
            if (query && !columns.some((c) => formatCell(c, row[c.key]).toLowerCase().includes(query))) {
                return false;
            }
            return Object.entries(this.state.filters).every(([key, filter]) => {
                const column = columns.find((c) => c.key === key);
                if (!column || !filter) {
                    return true;
                }
                const value = row[key];
                if (filter.like) {
                    return formatCell(column, value).toLowerCase().includes(String(filter.like).toLowerCase());
                }
                if (Array.isArray(filter.in) && filter.in.length) {
                    return filter.in.includes(String(value));
                }
                const num = column.type === 'date' ? parseInt(value, 10) : parseFloat(value);
                if (filter.min !== undefined && filter.min !== '' && !(num >= parseFloat(filter.min))) {
                    return false;
                }
                if (filter.max !== undefined && filter.max !== '' && !(num <= parseFloat(filter.max))) {
                    return false;
                }
                return true;
            });
        });
        // Table header sort (the column arrows): chart included, so both stay in step.
        const tsort = this.state.tsort;
        const sortcolumn = tsort ? columns.find((c) => c.key === tsort.key) : null;
        if (sortcolumn) {
            const dir = tsort.dir === 'asc' ? 1 : -1;
            const numeric = sortcolumn.type !== 'text';
            rows = rows.slice().sort((a, b) => dir * (numeric
                ? (parseFloat(a[tsort.key]) || 0) - (parseFloat(b[tsort.key]) || 0)
                : String(a[tsort.key] ?? '').localeCompare(String(b[tsort.key] ?? ''), undefined, {sensitivity: 'base'})));
        }
        return rows;
    }

    /**
     * Current view: URL choice, else the level's default.
     *
     * @returns {String}
     */
    view() {
        const allowed = this.allowedViews();
        const view = this.state.view || this.mapping().defaultview || 'bar';
        // A chart type the report does not offer (old saved default, hand-made URL) falls back to the first offered.
        return allowed.includes(view) ? view : allowed[0];
    }

    /**
     * Chart types of this level: the ones the report offers, or the subset chosen for the level in Chart settings.
     *
     * @returns {String[]}
     */
    allowedViews() {
        const level = this.payload ? (this.mapping().viewtypes || []) : [];
        const own = this.offeredViews().filter((v) => level.includes(v));
        return own.length ? own : this.offeredViews();
    }

    /**
     * Chart types the report offers (Reports manager / report form); each level may keep a subset of them.
     *
     * @returns {String[]}
     */
    offeredViews() {
        const chosen = (this.config.views || []).filter((v) => VIEWS.includes(v));
        return chosen.length ? VIEWS.filter((v) => chosen.includes(v)) : VIEWS;
    }

    /**
     * Title of the current level.
     *
     * @returns {String}
     */
    title() {
        const parent = this.state.path.length ? this.state.path[this.state.path.length - 1].label : '';
        // Generic labels like "Level 2" (from the old SQL drawer) say nothing: use the clicked item instead.
        const custom = this.payload.title && !/^level\s*\d+$/i.test(this.payload.title.trim());
        if (custom) {
            return this.payload.title.replace('{parent}', parent);
        }
        // At the root the report name is already the page heading and the first breadcrumb.
        return parent;
    }

    /**
     * Render the whole UI for the current level.
     */
    render() {
        this.applyCalculated(this.payload);
        this.applyCalculated(this.comparePayload);
        const rows = this.visibleRows();
        const title = this.title();
        const titlenode = this.root.querySelector('[data-region="title"]');
        titlenode.textContent = title;
        titlenode.hidden = title === '';
        this.renderBreadcrumbs();
        this.renderToolbar();
        this.renderFilters();
        this.renderSettings();
        this.renderSql();
        this.renderSideTabs();
        this.renderBody(rows);
        this.writeUrlState();
    }

    /**
     * Point the footer "Download report" links at what is on screen: current level, search, filters, chart settings.
     */
    updateExportLinks() {
        // Print: the browser print (the print stylesheet hides controls and Moodle chrome).
        document.querySelectorAll('a[data-action="print-report"]').forEach((link) => {
            if (!link.dataset.reportiumPrint) {
                link.dataset.reportiumPrint = '1';
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    window.print();
                });
            }
        });
        document.querySelectorAll('a[data-action="download-png"]').forEach((link) => {
            // A button class shows it even when hidden: hide it with its style.
            link.style.display = this.view() === 'table' ? 'none' : '';
            if (!link.dataset.reportiumPng) {
                link.dataset.reportiumPng = '1';
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.downloadPng();
                });
            }
        });
        document.querySelectorAll('a[href*="download=1"][href*="format="]').forEach((link) => {
            const url = new URL(link.getAttribute('href'), window.location.href);
            const set = (key, value) => (value ? url.searchParams.set(key, value) : url.searchParams.delete(key));
            url.searchParams.set('rlevel', String(this.level));
            set('rpath', this.state.path.length ? JSON.stringify(this.state.path.map((p) => ({v: p.value, l: p.label}))) : '');
            set('rq', this.state.search);
            set('rf', Object.keys(this.state.filters).length ? JSON.stringify(this.state.filters) : '');
            set('rmap', this.state.mapping ? JSON.stringify(this.state.mapping) : '');
            set('rsort', this.state.tsort ? `${this.state.tsort.key}:${this.state.tsort.dir}` : '');
            const range = this.rangeArgs();
            set('rrange', range.daterange);
            set('rfrom', range.rangefrom ? String(range.rangefrom) : '');
            set('rto', range.rangeto ? String(range.rangeto) : '');
            // KPI cards and the totals row on screen go at the end of the file.
            const kpis = this.payload ? this.kpiSpecs() : [];
            set('rkpi', kpis.length ? JSON.stringify(kpis) : '');
            set('rtotals', kpis.length && this.totalsRowShown() ? '1' : '');
            link.setAttribute('href', url.toString());
            // The PDF carries the charts and KPI cards of the page (like the print).
            if (url.searchParams.get('format') === 'pdf' && !link.dataset.reportiumPdf) {
                link.dataset.reportiumPdf = '1';
                link.addEventListener('click', (e) => {
                    e.preventDefault();
                    this.downloadPdf(link);
                });
            }
        });
    }

    /**
     * The charts on screen as PNG files (one per pane when compared), named after the report and its drill path.
     */
    downloadPng() {
        const name = [this.config.reportname].concat(this.state.path.map((p) => p.label)).join(' - ')
            .replace(/[\\/:*?"<>|]+/g, '_').slice(0, 120);
        this.charts.forEach((chart, i) => {
            const link = document.createElement('a');
            link.href = chart.getDataURL({type: 'png', pixelRatio: 2, backgroundColor: '#fff'});
            link.download = `${name}${i ? ` (${i + 1})` : ''}.png`;
            document.body.appendChild(link);
            link.click();
            link.remove();
        });
    }
    /**
     * PDF download like the print: a form post with the address of the link (level, search, filters, period...),
     * the charts as drawn on the page (PNG) and the KPI cards.
     *
     * @param {HTMLAnchorElement} link
     */
    downloadPdf(link) {
        const url = new URL(link.getAttribute('href'), window.location.href);
        const charts = this.view() === 'table' ? [] : this.charts.map((chart) => {
            try {
                return chart.getDataURL({type: 'png', pixelRatio: 2, backgroundColor: '#fff'});
            } catch (e) {
                return null;
            }
        }).filter(Boolean);
        const kpis = [...this.root.querySelectorAll('[data-region="kpi"]')].map((card) => ({
            label: (card.querySelector('.reportium-kpi-label') || {}).textContent || '',
            value: ((card.querySelector('.reportium-kpi-value') || {}).textContent || '').replace(/\s+/g, ' ').trim(),
            sub: (card.querySelector('.reportium-kpi-sub') || {}).textContent || '',
        }));
        const form = document.createElement('form');
        form.method = 'post';
        form.action = url.origin + url.pathname;
        form.hidden = true;
        const add = (name, value) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        };
        url.searchParams.forEach((value, name) => add(name, value));
        add('sesskey', M.cfg.sesskey);
        add('rcharts', JSON.stringify(charts));
        add('rkpis', JSON.stringify(kpis));
        document.body.appendChild(form);
        form.submit();
        form.remove();
    }

    /**
     * Breadcrumbs: report name, then one crumb per chosen value.
     */
    renderBreadcrumbs() {
        const region = this.root.querySelector('[data-region="breadcrumbs"]');
        // Only shown once you have drilled at least one level; the first crumb is a home icon (back to the root).
        if (!this.state.path.length) {
            region.innerHTML = '';
            region.hidden = true;
            return;
        }
        region.hidden = false;
        const home = `<button type="button" class="reportium-crumb reportium-crumb-home" data-action="crumb" data-depth="0"
            title="${esc(this.config.reportname)}" aria-label="${esc(this.config.reportname)}">
            <i class="fa fa-home" aria-hidden="true"></i></button>`;
        const crumbs = this.state.path.map((p, i) => {
            const current = i === this.state.path.length - 1;
            return current
                ? `<span class="reportium-crumb is-current" data-region="crumb-current">${esc(p.label)}</span>`
                : `<button type="button" class="reportium-crumb" data-action="crumb" data-depth="${i + 1}">
                    ${esc(p.label)}</button>`;
        });
        region.innerHTML = [home].concat(crumbs).join('<span class="reportium-crumb-sep">/</span>');
    }

    /**
     * View switcher, search, compare and freshness (the row counters sit above each table).
     */
    renderToolbar() {
        const view = this.view();
        const views = this.allowedViews().map((v) => `<button type="button"
            class="reportium-view-btn${v === view ? ' is-active' : ''}" data-action="view" data-view="${v}"
            aria-pressed="${v === view}">${esc(this.str['ui_' + v])}</button>`).join('');
        const fresh = this.payload.freshnessminutes > 0 && this.payload.cached
            ? this.str.ui_cached.replace('{$a}', this.payload.freshnessminutes) : this.str.ui_live;
        const toolbar = this.root.querySelector('[data-region="toolbar"]');
        toolbar.innerHTML = `
            <div class="reportium-views" role="group">${views}</div>
            <input type="search" class="form-control reportium-search" data-region="search"
                placeholder="${esc(this.str.ui_search)}" value="${esc(this.state.search)}">
            ${this.periodHtml()}
            ${this.compareSelectHtml()}
            <span class="reportium-toolbar-end">${this.slowPillHtml()}
            ${this.config.showfreshness === false ? '' : `<span class="reportium-ui-view-freshness reportium-freshness">
                <span class="reportium-ui-toolbar-pill reportium-ui-toolbar-pill-status">
                    <span class="reportium-ui-toolbar-pill-status-value" data-region="freshness">${esc(fresh)}
                        <button type="button" class="reportium-ui-help-trigger" data-action="freshness-help"
                            aria-label="${esc(this.str.ui_freshness_explain)}" aria-expanded="false">i</button></span>
                    <span class="reportium-ui-help-popover" data-region="freshness-help" hidden>
                        <strong>${esc(this.str.ui_freshness_title)}</strong>
                        ${esc(this.str.ui_freshness_live)}<br><br>${esc(this.str.ui_freshness_cached)}</span>
                </span>
            </span>`}</span>`;
    }

    /**
     * Period of the data (reports whose SQL uses %%STARTTIME%% / %%ENDTIME%%): presets, custom dates, and
     * "Compare with the previous period".
     *
     * @returns {String}
     */
    periodHtml() {
        if (!this.usesdates) {
            return '';
        }
        const range = this.state.range;
        const options = (this.config.ranges || []).map((o) => `<option value="${esc(o.value)}"
            ${o.value === (range.preset || '') ? ' selected' : ''}>${esc(o.label)}</option>`).join('');
        const day = (ts) => {
            if (!ts) {
                return '';
            }
            const d = new Date(ts * 1000);
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        };
        const custom = range.preset === 'custom' ? `
            <input type="date" class="form-control form-control-sm" data-region="range-from" value="${day(range.from)}"
                aria-label="${esc(this.str.ui_rangefrom)}" title="${esc(this.str.ui_rangefrom)}">
            <input type="date" class="form-control form-control-sm" data-region="range-to" value="${day(range.to)}"
                aria-label="${esc(this.str.ui_rangeto)}" title="${esc(this.str.ui_rangeto)}">` : '';
        const dates = this.payload.rangelabel && range.preset !== 'custom'
            ? `<span class="reportium-period-dates" data-region="range-label">${esc(this.payload.rangelabel)}</span>` : '';
        const compare = range.preset ? `<label class="reportium-period-compare">
            <input type="checkbox" data-region="range-prev"${this.state.prev ? ' checked' : ''}>
            ${esc(this.str.ui_comparewithprevious)}</label>` : '';
        return `<span class="reportium-period" data-region="period">
            <i class="fa fa-calendar" aria-hidden="true"></i>
            <select class="form-control form-control-sm" data-region="range" aria-label="${esc(this.str.ui_period)}">
                ${options}</select>${custom}${dates}${compare}</span>`;
    }

    /**
     * "Compare with…" selector (siblings of the current item), only below the root.
     *
     * @returns {String}
     */
    compareSelectHtml() {
        const options = this.siblingOptions();
        if (!options.length) {
            return '';
        }
        const chosen = this.state.compare ? this.state.compare.value : '';
        const items = options.map((o) => `<option value="${esc(o.value)}"${o.value === chosen ? ' selected' : ''}>
            ${esc(o.label)}</option>`).join('');
        const clear = this.state.compare ? `<button type="button" class="btn btn-sm btn-outline-primary"
            data-action="clear-compare">${esc(this.str.ui_clear)}</button>` : '';
        return `<select class="form-control form-control-sm reportium-compare-select" data-region="compare"
            aria-label="${esc(this.str.ui_compare)}">
            <option value="">${esc(this.str.ui_compare)}</option>${items}</select>${clear}`;
    }

    /**
     * SQL Inspector: a drawer on the right edge with one pill per level, "+ Add level",
     * and the editor of the selected level. Only for SQL managers.
     */
    renderSql() {
        const region = this.root.querySelector('[data-region="sql"]');
        if (!this.canmanagesql) {
            region.innerHTML = '';
            return;
        }
        const deepest = this.levels.reduce((max, l) => Math.max(max, l.level), 0);
        const selected = this.sqlSelected === null ? this.level : this.sqlSelected;
        // No level saved yet (new report): level 1 is a new, unsaved editor.
        const isnew = !this.levels.length || selected > deepest;
        const current = this.levels.find((l) => l.level === selected) || {sqlquery: '', label: ''};
        const levelname = (level) => esc(this.str.ui_sqltitle.replace('{$a}', level + 1));
        const pill = (level, extra) => `<button type="button" class="reportium-sql-pill${extra}" data-action="sql-select"
            data-level="${level}">${levelname(level)}</button>`;
        const pills = this.levels.map((l) => pill(l.level, l.level === selected ? ' is-active' : ''))
            .concat(isnew ? [pill(selected, ' is-active is-new')] : [])
            .join('');
        const lastsaved = this.levels.find((l) => l.level === deepest);
        const canadd = !isnew && lastsaved && lastsaved.sqlquery.trim() !== '';
        const badge = isnew ? this.str.ui_sqlunsaved : this.str.ui_sqlsavedbadge;
        const title = this.str.ui_sqltitle.replace('{$a}', selected + 1);
        const badgeclass = 'reportium-sql-badge' + (isnew ? ' is-unsaved' : '');
        const savebutton = `<button type="button" class="btn btn-sm btn-primary" data-action="save-sql">
            ${esc(this.str.ui_sqlsave)}</button>`;
        const deletebutton = selected === deepest && selected > 0 && !isnew
            ? `<button type="button" class="btn btn-sm btn-outline-danger" data-action="delete-sql">
                ${esc(this.str.ui_sqldelete)}</button>` : '';
        const closeicon = '<i class="fa fa-xmark fa-times fa-fw" aria-hidden="true"></i>';
        // Vertical tab on the right edge, drawer on the right.
        region.innerHTML = `
            <aside class="reportium-sql-drawer${this.sqlOpen ? ' is-open' : ''}" id="${this.root.id}-sqldrawer"
                data-region="sql-drawer" aria-label="${esc(this.str.ui_sqlinspector)}">
                <div class="reportium-sql-drawer-header">
                    <button type="button" class="btn reportium-sql-close" data-action="toggle-sql"
                        aria-label="${esc(this.str.ui_close)}">${closeicon}</button>
                    <span class="reportium-sql-drawer-title"><i class="fa fa-code" aria-hidden="true"></i>
                        ${esc(this.str.ui_sqlinspector)}</span>
                </div>
                <div class="reportium-sql-drawer-body">
                    <div class="reportium-sql-pills">${pills}
                        ${canadd ? `<button type="button" class="reportium-sql-pill reportium-sql-pill-add" data-action="sql-add">
                            + ${esc(this.str.ui_sqladdshort)}</button>` : ''}</div>
                    <div class="reportium-sql-editor" data-sql-level="${selected}">
                        <div class="reportium-sql-heading">${esc(title)}
                            <span class="${badgeclass}" data-region="sql-badge">${esc(badge)}</span>
                        </div>
                        ${selected > 0 ? `<div class="reportium-sql-hint" title="${esc(this.str.ui_sqlhelp)}">
                            <code>%%PREVIOUSVALUE%%</code> ${esc(this.str.ui_sqlprevhint)}</div>` : ''}
                        <div class="reportium-sql-hint" data-region="sql-note-mycourses"
                            ${current.sqlquery.includes('%%MYCOURSES%%') ? '' : 'hidden'}>
                            <code>%%MYCOURSES%%</code> ${esc(this.str.ui_sqlnote_mycourses)}</div>
                        ${this.slowHtml(selected)}
                        <textarea class="reportium-sql-text" spellcheck="false" aria-label="${esc(title)}"
                            data-region="sql-text">${esc(current.sqlquery)}</textarea>
                        <input type="hidden" data-region="sql-label" value="${esc(current.label)}">
                        <div class="reportium-sql-actions">
                            ${savebutton}
                            ${deletebutton}
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-action="sql-history"
                                aria-expanded="${this.historyLevel === selected}">
                                <i class="fa fa-clock-rotate-left fa-history" aria-hidden="true"></i>
                                ${esc(this.str.ui_history)}</button>
                            <span class="reportium-sql-status" data-region="sql-status" role="status"></span>
                        </div>
                        <div class="reportium-sql-history" data-region="sql-history"
                            ${this.historyLevel === selected ? '' : 'hidden'}>${this.historyHtml(selected, isnew)}</div>
                    </div>
                </div>
            </aside>`;
        if (this.config.assistant) {
            // An assistant offered by another plugin (Reportium Pro), loaded once and kept across re-renders.
            const body = region.querySelector('.reportium-sql-drawer-body');
            if (this.assistant) {
                body.appendChild(this.assistant.element);
            } else if (!this.assistantLoading) {
                this.assistantLoading = true;
                window.require([this.config.assistant.module], (module) => {
                    const Assistant = module.default || module;
                    this.assistant = new Assistant(this.config.assistant.config, (sql) => this.useChatSql(sql),
                        () => (this.sqlSelected === null ? this.level : this.sqlSelected));
                    const drawer = this.root.querySelector('[data-region="sql"] .reportium-sql-drawer-body');
                    if (drawer) {
                        drawer.appendChild(this.assistant.element);
                    }
                });
            }
        } else if (this.config.aihelp) {
            // Instructions to paste into an AI chat outside Moodle.
            if (!this.aihelp) {
                this.aihelp = new AiHelp(this.config.aihelp, () => (this.sqlSelected === null ? this.level : this.sqlSelected));
            }
            region.querySelector('.reportium-sql-drawer-body').appendChild(this.aihelp.element);
        }
    }

    /**
     * Slow query pill of the toolbar, next to "Live data": how long the level took; its popover lists the tips
     * (only sent to those who can change the SQL, when the level was slow).
     *
     * @returns {String}
     */
    slowPillHtml() {
        const payload = this.payload || {};
        if (!payload.slowtips || !payload.slowtips.length) {
            return '';
        }
        const seconds = ((payload.executionms || 0) / 1000).toFixed(1);
        const tips = payload.slowtips.map((tip) => `<li>${esc(tip)}</li>`).join('');
        return `<span class="reportium-slowpill" data-region="slow-pill">
            <button type="button" class="reportium-ui-toolbar-pill reportium-slowpill-button" data-action="slow-help"
                aria-expanded="false"><i class="fa fa-gauge-high fa-tachometer" aria-hidden="true"></i>
                ${esc(this.str.ui_slowbadge)} · ${esc(seconds)} s</button>
            <span class="reportium-ui-help-popover reportium-slowpill-popover" data-region="slow-help" hidden>
                <strong>${esc(this.str.ui_slowquery.replace('{$a}', seconds))}</strong><br>
                ${esc(this.str.ui_slowtips)}<ul class="mb-0">${tips}</ul></span>
        </span>`;
    }

    /**
     * Slow query warning of the level shown in the SQL Inspector: how long it took and how to make it faster
     * (the server only sends tips to those who can change the SQL, when the level was slow).
     *
     * @param {Number} level
     * @returns {String}
     */
    slowHtml(level) {
        const payload = this.payload || {};
        if (level !== this.level || !payload.slowtips || !payload.slowtips.length) {
            return '';
        }
        const seconds = ((payload.executionms || 0) / 1000).toFixed(1);
        const tips = payload.slowtips.map((tip) => `<li>${esc(tip)}</li>`).join('');
        return `<div class="alert alert-warning reportium-slow" data-region="sql-slow" role="status">
            <strong><i class="fa fa-gauge-high fa-tachometer" aria-hidden="true"></i>
            ${esc(this.str.ui_slowquery.replace('{$a}', seconds))}</strong>
            <div class="mt-1">${esc(this.str.ui_slowtips)}</div><ul class="mb-0">${tips}</ul></div>`;
    }

    /**
     * Versions of the SQL of a level (newest first): when, who, what; load one into the editor, restore it, or see
     * what changed compared with the editor.
     *
     * @param {Number} level
     * @param {Boolean} isnew the level has no saved SQL (e.g. it was deleted: its versions can be restored)
     * @returns {String}
     */
    historyHtml(level, isnew) {
        if (this.historyLevel !== level || !this.history) {
            return '';
        }
        if (!this.history.length) {
            return `<div class="text-muted small" data-region="history-empty">${esc(this.str.ui_historyempty)}</div>`;
        }
        const when = (ts) => formatDate(String(ts), true);
        const items = this.history.map((v, i) => {
            const restorable = !isnew || v.action === 'deleted' || i === 0;
            return `<li class="reportium-history-item" data-region="history-item" data-id="${v.id}">
                <div class="reportium-history-meta">
                    <span class="badge reportium-history-${esc(v.action)}">
                        ${esc(this.str['ui_historyaction_' + v.action] || v.action)}</span>
                    <span>${esc(when(v.timecreated))}</span>
                    <span class="text-muted">${esc(v.userfullname)}</span>
                </div>
                <div class="reportium-history-buttons">
                    <button type="button" class="btn btn-link btn-sm p-0" data-action="history-diff" data-id="${v.id}">
                        ${esc(this.str.ui_historychanges)}</button>
                    ${isnew ? '' : `<button type="button" class="btn btn-link btn-sm p-0" data-action="history-use"
                        data-id="${v.id}">
                        ${esc(this.str.ui_historyuse)}</button>`}
                    ${restorable ? `<button type="button" class="btn btn-outline-primary btn-sm" data-action="history-restore"
                        data-id="${v.id}">${esc(this.str.ui_historyrestore)}</button>` : ''}
                </div>
                <pre class="reportium-history-diff" data-region="history-diff-${v.id}" hidden></pre>
            </li>`;
        }).join('');
        const hint = isnew ? `<div class="text-muted small mb-1">${esc(this.str.ui_historydeletedhint)}</div>` : '';
        return `${hint}<ol class="reportium-history list-unstyled mb-0">${items}</ol>`;
    }

    /**
     * Open or close the history of the level shown in the SQL Inspector.
     *
     * @param {Number} level
     * @returns {Promise}
     */
    toggleHistory(level) {
        if (this.historyLevel === level) {
            this.historyLevel = null;
            this.renderSql();
            return Promise.resolve();
        }
        return Ajax.call([{
            methodname: 'local_reportium_get_level_history',
            args: {reportid: this.config.reportid, level: level},
        }])[0].then((data) => {
            this.history = data.versions;
            this.historyLevel = level;
            this.renderSql();
            return data;
        }).catch(Notification.exception);
    }

    /**
     * Restore a version (a deleted level comes back as the deepest level), then reload everything.
     *
     * @param {Number} versionid
     */
    restoreVersion(versionid) {
        Ajax.call([{
            methodname: 'local_reportium_restore_level_version',
            args: {reportid: this.config.reportid, versionid: versionid},
        }])[0].then((result) => {
            const status = this.root.querySelector('[data-region="sql-status"]');
            if (!result.success) {
                if (status) {
                    status.className = 'reportium-sql-status text-danger';
                    status.textContent = result.error;
                }
                return null;
            }
            this.payloads = {};
            this.historyLevel = null;
            this.sqlSelected = result.level;
            return this.loadLevels().then(() => this.load()).then(() => {
                this.sqlSelected = result.level;
                this.renderSql();
                const node = this.root.querySelector('[data-region="sql-status"]');
                if (node) {
                    node.className = 'reportium-sql-status text-success';
                    node.textContent = this.str.ui_historyrestored;
                }
                return null;
            });
        }).catch(Notification.exception);
    }

    /**
     * Show or hide what changed between a version and the SQL in the editor (lines removed - / added +).
     *
     * @param {Number} versionid
     */
    toggleDiff(versionid) {
        const node = this.root.querySelector(`[data-region="history-diff-${versionid}"]`);
        const version = (this.history || []).find((v) => v.id === versionid);
        if (!node || !version) {
            return;
        }
        if (!node.hidden) {
            node.hidden = true;
            return;
        }
        const text = this.root.querySelector('[data-region="sql-text"]');
        node.innerHTML = lineDiff(version.sqlquery, text ? text.value : '').map(([kind, line]) =>
            `<span class="reportium-diff-${kind}">${kind === 'del' ? '-' : kind === 'add' ? '+' : ' '} ${esc(line)}</span>`)
            .join('\n');
        node.hidden = false;
    }

    /**
     * Put SQL suggested by the AI assistant into the editor of the selected level (not saved yet).
     *
     * @param {String} sql
     */
    useChatSql(sql) {
        const text = this.root.querySelector('[data-region="sql-text"]');
        const badge = this.root.querySelector('[data-region="sql-badge"]');
        text.value = sql;
        badge.textContent = this.str.ui_sqlunsaved;
        badge.classList.add('is-unsaved');
        text.focus();
        text.scrollIntoView({block: 'nearest'});
    }

    /**
     * Validate and save the SQL of one level, then reload everything from the server.
     *
     * @param {HTMLElement} editor the .reportium-sql-editor element
     */
    saveSql(editor) {
        const status = editor.querySelector('[data-region="sql-status"]');
        status.className = 'reportium-sql-status';
        status.textContent = this.str.ui_sqlsaving;
        Ajax.call([{
            methodname: 'local_reportium_save_level',
            args: {
                reportid: this.config.reportid,
                level: parseInt(editor.dataset.sqlLevel, 10),
                sqlquery: editor.querySelector('[data-region="sql-text"]').value,
                label: editor.querySelector('[data-region="sql-label"]').value,
            },
        }])[0].then((result) => {
            if (!result.success) {
                status.className = 'reportium-sql-status text-danger';
                status.textContent = result.error;
                return null;
            }
            this.payloads = {};
            return this.loadLevels().then(() => this.load()).then(() => {
                const node = this.root.querySelector('[data-region="sql-status"]');
                if (node) {
                    node.className = 'reportium-sql-status text-success';
                    node.textContent = this.str.ui_sqlsaved;
                }
                return null;
            });
        }).catch(Notification.exception);
    }

    /**
     * Delete the deepest level after confirmation.
     *
     * @param {HTMLElement} editor
     */
    deleteSql(editor) {
        const level = parseInt(editor.dataset.sqlLevel, 10);
        const label = (this.levels.find((l) => l.level === level) || {}).label || this.str.ui_sqltitle.replace('{$a}', level + 1);
        const question = this.str.deletelevel_confirm.replace('{$a}', label);
        const confirmed = typeof Notification.deleteCancelPromise === 'function'
            ? Notification.deleteCancelPromise(this.str.deletelevel_title, question)
            : (window.confirm(question) ? Promise.resolve() : Promise.reject(new Error('cancelled')));
        confirmed.then(() => Ajax.call([{
            methodname: 'local_reportium_delete_level',
            args: {reportid: this.config.reportid, level: level},
        }])[0]).then(() => {
            this.payloads = {};
            // Only leave the current level if it (or a parent of it) no longer exists.
            if (this.level >= level) {
                this.state.path = this.state.path.slice(0, Math.max(0, level - 1));
                this.resetLevelState();
            }
            return this.loadLevels().then(() => this.load());
        }).catch((error) => {
            // Cancelling the dialog rejects without an error object worth reporting.
            if (error && error.message !== 'cancelled' && error.errorcode) {
                Notification.exception(error);
            }
        });
    }

    /**
     * Filter control of one column, shared by the Filters panel and the column funnel popover:
     * a list of values for text (or "contains" when there are too many values), min/max for numbers and dates.
     *
     * @param {Object} column
     * @param {Boolean} popover true for the column funnel popover (always returns a control)
     * @returns {String} '' when the Filters panel should not show this column (a single value)
     */
    filterControl(column, popover = false) {
        const filter = this.state.filters[column.key] || {};
        if (column.type === 'text') {
            const values = [...new Set(this.payload.rows.map((r) => String(r[column.key] ?? '')).filter((v) => v !== ''))];
            const islabel = column.key === String(this.mapping().labelcolumn || '').split('|')[0];
            const toomany = values.length > MAX_SELECT_VALUES || (!popover && islabel && values.length > 20);
            // Too many values for a list (users, emails...): "contains", in the Filters panel too.
            if (toomany || (popover && values.length < 2)) {
                return `<input type="search" class="form-control form-control-sm" data-filter="${esc(column.key)}"
                    data-filter-type="like" placeholder="${esc(this.str.ui_contains)}"
                    aria-label="${esc(column.label + ' ' + this.str.ui_contains)}" value="${esc(filter.like || '')}">`;
            }
            if (values.length < 2 || toomany) {
                return '';
            }
            const chosen = filter.in || [];
            const options = values.sort().map((v) => `<option value="${esc(v)}"${chosen.includes(v) ? ' selected' : ''}>
                ${esc(v)}</option>`).join('');
            const size = popover ? Math.min(8, Math.max(3, values.length)) : 3;
            return `<select multiple size="${size}" class="form-control form-control-sm" data-filter="${esc(column.key)}"
                data-filter-type="in" aria-label="${esc(column.label)}">${options}</select>`;
        }
        const isdate = column.type === 'date';
        const type = isdate ? 'date' : 'number';
        const toInput = (v) => (isdate && v ? new Date(v * 1000).toISOString().slice(0, 10) : (v ?? ''));
        const lo = isdate ? this.str.ui_from : this.str.ui_min;
        const hi = isdate ? this.str.ui_to : this.str.ui_max;
        return `<input type="${type}" class="form-control form-control-sm" aria-label="${esc(column.label + ' ' + lo)}"
                placeholder="${esc(lo)}" data-filter="${esc(column.key)}" data-filter-type="min" data-date="${isdate ? 1 : 0}"
                value="${esc(toInput(filter.min))}">
            <input type="${type}" class="form-control form-control-sm" aria-label="${esc(column.label + ' ' + hi)}"
                placeholder="${esc(hi)}" data-filter="${esc(column.key)}" data-filter-type="max" data-date="${isdate ? 1 : 0}"
                value="${esc(toInput(filter.max))}">`;
    }

    /**
     * Popover of the column funnel (table header): the filter of that column, under its funnel.
     * It lives outside the table (whose scroll container would clip it) and is re-opened after every render.
     */
    renderColumnFilter() {
        const old = this.root.querySelector('[data-region="col-filter-popover"]');
        if (old) {
            old.remove();
        }
        const button = this.openFilter
            ? this.root.querySelector(`[data-region="table"] [data-action="col-filter"][data-key="${CSS.escape(this.openFilter)}"]`)
            : null;
        const column = button ? this.payload.columns.find((c) => c.key === this.openFilter) : null;
        if (!column) {
            this.openFilter = null;
            return;
        }
        const popover = document.createElement('div');
        popover.className = 'reportium-col-filter';
        popover.setAttribute('data-region', 'col-filter-popover');
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-label', this.str.ui_filtercolumn.replace('{$a}', column.label));
        const active = !!this.state.filters[column.key];
        popover.innerHTML = `<div class="reportium-col-filter-title"><i class="fa fa-filter" aria-hidden="true"></i>
                ${esc(column.label)}</div>
            <div class="reportium-col-filter-body">${this.filterControl(column, true)}</div>
            ${active ? `<button type="button" class="btn btn-link btn-sm p-0" data-action="clear-col-filter"
                data-key="${esc(column.key)}">${esc(this.str.ui_clear)}</button>` : ''}`;
        this.root.appendChild(popover);
        const rootbox = this.root.getBoundingClientRect();
        const box = button.getBoundingClientRect();
        const left = Math.min(box.left - rootbox.left, Math.max(0, rootbox.width - popover.offsetWidth));
        popover.style.left = `${Math.max(0, left)}px`;
        popover.style.top = `${box.bottom - rootbox.top + 4}px`;
        const field = popover.querySelector('[data-filter]');
        if (field && this.focusFilter) {
            field.focus();
        }
        this.focusFilter = false;
    }

    /**
     * Automatic filters built from the column types of this level.
     */
    renderFilters() {
        const region = this.root.querySelector('[data-region="filters"]');
        const parts = this.payload.columns.filter((c) => c.type !== 'id').map((column) => {
            const control = this.filterControl(column);
            if (control === '') {
                return '';
            }
            return column.type === 'text'
                ? `<label class="reportium-filter">${esc(column.label)}${control}</label>`
                : `<fieldset class="reportium-filter"><legend>${esc(column.label)}</legend>${control}</fieldset>`;
        }).filter(Boolean);
        const active = Object.keys(this.state.filters).length;
        this.hasFilters = parts.length > 0;
        region.innerHTML = parts.length ? this.drawerHtml('filters', 'fa-filter', this.str.ui_filters, `
            <div class="reportium-filter-grid reportium-filter-stack">${parts.join('')}</div>
            ${active ? `<button type="button" class="btn btn-link btn-sm px-0" data-action="clear-filters">
                ${esc(this.str.ui_clearfilters)}</button>` : ''}`) : '';
    }

    /**
     * A drawer on the right edge (same look as the SQL Inspector), opened from its side tab.
     *
     * @param {String} panel filters | settings
     * @param {String} icon Font Awesome class
     * @param {String} title
     * @param {String} body HTML
     * @returns {String}
     */
    drawerHtml(panel, icon, title, body) {
        const open = this.openPanel === panel;
        return `<aside class="reportium-sql-drawer reportium-side-drawer${open ? ' is-open' : ''}" id="${this.root.id}-${panel}"
                data-region="${panel}-drawer" aria-label="${esc(title)}">
            <div class="reportium-sql-drawer-header">
                <button type="button" class="btn reportium-sql-close" data-action="toggle-panel" data-panel="${panel}"
                    aria-label="${esc(this.str.ui_close)}"><i class="fa fa-xmark fa-times fa-fw" aria-hidden="true"></i></button>
                <span class="reportium-sql-drawer-title"><i class="fa ${icon}" aria-hidden="true"></i> ${esc(title)}</span>
            </div>
            <div class="reportium-sql-drawer-body">${body}</div>
        </aside>`;
    }

    /**
     * Side tabs on the right edge: Filters (funnel), Chart settings (gear + chart) and SQL (code), stacked.
     */
    renderSideTabs() {
        let region = this.root.querySelector('[data-region="sidetabs"]');
        if (!region) {
            region = document.createElement('div');
            region.className = 'd-print-none';
            region.setAttribute('data-region', 'sidetabs');
            this.root.appendChild(region);
        }
        const active = Object.keys(this.state.filters).length;
        const tab = (panel, icons, label, extra = '') => `<button type="button"
            class="btn btn-sm ${this.openPanel === panel ? 'btn-primary' : 'btn-outline-primary'} reportium-side-tab"
            data-action="toggle-panel" data-panel="${panel}" title="${esc(label)}" aria-expanded="${this.openPanel === panel}"
            aria-controls="${this.root.id}-${panel === 'sql' ? 'sqldrawer' : panel}">${icons} ${esc(label)}${extra}</button>`;
        const tabs = [];
        if (this.hasFilters) {
            tabs.push(tab('filters', '<i class="fa fa-filter" aria-hidden="true"></i>', this.str.ui_filters,
                active ? ` <span class="badge badge-pill badge-light bg-light text-dark">${active}</span>` : ''));
        }
        if (this.payload && this.payload.columns && this.payload.columns.length) {
            tabs.push(tab('settings', '<i class="fa fa-cog" aria-hidden="true"></i><i class="fa fa-bar-chart fa-chart-bar"'
                + ' aria-hidden="true"></i>', this.str.ui_chartsettings));
        }
        if (this.canmanagesql) {
            tabs.push(tab('sql', '<i class="fa fa-code" aria-hidden="true"></i>', this.str.ui_sql));
        }
        region.innerHTML = `<div class="reportium-side-tabs${this.openPanel ? ' is-open' : ''}">${tabs.join('')}</div>`;
    }

    /**
     * Open one side panel (or close it if it is the open one); only one drawer is open at a time.
     *
     * @param {String} panel filters | settings | sql
     */
    togglePanel(panel) {
        this.openPanel = this.openPanel === panel ? null : panel;
        this.renderFilters();
        this.renderSettings();
        this.renderSql();
        this.renderSideTabs();
    }

    /**
     * Chart settings panel: automatic mapping made visible and editable.
     */
    renderSettings() {
        const region = this.root.querySelector('[data-region="settings"]');
        const map = this.mapping();
        const auto = this.payload.mapping;
        const cols = this.payload.columns;
        // X axis: each text/date/id column alone, plus "column (other column)" combinations such as
        // "Fullname (Id)" or "Fullname (Email)", to tell apart items with the same name.
        const labelcols = cols.filter((c) => c.type !== 'number');
        const textcols = labelcols.filter((c) => c.type === 'text' || c.type === 'date');
        const labelchoices = labelcols.map((c) => ({value: c.key, text: c.label}));
        textcols.forEach((c) => {
            labelcols.filter((o) => o.key !== c.key).forEach((o) => {
                labelchoices.push({value: `${c.key}|${o.key}`, text: `${c.label} (${o.label})`});
            });
        });
        const labelopts = labelchoices.map((o) => `<option value="${esc(o.value)}"${o.value === map.labelcolumn ? ' selected' : ''}>
            ${esc(o.text)}</option>`).join('');
        // The panel follows the chart on screen (the toolbar switcher is the chart type): a table has no
        // X axis or metrics, a pie shows a single metric, and only bars can stack.
        const current = this.view();
        const ischart = current !== 'table';
        const numeric = cols.filter((c) => c.type === 'number').map((c) => c.key);
        let chosenmetrics = (map.metrics || []).filter((m) => numeric.includes(m));
        if (current === 'pie') {
            chosenmetrics = chosenmetrics.slice(0, 1);
        }
        const inputtype = current === 'pie' ? 'radio' : 'checkbox';
        const automarker = ` <small>(${esc(this.str.ui_auto)})</small>`;
        const metrics = cols.filter((c) => c.type === 'number').map((c) => `<label class="reportium-check">
            <input type="${inputtype}" name="reportium-metric-${this.config.reportid}" data-setting="metric"
                value="${esc(c.key)}"${chosenmetrics.includes(c.key) ? ' checked' : ''}>
            ${esc(c.label)}${(auto.metrics || []).includes(c.key) ? automarker : ''}</label>`).join('');
        const select = (setting, label, options) => `<label>${esc(label)}
            <select class="form-control form-control-sm" data-setting="${setting}">${options}</select></label>`;
        const axes = ischart ? `${select('labelcolumn', current === 'pie' ? this.str.ui_slicelabel : this.str.ui_xaxis, labelopts)}
            <fieldset><legend>${esc(this.str.ui_metrics)}</legend>${metrics}</fieldset>` : '';
        const stack = current === 'bar' ? `<label class="reportium-check" data-region="stacked-field"${
            this.canStack(current, chosenmetrics) ? '' : ' hidden'}>
                <input type="checkbox" data-setting="stacked"${map.stacked ? ' checked' : ''}>
                ${esc(this.str.ui_stacked)}</label>` : '';
        // Column format (how the table shows each column): automatic from the keywords of instructions.json, or chosen.
        const formatname = (f) => this.str['ui_format_' + f] || f;
        const chosenformats = (this.state.mapping || {}).formats || (this.payload.mapping || {}).formats || {};
        const formats = cols.filter((c) => c.type !== 'id' && ((c.format && c.format !== 'text') || chosenformats[c.key]))
            .map((c) => {
            const autoname = this.str.ui_format_auto.replace('{$a}', formatname(c.format || 'text'));
            const auto = `<option value="">${esc(autoname)}</option>`;
            const options = (this.config.formats || ['text']).map((f) => `<option value="${esc(f)}"${
                chosenformats[c.key] === f ? ' selected' : ''}>${esc(formatname(f))}</option>`).join('');
            return `<label class="reportium-settings-row"><span>${esc(c.label)}</span>
                <select class="form-control form-control-sm" data-setting="format"
                data-key="${esc(c.key)}">${auto}${options}</select></label>`;
        }).join('');
        // Every section folded at first; the user's choice is remembered while the page is open.
        this.foldedSections = this.foldedSections || new Set(['chart', 'table', 'kpis', 'views']);
        const section = (id, icon, title, body) => (body ? `<details class="reportium-settings-section"
            data-section="${id}"${this.foldedSections.has(id) ? '' : ' open'}>
            <summary><i class="fa ${icon} fa-fw" aria-hidden="true"></i> ${esc(title)}</summary>
            <div class="reportium-settings-section-body">${body}</div></details>` : '');
        // Drawer on the right edge, opened from its side tab (gear + chart).
        region.innerHTML = this.drawerHtml('settings', 'fa-cog', this.str.ui_chartsettings, `
            <div class="reportium-settings" data-region="settings-panel" data-chart="${current}">
            <div class="reportium-settings-toolbar">
                <button type="button" class="btn btn-link btn-sm p-0" data-action="settings-toggle-all">
                    ${esc(['chart', 'table', 'kpis', 'views'].every((id) => this.foldedSections.has(id)) ? this.str.ui_expandall
                        : this.str.ui_collapseall)}</button>
            </div>
            ${section('chart', 'fa-chart-column fa-bar-chart', this.str.ui_settings_chart, ischart ? `${axes}${stack}` : '')}
            ${section('table', 'fa-table', this.str.ui_settings_table, `
                <label class="reportium-settings-row"><span>${esc(this.str.ui_pagesize)}</span>
                    <input type="number" min="1" class="form-control form-control-sm" data-setting="pagesize"
                    value="${map.pagesize || DEFAULT_PAGE_SIZE}"></label>
                ${(this.columnOrder[String(this.level)] || []).length ? `<button type="button" class="btn btn-link btn-sm p-0"
                    data-action="reset-column-order">${esc(this.str.ui_resetcolumnorder)}</button>` : ''}
                ${formats ? `<fieldset class="reportium-formats" data-region="formats-field">
                    <legend>${esc(this.str.ui_columnformats)}</legend>
                    <div class="small text-muted mb-1">${esc(this.str.ui_settings_formats_help)}</div>
                    ${formats}</fieldset>` : ''}`)}
            ${section('kpis', 'fa-gauge-high fa-tachometer', this.str.ui_settings_kpis, this.kpiSettingsHtml(map))}
            ${section('views', 'fa-eye', this.str.ui_settings_views, this.offeredViews().length > 1
                ? `<fieldset data-region="viewtypes-field">
                <legend class="sr-only visually-hidden">${esc(this.str.reportviews)}</legend>
                <div class="small text-muted mb-1">${esc(this.str.ui_settings_views_help)}</div>
                ${this.offeredViews().map((v) => `<label class="reportium-check"><input type="checkbox"
                    data-setting="viewtype" value="${v}"${this.allowedViews().includes(v) ? ' checked' : ''}>
                    ${esc(this.str['ui_' + v])}</label>`).join('')}
                </fieldset>` : '')}
            <div class="reportium-settings-actions">
                <button type="button" class="btn btn-sm btn-primary" data-action="apply-settings">${esc(this.str.ui_apply)}</button>
                ${this.config.canconfigure ? `<button type="button" class="btn btn-sm btn-outline-primary"
                    data-action="save-settings">
                    ${esc(this.str.ui_save)}</button>
                <button type="button" class="btn btn-sm btn-link" data-action="reset-settings">
                    ${esc(this.str.ui_reset)}</button>` : ''}
            </div></div>`);
    }

    /**
     * "Stack series" only makes sense for bars with two or more metrics (pie has one metric, table is no chart,
     * stacked lines read as bigger values than they are).
     *
     * @param {String} view
     * @param {Array} metrics chosen metric keys
     * @returns {Boolean}
     */
    canStack(view, metrics) {
        return view === 'bar' && metrics.length >= 2;
    }

    /**
     * Show or hide "Stack series" as metrics are ticked in the open settings panel.
     */
    syncStackedField() {
        const panel = this.root.querySelector('[data-region="settings-panel"]');
        const field = panel ? panel.querySelector('[data-region="stacked-field"]') : null;
        if (!field) {
            return;
        }
        const metrics = [...panel.querySelectorAll('[data-setting="metric"]:checked')];
        field.hidden = !this.canStack(this.view(), metrics);
    }

    /**
     * Read the settings panel into a mapping object.
     *
     * @returns {Object}
     */
    readSettings() {
        const panel = this.root.querySelector('[data-region="settings-panel"]');
        const get = (name) => panel.querySelector(`[data-setting="${name}"]`);
        // Fields the current view does not show keep their current value.
        const map = this.mapping();
        const current = this.view();
        // Shared by every chart of the level: X axis, sort, page size, formats, default chart type.
        const mapping = {
            labelcolumn: get('labelcolumn') ? get('labelcolumn').value : map.labelcolumn,
            pagesize: Math.max(1, parseInt(get('pagesize').value, 10) || DEFAULT_PAGE_SIZE),
            defaultview: current,
            views: Object.assign({}, map.views || {}),
            kpis: get('kpis') ? this.readKpiSettings(panel) : map.kpis,
            totalsrow: get('totalsrow') ? get('totalsrow').checked : this.totalsRowShown(),
        };
        // Own to the chart on screen: metrics and stacking (a pie with one metric leaves the bars untouched).
        if (current !== 'table') {
            mapping.views[current] = {
                metrics: get('metric') ? [...panel.querySelectorAll('[data-setting="metric"]:checked')].map((i) => i.value)
                    : (map.metrics || []),
                stacked: get('stacked') ? get('stacked').checked : !!map.stacked,
            };
        }
        if (get('valuecolumn')) {
            mapping.valuecolumn = get('valuecolumn').value;
        }
        // Only the formats chosen by hand; "Automatic" keeps the keyword detection.
        const formats = {};
        panel.querySelectorAll('[data-setting="format"]').forEach((select) => {
            if (select.value) {
                formats[select.dataset.key] = select.value;
            }
        });
        mapping.formats = formats;
        // Chart types of this level (a subset of the report's); all of them ticked = no restriction.
        const boxes = [...panel.querySelectorAll('[data-setting="viewtype"]')];
        if (boxes.length) {
            const chosen = boxes.filter((b) => b.checked).map((b) => b.value);
            mapping.viewtypes = chosen.length && chosen.length < boxes.length ? chosen : [];
            if (mapping.viewtypes.length && !mapping.viewtypes.includes(mapping.defaultview)) {
                mapping.defaultview = mapping.viewtypes[0];
            }
        }
        return mapping;
    }

    /**
     * Chart (bar/pie/line) or table, plus the table of the same rows under charts.
     *
     * @param {Array} rows
     */
    renderBody(rows) {
        const body = this.root.querySelector('[data-region="body"]');
        const view = this.view();
        const previous = this.comparingPrevious;
        const dated = (label, payload) => (payload.rangelabel ? `${label} (${payload.rangelabel})` : label);
        const mainlabel = this.title() || this.config.reportname;
        const panes = [{payload: this.payload, rows: rows, label: previous ? dated(mainlabel, this.payload) : mainlabel}];
        if ((this.state.compare || previous) && this.comparePayload) {
            panes.push({
                payload: this.comparePayload,
                rows: this.visibleRows(this.comparePayload),
                label: previous ? dated(this.str.ui_previousperiod, this.comparePayload) : this.state.compare.label,
            });
        }
        const comparing = panes.length > 1;
        // The page footer's "Total record count" follows what is on screen (level, search, filters).
        const totaltext = (count) => this.str.totalrecords.replace('{$a->totalrecords}', count);
        const timetext = (payload) => this.str.lastexecutiontime.replace('{$a}', (payload.executionms || 0) / 1000);
        // When comparing, each chart shows its own totals, so the page footer ones are hidden (they'd repeat the left one).
        const totalnode = document.getElementById('totalrecords');
        if (totalnode) {
            totalnode.textContent = totaltext(rows.length);
            totalnode.hidden = comparing;
        }
        const timenode = document.getElementById('lastexecutiontime');
        if (timenode) {
            timenode.textContent = timetext(this.payload);
            timenode.hidden = comparing;
        }
        if (!comparing && !rows.length) {
            this.disposeChart();
            body.innerHTML = `<div class="reportium-empty" data-region="empty">${esc(this.str.ui_nodata)}</div>`;
            return;
        }
        // Pagination: chart and table show the same page (big levels stay fast); totals count every row.
        const pagesize = Math.max(1, parseInt(this.mapping().pagesize, 10) || DEFAULT_PAGE_SIZE);
        const pagekey = JSON.stringify([this.state.path, this.state.search, this.state.filters, this.state.tsort,
            this.state.mapping, this.state.compare && this.state.compare.value, this.rangeArgs(), previous]);
        if (this.pagekey !== undefined && this.pagekey !== pagekey) {
            this.state.pages = [0, 0];
        }
        this.pagekey = pagekey;
        panes.forEach((pane, i) => {
            const pages = Math.max(1, Math.ceil(pane.rows.length / pagesize));
            const page = Math.min(Math.max(0, this.state.pages[i] || 0), pages - 1);
            this.state.pages[i] = page;
            pane.offset = page * pagesize;
            pane.pageRows = pane.rows.slice(pane.offset, pane.offset + pagesize);
            pane.pager = pages > 1 ? this.pagerHtml(i, page, pages, pane.offset, pane.pageRows.length, pane.rows.length) : '';
        });
        this.writeUrlState();
        const paneHtml = (pane, i) => {
            const chart = view === 'table' ? ''
                : `<div class="reportium-chart" data-region="${i ? 'chart-compare' : 'chart'}" data-pane="${i}"></div>`;
            const empty = `<div class="reportium-empty">${esc(this.str.ui_nodata)}</div>`;
            // When comparing, each side shows its own totals under its table (the page footer covers the left one).
            const stats = comparing ? `<div class="reportium-pane-stats">
                <div data-region="pane-total-${i}">${esc(totaltext(pane.rows.length))}</div>
                <div class="d-print-none" data-region="pane-time-${i}">${esc(timetext(pane.payload))}</div></div>` : '';
            const content = (pane.rows.length ? chart + this.tableHtml(pane.pageRows, pane.payload, i, pane.offset, pane.rows)
                + pane.pager
                : empty) + stats;
            return `<div class="reportium-pane" data-region="pane-${i}">
                ${comparing ? `<h4 class="reportium-pane-title"><span data-region="pane-title-${i}">${esc(pane.label)}</span>
                    ${i ? `<button type="button" class="btn btn-link reportium-pane-close" data-action="clear-compare"
                        aria-label="${esc(this.str.ui_closecompare)}" title="${esc(this.str.ui_closecompare)}">
                        <i class="fa fa-xmark fa-times" aria-hidden="true"></i></button>` : ''}</h4>` : ''}
                ${content}</div>`;
        };
        body.innerHTML = this.kpisHtml(rows, previous && this.comparePayload ? this.visibleRows(this.comparePayload) : null)
            + `<div class="${comparing ? 'reportium-compare' : ''}">${panes.map(paneHtml).join('')}</div>`;
        this.renderKpiPopover();
        this.renderCalcPopover();
        this.renderColumnFilter();
        if (view === 'table') {
            this.disposeChart();
            return;
        }
        loadEcharts(this.config.echartsurl).then((echarts) => {
            this.disposeChart();
            panes.forEach((pane, i) => {
                const node = body.querySelector(`[data-pane="${i}"].reportium-chart`);
                if (!node) {
                    return;
                }
                const chart = echarts.init(node, null, {renderer: 'canvas'});
                chart.setOption(this.chartOption(pane.pageRows, view, pane.payload, chart.getWidth()), true);
                chart.on('click', (params) => {
                    const row = pane.pageRows[params.dataIndex];
                    if (row) {
                        this.drill(row, i);
                    }
                });
                this.charts.push(chart);
            });
            this.root.dataset.chartReady = '1';
            return this.charts;
        }).catch(Notification.exception);
    }

    /**
     * Chart settings of the KPI cards: shown or not, the rows card, a calculation per column, and the totals row.
     *
     * @param {Object} map effective mapping
     * @returns {String}
     */
    kpiSettingsHtml(map) {
        const specs = this.kpiSpecs();
        const chosen = Object.fromEntries(specs.map((k) => [k.key, k.agg]));
        const options = (column) => {
            const aggs = column.type === 'number' ? ['sum', 'avg', 'min', 'max', 'count', 'distinct']
                : (column.type === 'date' ? ['min', 'max', 'count', 'distinct'] : ['count', 'distinct']);
            return [`<option value="">${esc(this.str.ui_kpi_none)}</option>`].concat(aggs.map((a) => `<option value="${a}"${
                chosen[column.key] === a ? ' selected' : ''}>${esc(this.aggregateName(a, column))}</option>`)).join('');
        };
        const columns = this.payload.columns.filter((c) => c.type !== 'id').map((c) => `<label class="reportium-settings-row">
            <span>${esc(c.label)}</span>
            <select class="form-control form-control-sm" data-setting="kpi" data-key="${esc(c.key)}">${options(c)}
            </select></label>`)
            .join('');
        return `<fieldset class="reportium-kpi-settings" data-region="kpi-settings">
            <legend class="sr-only visually-hidden">${esc(this.str.ui_kpis)}</legend>
            <label class="reportium-check"><input type="checkbox" data-setting="kpis"${map.kpis === false ? '' : ' checked'}>
                ${esc(this.str.ui_kpis)}</label>
            <label class="reportium-check"><input type="checkbox" data-setting="kpirows"${chosen._rows ? ' checked' : ''}>
                ${esc(this.str.ui_kpi_showrows)}</label>
            <div class="small text-muted mb-1">${esc(this.str.ui_kpi_help)}</div>
            <div class="reportium-settings-grid">${columns}</div>
            <label class="reportium-check"><input type="checkbox" data-setting="totalsrow"
                ${this.totalsRowShown() ? ' checked' : ''}>
                ${esc(this.str.ui_totalsrow)}</label>
        </fieldset>`;
    }

    /**
     * Calculations of the KPI cards and the totals row: the ones chosen in the chart settings (a list of
     * {key, agg}, "_rows" = number of rows), none when switched off, or automatic: the rows, and each metric of the
     * chart (up to 4), averaged for percentages and averages, summed otherwise.
     *
     * @param {Object} payload
     * @returns {Array} [{key, agg}]
     */
    kpiSpecs(payload = this.payload) {
        const map = this.mapping();
        const keys = payload.columns.map((c) => c.key);
        if (map.kpis === false) {
            return [];
        }
        if (Array.isArray(map.kpis)) {
            // Each card (column + calculation) once.
            const seen = new Set();
            return map.kpis.filter((k) => k.key === '_rows' || keys.includes(k.key)).filter((k) => {
                const id = k.key === '_rows' ? '_rows' : `${k.key}:${k.agg}`;
                return seen.has(id) ? false : seen.add(id);
            });
        }
        // Defaults of the report (Edit page).
        const defaults = Object.assign({cards: false, rows: false}, this.config.kpidefaults || {});
        if (!defaults.cards) {
            return [];
        }
        const specs = defaults.rows ? [{key: '_rows', agg: 'count'}] : [];
        payload.columns.filter((c) => c.type === 'number' && (map.metrics || []).includes(c.key)).slice(0, 4).forEach((c) => {
            specs.push({key: c.key, agg: this.defaultAggregate(c, payload)});
        });
        return specs;
    }

    /**
     * The usual calculation of a column: the average for percentages and averages, the sum for other numbers, the
     * number of distinct values for text and ids, the latest one for dates.
     *
     * @param {Object} column
     * @param {Object} payload
     * @returns {String}
     */
    defaultAggregate(column, payload = this.payload) {
        if (column.type === 'date') {
            return 'max';
        }
        if (column.type !== 'number') {
            return 'distinct';
        }
        return this.formatOf(column, payload) === 'progress'
            || /(pct|percent|avg|average|mean|rate|ratio|porcentaje|promedio|media)/i.test(column.key) ? 'avg' : 'sum';
    }

    /**
     * One calculation over rows.
     *
     * @param {Array} rows
     * @param {String} key column ("_rows" = the rows)
     * @param {String} agg sum | avg | min | max | count | distinct
     * @returns {Number}
     */
    aggregate(rows, key, agg) {
        if (key === '_rows') {
            return rows.length;
        }
        const raw = rows.map((r) => r[key]).filter((v) => v !== undefined && v !== null && String(v) !== '');
        if (agg === 'count') {
            return raw.length;
        }
        if (agg === 'distinct') {
            return new Set(raw.map(String)).size;
        }
        const values = raw.map((v) => parseFloat(v)).filter((v) => Number.isFinite(v));
        if (!values.length) {
            return NaN;
        }
        if (agg === 'min') {
            return Math.min(...values);
        }
        if (agg === 'max') {
            return Math.max(...values);
        }
        const sum = values.reduce((a, b) => a + b, 0);
        return agg === 'avg' ? sum / values.length : sum;
    }

    /**
     * A calculated value as shown: dates as dates, percentages with %, numbers with up to one decimal.
     *
     * @param {Number} value
     * @param {Object|null} column
     * @param {String} agg
     * @param {Object} payload
     * @returns {String}
     */
    aggregateText(value, column, agg, payload = this.payload) {
        if (!Number.isFinite(value)) {
            return '-';
        }
        if (column && column.type === 'date' && (agg === 'min' || agg === 'max')) {
            return formatDate(String(value), column.withtime);
        }
        const pct = column && ['sum', 'avg', 'min', 'max'].includes(agg) && this.formatOf(column, payload) === 'progress';
        return value.toLocaleString(undefined, {maximumFractionDigits: 1}) + (pct ? '%' : '');
    }

    /**
     * Name of a calculation for a column ("Average", "Latest"...).
     *
     * @param {String} agg
     * @param {Object|null} column
     * @returns {String}
     */
    aggregateName(agg, column) {
        if (column && column.type === 'date' && (agg === 'min' || agg === 'max')) {
            return agg === 'min' ? this.str.ui_kpi_earliest : this.str.ui_kpi_latest;
        }
        return this.str['ui_kpi_' + agg] || agg;
    }

    /**
     * KPI cards above the chart (see kpiSpecs()); with "Compare with the previous period", the change against it.
     *
     * @param {Array} rows visible rows of the level
     * @param {Array|null} previous visible rows of the previous period
     * @returns {String}
     */
    kpisHtml(rows, previous) {
        const specs = this.kpiSpecs();
        if (!specs.length || !rows.length) {
            return '';
        }
        const columns = Object.fromEntries(this.payload.columns.map((c) => [c.key, c]));
        const delta = (now, before) => {
            if (!previous || !Number.isFinite(before) || before === 0 || !Number.isFinite(now)) {
                return '';
            }
            const change = (now - before) / Math.abs(before) * 100;
            const up = change >= 0;
            return `<span class="reportium-kpi-delta ${up ? 'is-up' : 'is-down'}" title="${esc(this.str.ui_kpi_vsprevious)}">
                <i class="fa ${up ? 'fa-arrow-up' : 'fa-arrow-down'}" aria-hidden="true"></i>
                ${esc(Math.abs(change).toLocaleString(undefined, {maximumFractionDigits: 1}))}%</span>`;
        };
        const cards = specs.map((spec, index) => {
            const column = columns[spec.key] || null;
            const now = this.aggregate(rows, spec.key, spec.agg);
            // A change only makes sense for numbers (not for the latest date).
            const comparable = !(column && column.type === 'date');
            const change = previous && comparable ? delta(now, this.aggregate(previous, spec.key, spec.agg)) : '';
            const label = column ? column.label : this.str.ui_kpi_rows;
            const sub = column ? this.aggregateName(spec.agg, column) : (previous ? this.str.ui_kpi_vsprevious : '');
            return `<div class="reportium-kpi" data-region="kpi" data-key="${esc(spec.key)}" data-agg="${esc(spec.agg)}">
                <button type="button" class="reportium-kpi-gear" data-action="kpi-edit" data-index="${index}"
                    title="${esc(this.str.ui_kpi_edit)}" aria-label="${esc(this.str.ui_kpi_edit)}: ${esc(label)}"
                    aria-expanded="${this.openKpi === index}"><i class="fa fa-gear fa-cog" aria-hidden="true"></i></button>
                <div class="reportium-kpi-label" title="${esc(label)}">${esc(label)}</div>
                <div class="reportium-kpi-value">${esc(this.aggregateText(now, column, spec.agg))} ${change}</div>
                <div class="reportium-kpi-sub">${esc(sub)}</div></div>`;
        });
        const add = `<button type="button" class="reportium-kpi reportium-kpi-add" data-action="kpi-edit" data-index="new"
            title="${esc(this.str.ui_kpi_add)}" aria-label="${esc(this.str.ui_kpi_add)}"
            aria-expanded="${this.openKpi === 'new'}"><i class="fa fa-plus" aria-hidden="true"></i></button>`;
        return `<div class="reportium-kpis" data-region="kpis">${cards.join('')}${add}</div>`;
    }

    /**
     * Popover of a KPI card (its gear, or the "+" card): the column and the calculation, apply, remove, save as default.
     */
    renderKpiPopover() {
        const old = this.root.querySelector('[data-region="kpi-popover"]');
        if (old) {
            old.remove();
        }
        if (this.openKpi === null || this.openKpi === undefined) {
            return;
        }
        const button = this.root.querySelector(`[data-action="kpi-edit"][data-index="${this.openKpi}"]`);
        if (!button) {
            this.openKpi = null;
            return;
        }
        const specs = this.kpiSpecs();
        const isnew = this.openKpi === 'new';
        const columns = this.payload.columns.filter((c) => c.type !== 'id');
        const firstnumber = columns.find((c) => c.type === 'number') || columns[0];
        // The "+" card proposes a card that is not shown yet.
        const used = new Set(specs.map((k) => (k.key === '_rows' ? '_rows' : `${k.key}:${k.agg}`)));
        const candidates = (firstnumber ? [{key: firstnumber.key, agg: this.defaultAggregate(firstnumber)}] : [])
            .concat(columns.map((c) => ({key: c.key, agg: this.defaultAggregate(c)})), [{key: '_rows', agg: 'count'}]);
        const fresh = candidates.find((k) => !used.has(k.key === '_rows' ? '_rows' : `${k.key}:${k.agg}`)) || candidates[0];
        const spec = isnew ? fresh
            : specs[this.openKpi];
        if (!spec) {
            this.openKpi = null;
            return;
        }
        const option = (value, text) => `<option value="${esc(value)}"${spec.key === value ? ' selected' : ''}>
            ${esc(text)}</option>`;
        const columnoptions = [option('_rows', this.str.ui_kpi_rows)].concat(columns.map((c) => option(c.key, c.label)))
            .join('');
        const popover = document.createElement('div');
        popover.className = 'reportium-col-filter reportium-kpi-popover';
        popover.setAttribute('data-region', 'kpi-popover');
        popover.setAttribute('role', 'dialog');
        popover.setAttribute('aria-label', isnew ? this.str.ui_kpi_add : this.str.ui_kpi_edit);
        popover.innerHTML = `<div class="reportium-col-filter-title"><i class="fa fa-gear fa-cog" aria-hidden="true"></i>
                ${esc(isnew ? this.str.ui_kpi_add : this.str.ui_kpi_edit)}</div>
            <div class="reportium-col-filter-body">
                <label>${esc(this.str.ui_kpi_column)}<select class="form-control form-control-sm" data-kpi-field="key">
                    ${columnoptions}</select></label>
                <label data-region="kpi-agg-field">${esc(this.str.ui_kpi_calculation)}
                    <select class="form-control form-control-sm" data-kpi-field="agg">${this.kpiAggOptions(spec)}</select></label>
                <div class="text-danger small" data-region="kpi-error" hidden>${esc(this.str.ui_kpi_duplicate)}</div>
                <div class="reportium-kpi-popover-actions">
                    <button type="button" class="btn btn-sm btn-primary" data-action="kpi-apply">${esc(this.str.ui_apply)}</button>
                    ${this.config.canconfigure ? `<button type="button" class="btn btn-sm btn-outline-primary"
                        data-action="kpi-save">${esc(this.str.ui_save)}</button>` : ''}
                    ${isnew ? '' : `<button type="button" class="btn btn-sm btn-link text-danger" data-action="kpi-remove">
                        ${esc(this.str.ui_kpi_remove)}</button>`}
                </div>
            </div>`;
        this.root.appendChild(popover);
        const rootbox = this.root.getBoundingClientRect();
        const box = button.getBoundingClientRect();
        const left = Math.min(box.left - rootbox.left, Math.max(0, rootbox.width - popover.offsetWidth));
        popover.style.left = `${Math.max(0, left)}px`;
        popover.style.top = `${box.bottom - rootbox.top + 4}px`;
        popover.querySelector('[data-kpi-field="key"]').focus();
    }

    /**
     * Calculations offered for the column of a card.
     *
     * @param {Object} spec {key, agg}
     * @returns {String} options
     */
    kpiAggOptions(spec) {
        const column = this.payload.columns.find((c) => c.key === spec.key);
        if (!column) {
            return `<option value="count" selected>${esc(this.str.ui_kpi_count)}</option>`;
        }
        const aggs = column.type === 'number' ? ['sum', 'avg', 'min', 'max', 'count', 'distinct']
            : (column.type === 'date' ? ['min', 'max', 'count', 'distinct'] : ['count', 'distinct']);
        const chosen = aggs.includes(spec.agg) ? spec.agg : this.defaultAggregate(column);
        return aggs.map((a) => `<option value="${a}"${a === chosen ? ' selected' : ''}>
            ${esc(this.aggregateName(a, column))}</option>`)
            .join('');
    }

    /**
     * The cards with the popover's change: replace (or add, or remove) one card; shown at once, saved as the default
     * of the level when asked.
     *
     * @param {String} action kpi-apply | kpi-remove | kpi-save
     */
    changeKpi(action) {
        const popover = this.root.querySelector('[data-region="kpi-popover"]');
        const specs = this.kpiSpecs().map((k) => Object.assign({}, k));
        const spec = {
            key: popover.querySelector('[data-kpi-field="key"]').value,
            agg: popover.querySelector('[data-kpi-field="agg"]').value,
        };
        if (spec.key === '_rows') {
            spec.agg = 'count';
        }
        const id = (k) => (k.key === '_rows' ? '_rows' : `${k.key}:${k.agg}`);
        const twin = specs.findIndex((k, i) => id(k) === id(spec) && i !== this.openKpi);
        if (action !== 'kpi-remove' && twin !== -1) {
            popover.querySelector('[data-region="kpi-error"]').hidden = false;
            return;
        }
        if (action === 'kpi-remove') {
            specs.splice(this.openKpi, 1);
        } else if (this.openKpi === 'new') {
            specs.push(spec);
        } else {
            specs[this.openKpi] = spec;
        }
        this.openKpi = null;
        const mapping = Object.assign({}, this.state.mapping || {}, {kpis: specs});
        if (action === 'kpi-save') {
            this.saveSettings(Object.assign({}, this.mapping(), {kpis: specs}));
            return;
        }
        this.state.mapping = mapping;
        this.render();
    }

    /**
     * Whether the totals row is shown: the level's own setting, else the report's (Edit page).
     *
     * @returns {Boolean}
     */
    totalsRowShown() {
        const map = this.mapping();
        return map.totalsrow !== undefined ? !!map.totalsrow : !!(this.config.kpidefaults || {}).totalsrow;
    }

    /**
     * Totals row under a table: the calculations of the KPI cards for the columns that have one, over every visible
     * row (not only the page).
     *
     * @param {Array} columns columns of the table
     * @param {Array} rows every visible row of the pane
     * @param {Object} payload
     * @returns {String}
     */
    totalsRowHtml(columns, rows, payload) {
        if (!this.totalsRowShown() || !rows.length) {
            return '';
        }
        const specs = this.kpiSpecs(payload).filter((k) => k.key !== '_rows');
        const cells = columns.map((c, i) => {
            const spec = specs.find((k) => k.key === c.key);
            if (!spec) {
                return `<td>${i === 0 ? `<strong>${esc(this.str.ui_totals)}</strong>` : ''}</td>`;
            }
            // The first column says what the row is, also when it has a calculation of its own.
            const label = i === 0 ? `<span class="text-muted">${esc(this.str.ui_totals)}:</span> ` : '';
            return `<td data-agg="${esc(spec.agg)}" title="${esc(this.aggregateName(spec.agg, c))}">${label}<strong>
                ${esc(this.aggregateText(this.aggregate(rows, c.key, spec.agg), c, spec.agg, payload))}</strong></td>`;
        }).join('');
        return `<tfoot data-region="totals"><tr>${cells}</tr></tfoot>`;
    }
    /**
     * Calculated columns of the level: those changed on screen (not saved yet), else the saved ones.
     *
     * @returns {Array} [{key, label, formula, format}]
     */
    calculatedList() {
        const override = (this.state.mapping || {}).calculated;
        const list = Array.isArray(override) ? override : ((this.payload && this.payload.mapping || {}).calculated || []);
        return list.filter((d) => d && typeof d === 'object').map((d) => ({key: String(d.key), label: String(d.label || ''),
            formula: String(d.formula || ''), format: d.format === 'progress' ? 'progress' : 'text'}));
    }

    /**
     * Add the calculated columns to a payload of this level (replacing those added before), row by row.
     *
     * @param {Object|null} payload
     */
    applyCalculated(payload) {
        if (!payload || !Array.isArray(payload.columns)) {
            return;
        }
        const columns = payload.columns.filter((c) => !c.calculated);
        const valid = [];
        this.calculatedList().slice(0, MAX_CALCULATED).forEach((def) => {
            if (!/^calc_[a-z0-9_]{1,30}$/.test(def.key) || columns.some((c) => c.key === def.key) || !def.label.trim()) {
                return;
            }
            try {
                valid.push({key: def.key, tree: parseFormula(def.formula, formulaRefs(columns))});
                columns.push({key: def.key, label: def.label, type: 'number', format: def.format, withtime: false,
                    html: false, calculated: true});
            } catch (e) {
                // An invalid formula is left out, as on the server.
            }
        });
        payload.columns = columns;
        if (!valid.length) {
            return;
        }
        payload.rows.forEach((row) => valid.forEach(({key, tree}) => {
            const value = evalFormula(tree, row);
            row[key] = value === null || !isFinite(value) ? null : roundTo(value, 2);
        }));
    }

    /**
     * Error of a formula for a calculated column (its own and later columns cannot be used), or ''.
     *
     * @param {String} formula
     * @param {String} key the column edited ('new' for a new one)
     * @returns {String}
     */
    formulaError(formula, key) {
        const index = this.payload.columns.findIndex((c) => c.key === key);
        const columns = index === -1 ? this.payload.columns : this.payload.columns.slice(0, index);
        try {
            parseFormula(formula, formulaRefs(columns));
            return '';
        } catch (e) {
            return e && e.code === 'unknown' ? this.str.formulaunknowncolumn.replace('{$a}', e.name) : this.str.formulainvalid;
        }
    }

    /**
     * Popover of a calculated column (the "+" of the last header, or the calculator of a calculated column).
     */
    renderCalcPopover() {
        const old = this.root.querySelector('[data-region="calc-popover"]');
        if (old) {
            old.remove();
        }
        if (!this.openCalc) {
            return;
        }
        const button = this.root.querySelector(
            `[data-region="pane-0"] [data-action="calc-edit"][data-key="${CSS.escape(this.openCalc)}"]`);
        const isnew = this.openCalc === 'new';
        const def = isnew ? {key: 'new', label: '', formula: '', format: 'text'}
            : this.calculatedList().find((d) => d.key === this.openCalc);
        if (!button || !def) {
            this.openCalc = null;
            return;
        }
        const chips = this.payload.columns.filter((c) => c.type === 'number' && c.key !== def.key)
            .map((c) => `<button type="button" class="reportium-calc-chip" data-action="calc-chip" data-key="${esc(c.key)}"
                title="[${esc(c.key)}]">${esc(c.label)}</button>`).join('');
        const popover = document.createElement('div');
        popover.className = 'reportium-col-filter reportium-kpi-popover reportium-calc-popover';
        popover.setAttribute('data-region', 'calc-popover');
        popover.setAttribute('role', 'dialog');
        const title = isnew ? this.str.ui_calc_add : this.str.ui_calc_edit;
        popover.setAttribute('aria-label', title);
        popover.innerHTML = `<div class="reportium-col-filter-title"><i class="fa fa-calculator" aria-hidden="true"></i>
                ${esc(title)}</div>
            <div class="reportium-col-filter-body">
                <label>${esc(this.str.ui_calc_name)}<input type="text" class="form-control form-control-sm" maxlength="100"
                    data-calc-field="label" value="${esc(def.label)}"></label>
                <label>${esc(this.str.ui_calc_formula)}<textarea class="form-control form-control-sm reportium-calc-formula"
                    rows="2" spellcheck="false" data-calc-field="formula"
                    placeholder="[a] / [b] * 100">${esc(def.formula)}</textarea>
                    </label>
                ${chips ? `<div class="small text-muted">${esc(this.str.ui_calc_columns)}</div>
                    <div class="reportium-calc-chips">${chips}</div>` : ''}
                <div class="small text-muted">${esc(this.str.ui_calc_help)}</div>
                <label>${esc(this.str.ui_calc_format)}<select class="form-control form-control-sm" data-calc-field="format">
                    <option value="text"${def.format === 'progress' ? '' : ' selected'}>${esc(this.str.ui_calc_number)}</option>
                    <option value="progress"${def.format === 'progress' ? ' selected' : ''}>
                        ${esc(this.str.ui_format_progress)}</option>
                    </select></label>
                <div class="small" data-region="calc-preview" aria-live="polite"></div>
                <div class="reportium-kpi-popover-actions">
                    <button type="button" class="btn btn-sm btn-primary" data-action="calc-apply">${esc(this.str.ui_apply)}</button>
                    ${this.config.canconfigure ? `<button type="button" class="btn btn-sm btn-outline-primary"
                        data-action="calc-save">${esc(this.str.ui_save)}</button>` : ''}
                    ${isnew ? '' : `<button type="button" class="btn btn-sm btn-link text-danger" data-action="calc-remove">
                        ${esc(this.str.ui_calc_remove)}</button>`}
                </div>
            </div>`;
        this.root.appendChild(popover);
        const rootbox = this.root.getBoundingClientRect();
        const box = button.getBoundingClientRect();
        const left = Math.min(box.left - rootbox.left, Math.max(0, rootbox.width - popover.offsetWidth));
        popover.style.left = `${Math.max(0, left)}px`;
        popover.style.top = `${box.bottom - rootbox.top + 4}px`;
        this.previewCalc();
        popover.querySelector(isnew ? '[data-calc-field="label"]' : '[data-calc-field="formula"]').focus();
    }

    /**
     * Under the formula: its error, or its value for the first row shown.
     */
    previewCalc() {
        const popover = this.root.querySelector('[data-region="calc-popover"]');
        if (!popover) {
            return;
        }
        const formula = popover.querySelector('[data-calc-field="formula"]').value;
        const preview = popover.querySelector('[data-region="calc-preview"]');
        if (!formula.trim()) {
            preview.textContent = '';
            return;
        }
        const error = this.formulaError(formula, this.openCalc);
        preview.className = `small ${error ? 'text-danger' : 'text-muted'}`;
        if (error) {
            preview.textContent = error;
            return;
        }
        const row = this.visibleRows()[0];
        const index = this.payload.columns.findIndex((c) => c.key === this.openCalc);
        const columns = index === -1 ? this.payload.columns : this.payload.columns.slice(0, index);
        const value = row ? evalFormula(parseFormula(formula, formulaRefs(columns)), row) : null;
        preview.textContent = this.str.ui_calc_preview.replace('{$a}', value === null || !isFinite(value) ? '-'
            : String(roundTo(value, 2)));
    }

    /**
     * The calculated columns with the popover's change: shown at once, saved as the default of the level when asked
     * (removing a saved column removes it from the defaults, for those who may change them).
     *
     * @param {String} action calc-apply | calc-save | calc-remove
     */
    changeCalc(action) {
        const popover = this.root.querySelector('[data-region="calc-popover"]');
        const field = (name) => popover.querySelector(`[data-calc-field="${name}"]`).value.trim();
        const key = this.openCalc;
        let list = this.calculatedList();
        if (action === 'calc-remove') {
            list = list.filter((d) => d.key !== key);
        } else {
            const label = field('label');
            const formula = field('formula');
            const error = label ? this.formulaError(formula, key) : this.str.ui_calc_noname;
            if (error) {
                const preview = popover.querySelector('[data-region="calc-preview"]');
                preview.className = 'small text-danger';
                preview.textContent = error;
                return;
            }
            const def = {label, formula, format: field('format') === 'progress' ? 'progress' : 'text'};
            if (key === 'new') {
                list.push(Object.assign({key: `calc_${Date.now().toString(36)}`}, def));
            } else {
                list = list.map((d) => (d.key === key ? Object.assign({}, d, def) : d));
            }
        }
        this.openCalc = null;
        const saved = ((this.payload.mapping || {}).calculated || []).some((d) => d.key === key);
        if (action === 'calc-save' || (action === 'calc-remove' && saved && this.config.canconfigure)) {
            this.saveSettings(Object.assign({}, this.mapping(), {calculated: list}));
            return;
        }
        this.state.mapping = Object.assign({}, this.state.mapping || {}, {calculated: list});
        this.render();
    }

    /**
     * Columns of a table (id columns hidden) in the order the user dragged them to at this level.
     *
     * @param {Object} payload
     * @returns {Array}
     */
    orderedColumns(payload = this.payload) {
        const columns = payload.columns.filter((c) => c.type !== 'id');
        const order = this.columnOrder[String(this.level)] || [];
        if (!order.length) {
            return columns;
        }
        const place = (c, i) => (order.includes(c.key) ? order.indexOf(c.key) : order.length + i);
        return columns.map((c, i) => [place(c, i), i, c]).sort((a, b) => a[0] - b[0] || a[1] - b[1]).map((x) => x[2]);
    }

    /**
     * Keep the column order of this level for the user (null = the order of the SQL); downloads follow it.
     *
     * @param {Array|null} keys
     */
    saveColumnOrder(keys) {
        if (keys) {
            this.columnOrder[String(this.level)] = keys;
        } else {
            delete this.columnOrder[String(this.level)];
        }
        setUserPreference(`local_reportium_columns_${this.config.reportid}`, JSON.stringify(this.columnOrder))
            .catch(Notification.exception);
        this.render();
        // The moved column "lands" in its new place.
        const moved = keys ? this.dropped : null;
        this.dropped = null;
        const th = moved ? this.root.querySelector(`[data-region="table"] th[data-colkey="${CSS.escape(moved)}"]`) : null;
        if (th) {
            const index = th.cellIndex;
            [th, ...[...th.closest('table').querySelectorAll('tbody tr, tfoot tr')].map((row) => row.cells[index])]
                .filter(Boolean).forEach((cell) => cell.classList.add('is-dropped'));
        }
    }

    /**
     * Pager under a table: first / previous / next / last and "1-20 of 3524".
     *
     * @param {Number} pane
     * @param {Number} page current page (0-based)
     * @param {Number} pages number of pages
     * @param {Number} offset index of the first row shown
     * @param {Number} shown rows on this page
     * @param {Number} total visible rows
     * @returns {String}
     */
    pagerHtml(pane, page, pages, offset, shown, total) {
        const button = (target, label, icon, disabled) => `<button type="button" class="btn btn-sm btn-outline-secondary"
            data-action="page" data-pane="${pane}" data-page="${target}"${disabled ? ' disabled' : ''}
            aria-label="${esc(label)}" title="${esc(label)}"><i class="fa ${icon}" aria-hidden="true"></i></button>`;
        const range = this.str.ui_pagerange.replace('{$a->from}', offset + 1).replace('{$a->to}', offset + shown)
            .replace('{$a->total}', total);
        const pageof = this.str.ui_pageof.replace('{$a->page}', page + 1).replace('{$a->pages}', pages);
        return `<nav class="reportium-pager d-print-none" data-region="pager-${pane}" aria-label="${esc(pageof)}">
            ${button(0, this.str.ui_firstpage, 'fa-angle-double-left', page === 0)}
            ${button(page - 1, this.str.ui_previouspage, 'fa-angle-left', page === 0)}
            <span class="reportium-pager-info" data-region="pager-info-${pane}">${esc(pageof)} · ${esc(range)}</span>
            ${button(page + 1, this.str.ui_nextpage, 'fa-angle-right', page >= pages - 1)}
            ${button(pages - 1, this.str.ui_lastpage, 'fa-angle-double-right', page >= pages - 1)}</nav>`;
    }

    /**
     * Table of the visible rows (id columns hidden). Rows are clickable when a deeper level exists.
     *
     * @param {Array} rows
     * @param {Object} payload
     * @param {Number} pane 0 = current item, 1 = compared item
     * @param {Number} offset index of the first row of the page in the visible rows
     * @param {Array} allrows every visible row of the pane (for the totals row)
     * @returns {String}
     */
    tableHtml(rows, payload = this.payload, pane = 0, offset = 0, allrows = rows) {
        const columns = this.orderedColumns(payload);
        // Click a header to sort by it: ascending, descending, then back to the original order.
        const tsort = this.state.tsort || {};
        const head = columns.map((c) => {
            const dir = tsort.key === c.key ? tsort.dir : '';
            const aria = dir ? ` aria-sort="${dir === 'asc' ? 'ascending' : 'descending'}"` : '';
            const icon = dir === 'asc' ? 'fa-sort-up' : (dir === 'desc' ? 'fa-sort-down' : 'fa-sort');
            const filtered = this.state.filters[c.key] ? ' is-active' : '';
            const funnel = pane === 0 ? `<button type="button" class="reportium-th-filter${filtered}" data-action="col-filter"
                data-key="${esc(c.key)}" title="${esc(this.str.ui_filtercolumn.replace('{$a}', c.label))}"
                aria-label="${esc(this.str.ui_filtercolumn.replace('{$a}', c.label))}"
                aria-expanded="${this.openFilter === c.key}"><i class="fa fa-filter" aria-hidden="true"></i></button>` : '';
            // A calculated column opens its formula; the last header adds one.
            const calc = pane === 0 && c.calculated ? `<button type="button" class="reportium-th-filter d-print-none"
                data-action="calc-edit" data-key="${esc(c.key)}" title="${esc(this.str.ui_calc_edit)}"
                aria-label="${esc(this.str.ui_calc_edit)}" aria-expanded="${this.openCalc === c.key}">
                <i class="fa fa-calculator" aria-hidden="true"></i></button>` : '';
            const drag = pane === 0 ? ` draggable="true" data-colkey="${esc(c.key)}" title="${esc(this.str.ui_dragcolumn)}"` : '';
            return `<th scope="col"${aria}${drag}${c.calculated ? ' class="is-calculated"' : ''}><span class="reportium-th">
                <button type="button" class="reportium-th-sort"
                data-action="tsort" data-key="${esc(c.key)}">${esc(c.label)}<i class="fa ${icon}" aria-hidden="true"></i></button>
                ${funnel}${calc}</span></th>`;
        }).join('');
        const drillable = payload.hasnext ? ' class="is-drillable" tabindex="0"' : '';
        const body = rows.map((row, i) => `<tr data-row="${offset + i}" data-pane="${pane}"${drillable}>
            ${columns.map((c) => `<td>${this.cellHtml(c, row[c.key], payload, row)}</td>`).join('')}</tr>`).join('');
        // A strip as tall as the table, where the new column will go: adds a calculated column.
        const add = pane === 0 ? `<button type="button" class="reportium-col-add d-print-none" data-action="calc-edit"
            data-key="new" title="${esc(this.str.ui_calc_add)}" aria-label="${esc(this.str.ui_calc_add)}"
            aria-expanded="${this.openCalc === 'new'}"><i class="fa fa-plus" aria-hidden="true"></i></button>` : '';
        return `<div class="table-responsive"><div class="reportium-table-wrap">
            <table class="table table-sm table-striped reportium-table"
            data-region="${pane ? 'table-compare' : 'table'}"><thead><tr>${head}</tr></thead><tbody>${body}</tbody>
            ${this.totalsRowHtml(columns, allrows, payload)}</table>${add}</div></div>`;
    }

    /**
     * HTML of a table cell. Percentages (progress, *_pct, percent) show as a progress bar, and a completion_state
     * code (completed, submitted, notcompleted, futurenotcompleted) as a coloured label, like the participants
     * report progress bar.
     *
     * @param {Object} column
     * @param {*} value
     * @param {Object} payload level payload the row belongs to (current or compared)
     * @param {Object|null} row the whole row (for links to Moodle)
     * @returns {String}
     */
    cellHtml(column, value, payload = this.payload, row = null) {
        const format = this.formatOf(column, payload);
        if (format === 'progress') {
            const pct = Math.max(0, Math.min(100, parseFloat(value) || 0));
            // One colour per value: the 0% colour, through the 50% one, to the 100% one (100% is fully green).
            const c = Object.assign({low: '#c71c22', mid: '#f0ad4e', high: '#73a839'}, this.config.progresscolors || {});
            const fill = `width:${pct}%;background-color:${pct <= 50 ? mixColor(c.low, c.mid, pct / 50)
                : mixColor(c.mid, c.high, (pct - 50) / 50)}`;
            return `<div class="reportium-progress" role="progressbar" aria-valuenow="${pct}" aria-valuemin="0"
                aria-valuemax="100"><div class="reportium-progress-bar" style="${esc(fill)}"></div>
                <span>${esc(Math.round(pct * 10) / 10)}%</span></div>`;
        }
        // A value matches a state ignoring case, accents, spaces, hyphens and underscores, by code or alias
        // ("In Progress", "Sí").
        const key = String(value ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
            .replace(/[^a-z0-9]/g, '');
        const state = format === 'state' && key ? (this.config.states || []).find((s) => (s.keys || [s.code]).includes(key))
            : null;
        if (state) {
            return `<span class="reportium-state" data-state="${esc(state.code)}"
                style="background-color:${esc(state.color)};color:${esc(state.textcolor)}">${esc(state.label)}</span>`;
        }
        // HTML returned by the SQL, cleaned by the server.
        if (column.html && row && row.__html && row.__html[column.key] !== undefined) {
            return row.__html[column.key];
        }
        return this.linkHtml(column, esc(formatCell(column, value)), payload, row);
    }

    /**
     * A name cell as a link to Moodle (user profile, course, category) when the level has its id; it opens in a new
     * tab and does not drill down.
     *
     * @param {Object} column
     * @param {String} html escaped content of the cell
     * @param {Object} payload
     * @param {Object|null} row
     * @returns {String}
     */
    linkHtml(column, html, payload, row) {
        const link = row && payload.links ? payload.links[column.key] : null;
        const urls = this.config.linkurls || {};
        const id = link ? parseInt(row[link.idkey], 10) : 0;
        if (!link || !(id > 0) || html === '') {
            return html;
        }
        const course = link.courseidkey ? parseInt(row[link.courseidkey], 10) : 0;
        const template = link.kind === 'user' ? (course > 1 ? urls.user : urls.userprofile) : urls[link.kind];
        if (!template) {
            return html;
        }
        const href = template.replace('{id}', String(id)).replace('{course}', String(course));
        return `<a href="${esc(href)}" class="reportium-link" target="_blank" rel="noopener"
            title="${esc(this.str.ui_openinmoodle)}">${html}</a>`;
    }

    /**
     * X axis labels that never overlap: each label wraps inside the room of its own bar (up to three lines, then
     * "…"); when the bars are too narrow to wrap, the labels are turned and cut. The tooltip shows the full name.
     *
     * @param {Number} count number of labels
     * @param {Number} width chart width in pixels
     * @returns {Object} ECharts axisLabel
     */
    axisLabel(count, width) {
        // Room of each bar: the chart width without the value axis and margins, shared by the labels.
        const slot = Math.floor((width - 110) / Math.max(1, count)) - 8;
        if (slot >= 56) {
            // About 6.5 px per character at the axis font size.
            const max = Math.max(6, Math.floor(slot / 6.5));
            return {rotate: 0, interval: 0, lineHeight: 13, formatter: (value) => wrapLabel(value, max, 3)};
        }
        if (count > 40) {
            // Long series (days, many items): only the labels that fit, turned; the tooltip names every point.
            return {rotate: 45, interval: 'auto', hideOverlap: true, width: 110, overflow: 'truncate', ellipsis: '…'};
        }
        return {rotate: count > 24 ? 90 : 45, interval: 0, width: 130, overflow: 'truncate', ellipsis: '…'};
    }

    /**
     * ECharts option for the visible rows.
     *
     * @param {Array} rows
     * @param {String} view
     * @param {Object} payload level payload the rows come from
     * @param {Number} width chart width in pixels (room for the axis labels)
     * @returns {Object}
     */
    chartOption(rows, view, payload = this.payload, width = 600) {
        const map = this.mapping();
        const labels = rows.map((r) => this.labelOf(payload, map.labelcolumn, r));
        const metrics = (map.metrics || []).map((key) => payload.columns.find((c) => c.key === key)).filter(Boolean);
        if (view === 'pie') {
            const metric = metrics[0];
            return {
                tooltip: {trigger: 'item'},
                legend: {type: 'scroll', bottom: 0},
                series: [{
                    type: 'pie',
                    radius: ['30%', '65%'],
                    name: metric ? metric.label : '',
                    data: rows.map((r, i) => ({name: labels[i], value: metric ? parseFloat(r[metric.key]) || 0 : 0})),
                }],
            };
        }
        return {
            tooltip: {trigger: 'axis'},
            legend: {type: 'scroll', top: 0},
            grid: {left: 48, right: 24, top: 40, bottom: 16, containLabel: true},
            xAxis: {type: 'category', data: labels, axisLabel: this.axisLabel(labels.length, width)},
            yAxis: {type: 'value'},
            series: metrics.map((m) => ({
                type: view === 'line' ? 'line' : 'bar',
                name: m.label,
                stack: this.canStack(view, map.metrics || []) && map.stacked ? 'total' : undefined,
                data: rows.map((r) => parseFloat(r[m.key]) || 0),
            })),
        };
    }

    /**
     * Dispose the current chart instance.
     */
    disposeChart() {
        this.charts.forEach((chart) => chart.dispose());
        this.charts = [];
        delete this.root.dataset.chartReady;
    }

    /**
     * Drill into a row: pass its value column to the next level.
     * A row of the compared pane first makes the compared item the current one.
     *
     * @param {Object} row
     * @param {Number} pane 0 = current item, 1 = compared item
     */
    drill(row, pane = 0) {
        if (!this.payload.hasnext) {
            return;
        }
        if (pane === 1 && this.state.compare && this.state.path.length) {
            this.state.path[this.state.path.length - 1] = Object.assign({}, this.state.compare);
        }
        const map = this.mapping();
        const mainkey = String(map.labelcolumn || '').split('|')[0];
        this.state.path.push({
            value: String(row[map.valuecolumn] ?? row[mainkey] ?? ''),
            label: this.labelOf(this.payload, map.labelcolumn, row),
        });
        this.resetLevelState();
        this.load();
    }

    /**
     * Search, filters, view and mapping overrides belong to one level: clear them when the level changes.
     */
    resetLevelState() {
        this.state.search = '';
        this.state.filters = {};
        this.state.mapping = null;
        this.state.view = null;
        this.state.tsort = null;
        this.state.pages = [0, 0];
        this.state.compare = null;
        this.comparePayload = null;
        this.openKpi = null;
    }

    /**
     * Items that can be compared with the current one: its siblings in the parent level.
     *
     * @returns {Array} [{value, label}]
     */
    siblingOptions() {
        const parent = this.parentPayload;
        if (!parent || !this.state.path.length) {
            return [];
        }
        const map = parent.mapping;
        const mainkey = String(map.labelcolumn || '').split('|')[0];
        const current = this.state.path[this.state.path.length - 1].value;
        return parent.rows.map((row) => ({
            value: String(row[map.valuecolumn] ?? row[mainkey] ?? ''),
            label: this.labelOf(parent, map.labelcolumn, row),
        })).filter((o) => o.value !== '' && o.value !== current);
    }

    /**
     * Wire all UI events (delegated on the root).
     */
    bindEvents() {
        this.root.addEventListener('click', (e) => {
            if (e.target.closest('a.reportium-link, [data-region="body"] td a[href]')) {
                // A link (to Moodle, or from the SQL) opens; the row does not drill down.
                return;
            }
            const target = e.target.closest('[data-action], tr[data-row]');
            if (!target || !this.payload) {
                return;
            }
            const action = target.dataset.action;
            if (target.matches('tr[data-row]')) {
                const pane = parseInt(target.dataset.pane || '0', 10);
                const payload = pane === 1 && this.comparePayload ? this.comparePayload : this.payload;
                const row = this.visibleRows(payload)[parseInt(target.dataset.row, 10)];
                if (row) {
                    this.drill(row, pane);
                }
            } else if (action === 'crumb') {
                this.state.path = this.state.path.slice(0, parseInt(target.dataset.depth, 10));
                this.resetLevelState();
                this.load();
            } else if (action === 'view') {
                this.state.view = target.dataset.view;
                this.render();
            } else if (action === 'page') {
                this.state.pages[parseInt(target.dataset.pane, 10)] = parseInt(target.dataset.page, 10);
                this.renderBody(this.visibleRows());
                const pane = this.root.querySelector(`[data-region="pane-${target.dataset.pane}"]`);
                if (pane) {
                    pane.scrollIntoView({block: 'nearest'});
                }
            } else if (action === 'col-filter') {
                this.openFilter = this.openFilter === target.dataset.key ? null : target.dataset.key;
                this.focusFilter = true;
                this.renderColumnFilter();
            } else if (action === 'settings-toggle-all') {
                // Collapse every section when some is open, else expand them all.
                const sections = [...this.root.querySelectorAll('.reportium-settings-section')];
                const open = !sections.some((d) => d.open);
                sections.forEach((d) => {
                    d.open = open;
                    this.foldedSections[open ? 'delete' : 'add'](d.dataset.section);
                });
                target.textContent = open ? this.str.ui_collapseall : this.str.ui_expandall;
            } else if (action === 'calc-edit') {
                this.openCalc = this.openCalc === target.dataset.key ? null : target.dataset.key;
                this.renderCalcPopover();
            } else if (['calc-apply', 'calc-save', 'calc-remove'].includes(action)) {
                this.changeCalc(action);
            } else if (action === 'calc-chip') {
                // Insert the column at the cursor of the formula.
                const text = this.root.querySelector('[data-region="calc-popover"] [data-calc-field="formula"]');
                const ref = `[${target.dataset.key}]`;
                const start = text.selectionStart ?? text.value.length;
                const end = text.selectionEnd ?? start;
                text.value = text.value.slice(0, start) + ref + text.value.slice(end);
                text.focus();
                text.setSelectionRange(start + ref.length, start + ref.length);
                this.previewCalc();
            } else if (action === 'reset-column-order') {
                this.saveColumnOrder(null);
            } else if (action === 'kpi-edit') {
                const index = target.dataset.index === 'new' ? 'new' : parseInt(target.dataset.index, 10);
                this.openKpi = this.openKpi === index ? null : index;
                this.renderKpiPopover();
            } else if (['kpi-apply', 'kpi-remove', 'kpi-save'].includes(action)) {
                this.changeKpi(action);
            } else if (action === 'clear-col-filter') {
                delete this.state.filters[target.dataset.key];
                this.render();
            } else if (action === 'tsort') {
                const key = target.dataset.key;
                const dir = this.state.tsort && this.state.tsort.key === key ? this.state.tsort.dir : '';
                const next = {'': 'asc', asc: 'desc', desc: ''}[dir];
                this.state.tsort = next ? {key: key, dir: next} : null;
                this.render();
            } else if (action === 'clear-compare') {
                this.state.compare = null;
                this.state.prev = false;
                this.comparePayload = null;
                this.render();
            } else if (action === 'slow-help') {
                const popover = this.root.querySelector('[data-region="slow-help"]');
                const open = popover.hasAttribute('hidden');
                popover.toggleAttribute('hidden', !open);
                target.setAttribute('aria-expanded', String(open));
            } else if (action === 'freshness-help') {
                const popover = this.root.querySelector('[data-region="freshness-help"]');
                const open = popover.hasAttribute('hidden');
                popover.toggleAttribute('hidden', !open);
                target.setAttribute('aria-expanded', String(open));
            } else if (action === 'toggle-sql') {
                this.togglePanel('sql');
            } else if (action === 'toggle-panel') {
                this.togglePanel(target.dataset.panel);
            } else if (action === 'sql-select') {
                this.sqlSelected = parseInt(target.dataset.level, 10);
                this.historyLevel = null;
                this.renderSql();
            } else if (action === 'sql-add') {
                this.sqlSelected = this.levels.reduce((max, l) => Math.max(max, l.level), 0) + 1;
                this.historyLevel = null;
                this.renderSql();
            } else if (action === 'save-sql') {
                this.saveSql(target.closest('[data-sql-level]'));
            } else if (action === 'delete-sql') {
                this.deleteSql(target.closest('[data-sql-level]'));
            } else if (action === 'sql-history') {
                this.toggleHistory(parseInt(target.closest('[data-sql-level]').dataset.sqlLevel, 10));
            } else if (action === 'history-restore') {
                this.restoreVersion(parseInt(target.dataset.id, 10));
            } else if (action === 'history-use') {
                const version = (this.history || []).find((v) => v.id === parseInt(target.dataset.id, 10));
                if (version) {
                    this.useChatSql(version.sqlquery);
                }
            } else if (action === 'history-diff') {
                this.toggleDiff(parseInt(target.dataset.id, 10));
            } else if (action === 'clear-filters') {
                this.state.filters = {};
                this.render();
            } else if (action === 'apply-settings') {
                const mapping = this.readSettings();
                this.state.view = mapping.defaultview;
                this.state.mapping = mapping;
                this.render();
            } else if (action === 'save-settings') {
                this.saveSettings(this.readSettings());
            } else if (action === 'reset-settings') {
                this.saveSettings({});
            }
        });
        // Printing changes the layout (paper width, compare columns): re-draw the charts to their new size, and
        // back again afterwards, so canvases never spill over the tables.
        const resizecharts = () => this.charts.forEach((chart) => {
            chart.resize();
            // The labels follow the new width of the bars.
            const axis = (chart.getOption().xAxis || [])[0];
            if (axis && axis.type === 'category') {
                chart.setOption({xAxis: {axisLabel: this.axisLabel((axis.data || []).length, chart.getWidth())}});
            }
        });
        window.addEventListener('beforeprint', resizecharts);
        window.addEventListener('afterprint', resizecharts);
        if (window.matchMedia) {
            const printmedia = window.matchMedia('print');
            if (printmedia.addEventListener) {
                printmedia.addEventListener('change', resizecharts);
            }
        }
        // Remember whether the "Chart settings" collapsible is open, so re-renders keep it.
        this.root.addEventListener('toggle', (e) => {
            if (e.target.matches('[data-region="settings-details"]')) {
                this.settingsOpen = e.target.open;
            }
            if (e.target.matches('.reportium-settings-section')) {
                this.foldedSections = this.foldedSections || new Set(['chart', 'table', 'kpis', 'views']);
                this.foldedSections[e.target.open ? 'delete' : 'add'](e.target.dataset.section);
                const all = this.root.querySelector('[data-action="settings-toggle-all"]');
                if (all) {
                    const anyopen = [...this.root.querySelectorAll('.reportium-settings-section')].some((d) => d.open);
                    all.textContent = anyopen ? this.str.ui_collapseall : this.str.ui_expandall;
                }
            }
        }, true);
        // Close the data-freshness popover when clicking anywhere else.
        document.addEventListener('click', (e) => {
            const popover = this.root.querySelector('[data-region="freshness-help"]');
            if (popover && !popover.hasAttribute('hidden') && !e.target.closest('.reportium-freshness')) {
                popover.setAttribute('hidden', 'hidden');
            }
            const slow = this.root.querySelector('[data-region="slow-help"]');
            if (slow && !slow.hasAttribute('hidden') && !e.target.closest('.reportium-slowpill')) {
                slow.setAttribute('hidden', 'hidden');
            }
            if (this.openCalc && e.target.isConnected
                    && !e.target.closest('[data-region="calc-popover"], [data-action="calc-edit"]')) {
                this.openCalc = null;
                this.renderCalcPopover();
            }
            if (this.openKpi !== null && this.openKpi !== undefined && e.target.isConnected
                    && !e.target.closest('[data-region="kpi-popover"], [data-action="kpi-edit"]')) {
                this.openKpi = null;
                this.renderKpiPopover();
            }
            // A click on an element the popover re-render just replaced (choosing a value) is not "outside".
            if (this.openFilter && e.target.isConnected
                    && !e.target.closest('[data-region="col-filter-popover"], [data-action="col-filter"]')) {
                this.openFilter = null;
                this.renderColumnFilter();
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.openCalc) {
                this.openCalc = null;
                this.renderCalcPopover();
            }
            if (e.key === 'Escape' && this.openKpi !== null && this.openKpi !== undefined) {
                this.openKpi = null;
                this.renderKpiPopover();
            }
            if (e.key === 'Escape' && this.openFilter) {
                const button = this.root.querySelector(`[data-action="col-filter"][data-key="${CSS.escape(this.openFilter)}"]`);
                this.openFilter = null;
                this.renderColumnFilter();
                if (button) {
                    button.focus();
                }
            }
        });
        this.root.addEventListener('input', (e) => {
            if (e.target.closest('[data-region="calc-popover"]')) {
                this.previewCalc();
            }
        });
        // Drag a header onto another to move its column (kept per user and level).
        const header = (e) => (e.target.closest ? e.target.closest('[data-region="table"] th[data-colkey]') : null);
        const clearDrop = () => {
            this.root.querySelectorAll('.is-drop-before, .is-drop-after, .is-dragging, .is-lifted')
                .forEach((n) => n.classList.remove('is-drop-before', 'is-drop-after', 'is-dragging', 'is-lifted'));
            if (this.dragGhost) {
                this.dragGhost.remove();
                this.dragGhost = null;
            }
        };
        /**
         * The column "lifted" under the cursor: its header and first cells, as a floating card.
         *
         * @param {HTMLElement} th
         * @returns {HTMLElement}
         */
        const ghostOf = (th) => {
            const table = th.closest('table');
            const index = th.cellIndex;
            const ghost = document.createElement('table');
            ghost.className = 'table table-sm reportium-table reportium-drag-ghost';
            const head = th.cloneNode(true);
            head.removeAttribute('draggable');
            ghost.innerHTML = '<thead><tr></tr></thead><tbody></tbody>';
            ghost.querySelector('tr').appendChild(head);
            [...table.tBodies[0].rows].slice(0, 6).forEach((row) => {
                const tr = document.createElement('tr');
                if (row.cells[index]) {
                    tr.appendChild(row.cells[index].cloneNode(true));
                }
                ghost.tBodies[0].appendChild(tr);
            });
            ghost.style.width = `${Math.max(th.offsetWidth, 120)}px`;
            document.body.appendChild(ghost);
            return ghost;
        };
        const after = (e, th) => {
            const box = th.getBoundingClientRect();
            return e.clientX > box.left + box.width / 2;
        };
        this.root.addEventListener('dragstart', (e) => {
            const th = header(e);
            if (!th) {
                return;
            }
            this.dragColumn = th.dataset.colkey;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', th.dataset.colkey);
            th.classList.add('is-dragging');
            // The column leaves a faded gap and floats under the cursor.
            const index = th.cellIndex;
            th.closest('table').querySelectorAll('tbody tr, tfoot tr').forEach((row) => {
                if (row.cells[index]) {
                    row.cells[index].classList.add('is-lifted');
                }
            });
            this.dragGhost = ghostOf(th);
            e.dataTransfer.setDragImage(this.dragGhost, Math.min(40, th.offsetWidth / 2), 16);
        });
        this.root.addEventListener('dragover', (e) => {
            const th = this.dragColumn ? header(e) : null;
            if (!th) {
                return;
            }
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.root.querySelectorAll('.is-drop-before, .is-drop-after')
                .forEach((n) => n.classList.remove('is-drop-before', 'is-drop-after'));
            if (th.dataset.colkey !== this.dragColumn) {
                th.classList.add(after(e, th) ? 'is-drop-after' : 'is-drop-before');
            }
        });
        this.root.addEventListener('drop', (e) => {
            const th = this.dragColumn ? header(e) : null;
            if (!th) {
                return;
            }
            e.preventDefault();
            const moved = this.dragColumn;
            this.dragColumn = null;
            clearDrop();
            const keys = this.orderedColumns().map((c) => c.key).filter((k) => k !== moved);
            const index = keys.indexOf(th.dataset.colkey);
            if (index === -1) {
                return;
            }
            keys.splice(after(e, th) ? index + 1 : index, 0, moved);
            this.dropped = moved;
            this.saveColumnOrder(keys);
        });
        this.root.addEventListener('dragend', () => {
            this.dragColumn = null;
            clearDrop();
        });
        this.root.addEventListener('keydown', (e) => {
            const tr = e.target.closest('tr[data-row]');
            if (tr && (e.key === 'Enter' || e.key === ' ')) {
                e.preventDefault();
                tr.click();
            }
        });
        let searchTimer = null;
        this.root.addEventListener('input', (e) => {
            if (e.target.matches('[data-region="sql-text"]')) {
                const editor = e.target.closest('[data-sql-level]');
                // The %%MYCOURSES%% note follows what is typed.
                editor.querySelector('[data-region="sql-note-mycourses"]').hidden = !e.target.value.includes('%%MYCOURSES%%');
                const saved = this.levels.find((l) => l.level === parseInt(editor.dataset.sqlLevel, 10));
                const unsaved = !saved || saved.sqlquery !== e.target.value;
                const badge = editor.querySelector('[data-region="sql-badge"]');
                badge.textContent = unsaved ? this.str.ui_sqlunsaved : this.str.ui_sqlsavedbadge;
                badge.classList.toggle('is-unsaved', unsaved);
                return;
            }
            if (e.target.matches('[data-region="search"]')) {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => {
                    this.state.search = e.target.value;
                    this.renderBody(this.visibleRows());
                    this.writeUrlState();
                }, 200);
            }
        });
        this.root.addEventListener('change', (e) => {
            if (e.target.matches('[data-setting="metric"]')) {
                this.syncStackedField();
                return;
            }
            if (e.target.matches('[data-region="compare"]')) {
                const value = e.target.value;
                const option = this.siblingOptions().find((o) => o.value === value);
                this.state.compare = option || null;
                this.state.prev = false;
                this.load();
                return;
            }
            if (e.target.matches('[data-kpi-field]')) {
                const warning = this.root.querySelector('[data-region="kpi-error"]');
                if (warning) {
                    warning.hidden = true;
                }
            }
            if (e.target.matches('[data-kpi-field="key"]')) {
                // The calculations follow the column chosen.
                const agg = this.root.querySelector('[data-kpi-field="agg"]');
                agg.innerHTML = this.kpiAggOptions({key: e.target.value, agg: agg.value});
                agg.disabled = e.target.value === '_rows';
                return;
            }
            if (e.target.matches('[data-region="range"]')) {
                this.state.range = {preset: e.target.value, from: 0, to: 0};
                this.state.prev = this.state.prev && e.target.value !== '';
                this.state.pages = [0, 0];
                this.load();
                return;
            }
            if (e.target.matches('[data-region="range-from"], [data-region="range-to"]')) {
                // Noon of the chosen day: the server takes the whole day in the user's timezone.
                const ts = e.target.value ? Math.floor(Date.parse(e.target.value + 'T12:00:00') / 1000) : 0;
                this.state.range[e.target.dataset.region === 'range-from' ? 'from' : 'to'] = ts;
                this.state.pages = [0, 0];
                this.load();
                return;
            }
            if (e.target.matches('[data-region="range-prev"]')) {
                this.state.prev = e.target.checked;
                if (this.state.prev) {
                    this.state.compare = null;
                }
                this.load();
                return;
            }
            const input = e.target.closest('[data-filter]');
            if (!input) {
                return;
            }
            const key = input.dataset.filter;
            const filter = Object.assign({}, this.state.filters[key] || {});
            if (input.dataset.filterType === 'in') {
                filter.in = [...input.selectedOptions].map((o) => o.value);
            } else if (input.dataset.filterType === 'like') {
                filter.like = input.value.trim();
            } else {
                let value = input.value;
                if (value !== '' && input.dataset.date === '1') {
                    const ts = Date.parse(value + 'T00:00:00');
                    value = String(Math.floor(ts / 1000) + (input.dataset.filterType === 'max' ? 86399 : 0));
                }
                filter[input.dataset.filterType] = value;
            }
            const empty = !(filter.in && filter.in.length) && !filter.like && (filter.min ?? '') === ''
                && (filter.max ?? '') === '';
            if (empty) {
                delete this.state.filters[key];
            } else {
                this.state.filters[key] = filter;
            }
            this.render();
        });
    }


    /**
     * KPI cards chosen in the chart settings: false when switched off, else the rows card and each column with a
     * calculation.
     *
     * @param {HTMLElement} panel
     * @returns {Array|Boolean}
     */
    readKpiSettings(panel) {
        if (!panel.querySelector('[data-setting="kpis"]').checked) {
            return false;
        }
        const specs = panel.querySelector('[data-setting="kpirows"]').checked ? [{key: '_rows', agg: 'count'}] : [];
        panel.querySelectorAll('[data-setting="kpi"]').forEach((select) => {
            if (select.value) {
                specs.push({key: select.dataset.key, agg: select.value});
            }
        });
        return specs;
    }

    /**
     * Save the chart settings of this level as the report default (empty object = automatic).
     *
     * @param {Object} mapping
     */
    saveSettings(mapping) {
        Ajax.call([{
            methodname: 'local_reportium_save_level_config',
            args: {reportid: this.config.reportid, level: this.level, config: JSON.stringify(mapping)},
        }])[0].then(() => {
            // The saved settings change this level in every period (and its comparison): read it again.
            const path = JSON.stringify(this.state.path.map((p) => p.value));
            Object.keys(this.payloads).filter((key) => key.startsWith(`[${path},`)).forEach((key) => delete this.payloads[key]);
            this.state.mapping = null;
            this.state.view = null;
            return this.load();
        }).then(() => Notification.addNotification({message: this.str.ui_saved, type: 'success'}))
        .catch(Notification.exception);
    }
}

/**
 * Lines of two texts as kept / removed / added (longest common subsequence; enough for SQL of a level).
 *
 * @param {String} before
 * @param {String} after
 * @returns {Array} [[kind, line]] kind = same | del | add
 */
const lineDiff = (before, after) => {
    const a = String(before).split('\n');
    const b = String(after).split('\n');
    const lcs = Array.from({length: a.length + 1}, () => new Array(b.length + 1).fill(0));
    for (let i = a.length - 1; i >= 0; i--) {
        for (let j = b.length - 1; j >= 0; j--) {
            lcs[i][j] = a[i] === b[j] ? lcs[i + 1][j + 1] + 1 : Math.max(lcs[i + 1][j], lcs[i][j + 1]);
        }
    }
    const out = [];
    let i = 0;
    let j = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            out.push(['same', a[i]]);
            i++;
            j++;
        } else if (lcs[i + 1][j] >= lcs[i][j + 1]) {
            out.push(['del', a[i++]]);
        } else {
            out.push(['add', b[j++]]);
        }
    }
    while (i < a.length) {
        out.push(['del', a[i++]]);
    }
    while (j < b.length) {
        out.push(['add', b[j++]]);
    }
    return out;
};

/**
 * Mount the interactive report.
 *
 * @param {String} rootid id of the container element
 * @param {Object} config {reportid, courseid, echartsurl, reportname, canconfigure}
 * @returns {Promise}
 */
export const init = (rootid, config = null) => {
    const root = document.getElementById(rootid);
    if (!root) {
        return Promise.resolve();
    }
    // The configuration travels in the root's data-config attribute (js_call_amd arguments must stay small).
    if (!config) {
        try {
            config = JSON.parse(root.dataset.config || '{}');
        } catch (e) {
            config = {};
        }
    }
    if (config.dateformats) {
        DATE_FORMATS = Object.assign(DATE_FORMATS, config.dateformats);
    }
    return getStrings(STRING_KEYS.map((key) => ({key, component: 'local_reportium'}))).then((values) => {
        const strings = {};
        STRING_KEYS.forEach((key, i) => {
            strings[key] = values[i];
        });
        const app = new ReportApp(root, config, strings);
        app.bindEvents();
        return app.loadLevels(config.levelsdata || null).then(() => {
            app.seedFirstLevel(config.firstlevel || null);
            return app.load();
        });
    }).catch(Notification.exception);
};
