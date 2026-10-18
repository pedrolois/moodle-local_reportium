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
 * Form of a scheduled email: the level sent, and the row of each level before it (e.g. which category, then
 * which course), read from the report itself. The choice is kept in the hidden "viewstate" field.
 *
 * @module     local_reportium/schedule_form
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Escape text for HTML.
 *
 * @param {String} text
 * @returns {String}
 */
const esc = (text) => String(text ?? '').replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));

/**
 * Rows of a level as options: value of its value column, text of its label column ("main|extra" = "main (extra)").
 *
 * @param {Object} data get_level_data result
 * @returns {Array} [{value, label}]
 */
const optionsOf = (data) => {
    const rows = JSON.parse(data.rows);
    const mapping = JSON.parse(data.mapping);
    const [main, extra] = String(mapping.labelcolumn || '').split('|');
    return rows.map((row) => {
        const value = String(row[mapping.valuecolumn] ?? row[main] ?? '');
        const label = String(row[main] ?? value) + (extra && row[extra] ? ` (${row[extra]})` : '');
        return {value, label};
    }).filter((o) => o.value !== '');
};

/**
 * Wire the form.
 *
 * @param {Object} config {reportid, choose: "Choose…", levels: {level: name}, labels: {level: label}, levelname, nocolumns}
 */
export const init = (config) => {
    const form = document.querySelector('form.mform');
    const levelselect = form ? form.querySelector('select[name="level"]') : null;
    const statefield = form ? form.querySelector('input[name="viewstate"]') : null;
    const region = form ? form.querySelector('[data-region="schedule-path"]') : null;
    if (!levelselect || !statefield || !region) {
        return;
    }
    let state = {};
    try {
        state = JSON.parse(statefield.value || '{}') || {};
        // An empty view may come as [] (PHP json_encode of an empty array).
        if (Array.isArray(state) || typeof state !== 'object') {
            state = {};
        }
    } catch (e) {
        state = {};
    }
    let steps = Array.isArray(state.steps) ? state.steps.filter((s) => s && s.v !== undefined) : [];
    const cache = {};

    const save = (changed) => {
        state.level = parseInt(levelselect.value, 10) || 0;
        state.steps = steps.slice(0, state.level);
        if (changed) {
            // Search, filters and sort belonged to the view chosen before.
            state.changed = true;
        }
        statefield.value = JSON.stringify(state);
    };

    const rowsOf = (index) => {
        const path = steps.slice(0, index).map((s) => String(s.v));
        const key = JSON.stringify(path);
        if (!cache[key]) {
            cache[key] = Ajax.call([{
                methodname: 'local_reportium_get_level_data',
                args: {reportid: config.reportid, level: index, path: path},
            }])[0].then(optionsOf);
        }
        return cache[key];
    };

    /**
     * One select per level before the chosen one; each lists the rows of its level for the rows chosen above it.
     *
     * @returns {Promise}
     */
    const render = () => {
        const level = parseInt(levelselect.value, 10) || 0;
        region.innerHTML = '';
        const next = (index) => {
            if (index >= level) {
                return Promise.resolve();
            }
            // A level can only be listed once the rows above it are chosen.
            if (index > 0 && !(steps[index - 1] && steps[index - 1].v !== '')) {
                return Promise.resolve();
            }
            return rowsOf(index).then((options) => {
                const chosen = steps[index] ? String(steps[index].v) : '';
                const id = `reportium-schedule-step-${index}`;
                const items = options.map((o) => `<option value="${esc(o.value)}"${o.value === chosen ? ' selected' : ''}>
                    ${esc(o.label)}</option>`).join('');
                const wrapper = document.createElement('div');
                wrapper.className = 'mb-2';
                wrapper.innerHTML = `<label for="${id}" class="d-block small text-muted mb-1">
                        ${esc(config.levels[index] ?? index + 1)}</label>
                    <select id="${id}" class="custom-select form-select" data-step="${index}">
                        <option value="">${esc(config.choose)}</option>${items}</select>`;
                region.appendChild(wrapper);
                return next(index + 1);
            });
        };
        return next(0).catch(Notification.exception);
    };

    // Alert: the column of a value condition, from the columns of the chosen level.
    const condtype = form.querySelector('select[name="condtype"]');
    const condfield = form.querySelector('input[name="condcolumn"]');
    const condregion = form.querySelector('[data-region="cond-column"]');
    const condrow = condregion ? condregion.closest('.fitem, .form-group') : null;
    let columnsRequest = 0;
    const renderColumns = () => {
        if (!condtype || !condfield || !condregion) {
            return;
        }
        const shown = condtype.value === 'value';
        if (condrow) {
            condrow.style.display = shown ? '' : 'none';
        }
        if (!shown) {
            return;
        }
        const level = parseInt(levelselect.value, 10) || 0;
        const path = steps.slice(0, level).map((s) => String(s.v));
        if (path.length < level) {
            condregion.innerHTML = `<span class="text-muted small">${esc(config.nocolumns)}</span>`;
            return;
        }
        const request = ++columnsRequest;
        Ajax.call([{
            methodname: 'local_reportium_get_level_data',
            args: {reportid: config.reportid, level: level, path: path},
        }])[0].then((data) => {
            if (request !== columnsRequest) {
                return;
            }
            const chosen = condfield.value;
            const items = data.columns.filter((c) => c.type !== 'id' || c.key === chosen)
                .map((c) => `<option value="${esc(c.key)}"${c.key === chosen ? ' selected' : ''}>${esc(c.label)}</option>`);
            condregion.innerHTML = `<select class="custom-select form-select" data-region="cond-column-select">
                <option value="">${esc(config.choose)}</option>${items.join('')}</select>`;
            return null;
        }).catch(Notification.exception);
    };
    if (condregion) {
        condregion.addEventListener('change', (e) => {
            if (e.target.matches('select[data-region="cond-column-select"]')) {
                condfield.value = e.target.value;
                const label = form.querySelector('input[name="condcolumnlabel"]');
                if (label) {
                    label.value = e.target.value ? e.target.selectedOptions[0].textContent.trim() : '';
                }
            }
        });
    }
    if (condtype) {
        condtype.addEventListener('change', renderColumns);
    }

    /**
     * Names of the levels with the row chosen above them ("Level 2: Earners of AI Champion badge").
     */
    const renameLevels = () => {
        const labels = config.labels || {};
        [...levelselect.options].forEach((option) => {
            const level = parseInt(option.value, 10) || 0;
            const label = String(labels[level] || '');
            const parent = steps[level - 1];
            if (level > 0 && label.includes('{parent}') && parent && parent.l) {
                const name = String(config.levelname).replace('{n}', level + 1);
                option.textContent = `${name}: ${label.replace('{parent}', parent.l)}`;
            } else if (config.levels[level]) {
                option.textContent = config.levels[level];
            }
        });
    };

    levelselect.addEventListener('change', () => {
        save(true);
        render();
        renderColumns();
        renameLevels();
    });
    region.addEventListener('change', (e) => {
        const select = e.target.closest('select[data-step]');
        if (!select) {
            return;
        }
        const index = parseInt(select.dataset.step, 10);
        steps = steps.slice(0, index);
        if (select.value !== '') {
            steps[index] = {v: select.value, l: select.selectedOptions[0].textContent.trim()};
        }
        save(true);
        render();
        renderColumns();
        renameLevels();
    });
    save(false);
    render();
    renderColumns();
    renameLevels();
};
