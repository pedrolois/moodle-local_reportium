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
 * States table of the plugin settings: add and remove rows, live preview of each state label.
 *
 * @module     local_reportium/admin_states
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Readable text colour on a background (same rule as instructions::text_color).
 *
 * @param {String} hex #rrggbb
 * @returns {String}
 */
const textColor = (hex) => {
    const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.substr(i, 2), 16));
    return (0.2126 * r + 0.7152 * g + 0.0722 * b) / 255 > 0.6 ? '#1d2125' : '#ffffff';
};

/**
 * Update the preview label of a row from its fields.
 *
 * @param {HTMLElement} row
 */
const refresh = (row) => {
    const preview = row.querySelector('[data-region="preview"]');
    const code = row.querySelector('input[name$="[code][]"]').value.trim();
    const label = row.querySelector('input[name$="[label][]"]').value.trim();
    const color = row.querySelector('input[name$="[color][]"]').value;
    if (preview.dataset.code === undefined) {
        // The label the server gave (translated name of the code) is kept while the code does not change.
        preview.dataset.code = code;
        preview.dataset.name = preview.textContent;
    }
    preview.textContent = label || (code === preview.dataset.code ? preview.dataset.name : '') || code;
    preview.hidden = preview.textContent === '';
    preview.style.backgroundColor = color;
    preview.style.color = textColor(color);
};

/**
 * Set up the states table.
 *
 * @param {String} id element id of the table wrapper
 */
export const init = (id) => {
    const root = document.getElementById(id);
    if (!root) {
        return;
    }
    const rows = root.querySelector('[data-region="rows"]');
    rows.querySelectorAll('[data-region="state-row"]').forEach(refresh);
    root.addEventListener('click', (e) => {
        if (e.target.closest('[data-action="add-state"]')) {
            const template = root.querySelector('[data-region="row-template"]');
            const row = template.content.firstElementChild.cloneNode(true);
            rows.appendChild(row);
            refresh(row);
            row.querySelector('input').focus();
        }
        const remove = e.target.closest('[data-action="remove-state"]');
        if (remove) {
            remove.closest('[data-region="state-row"]').remove();
        }
    });
    root.addEventListener('input', (e) => {
        const row = e.target.closest('[data-region="state-row"]');
        if (row) {
            refresh(row);
        }
    });
};
