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
 * Reports manager: sortable tables and the search box.
 *
 * @module     local_reportium/main
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Make a table sortable by clicking its headers (or Enter/Space on them): ascending, then descending. Values compare
 * as text with numbers in natural order; a cell may give its own value in data-sortvalue. Headers without text, or
 * with the class "nosort", are not sortable.
 *
 * @param {Object} params {selector}
 */
export const js_order = (params) => {
    const table = document.querySelector(params.selector);
    if (!table || !table.tBodies.length) {
        return;
    }
    const headers = [...table.querySelectorAll('thead th')];
    const collator = new Intl.Collator(undefined, {numeric: true, sensitivity: 'base'});
    const value = (row, index) => {
        const cell = row.cells[index];
        return cell ? (cell.dataset.sortvalue ?? cell.textContent).trim() : '';
    };
    const sort = (th, index) => {
        const ascending = th.getAttribute('aria-sort') !== 'ascending';
        headers.forEach((other) => {
            other.classList.remove('reportium-sort-asc', 'reportium-sort-desc');
            if (other.classList.contains('reportium-sortable')) {
                other.setAttribute('aria-sort', 'none');
            }
        });
        th.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
        th.classList.add(ascending ? 'reportium-sort-asc' : 'reportium-sort-desc');
        const body = table.tBodies[0];
        const rows = [...body.rows];
        rows.sort((a, b) => collator.compare(value(a, index), value(b, index)) * (ascending ? 1 : -1));
        rows.forEach((row) => body.appendChild(row));
    };
    headers.forEach((th, index) => {
        if (!th.textContent.trim() || th.classList.contains('nosort')) {
            return;
        }
        th.classList.add('reportium-sortable');
        th.setAttribute('aria-sort', 'none');
        th.tabIndex = 0;
        th.addEventListener('click', () => sort(th, index));
        th.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                sort(th, index);
            }
        });
    });
};

/**
 * Search box of the Reports manager: filters the rows of every group by their text (name, course, owner), opens the
 * groups with matches, updates their counts, and keeps the search in the URL.
 *
 * @param {Object} params {input: selector of the search box, empty: selector of the "no match" message}
 */
export const search = (params) => {
    const input = document.querySelector(params.input);
    const empty = document.querySelector(params.empty);
    if (!input) {
        return;
    }
    const groups = [...document.querySelectorAll('details.reportium-group')];
    const apply = () => {
        const words = input.value.toLowerCase().split(/\s+/).filter(Boolean);
        let total = 0;
        groups.forEach((group) => {
            const rows = [...group.querySelectorAll('tbody tr')];
            const table = group.querySelector('table');
            const columns = String((table && table.dataset.searchcols) || '0,1,4').split(',').map(Number);
            let shown = 0;
            rows.forEach((row) => {
                // Name, course and owner columns (not the editing controls).
                const text = columns.map((i) => (row.cells[i] ? row.cells[i].textContent : '')).join(' ').toLowerCase();
                const match = words.every((word) => text.includes(word));
                row.hidden = !match;
                shown += match ? 1 : 0;
            });
            total += shown;
            const badge = group.querySelector('summary .badge');
            if (badge) {
                badge.textContent = words.length ? `${shown} / ${rows.length}` : String(rows.length);
            }
            if (words.length) {
                group.open = shown > 0;
            }
        });
        if (empty) {
            empty.hidden = !words.length || total > 0;
        }
        const url = new URL(window.location.href);
        if (input.value.trim()) {
            url.searchParams.set('search', input.value.trim());
        } else {
            url.searchParams.delete('search');
        }
        window.history.replaceState(null, '', url.toString());
    };
    input.addEventListener('input', apply);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            input.value = '';
            apply();
        }
    });
    if (input.value) {
        apply();
    }
};
