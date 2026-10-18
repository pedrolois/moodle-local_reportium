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
 * Instructions file (AGENTS.md) of a report for an AI chat outside Moodle, shown under the SQL editor when the
 * site has no AI provider.
 *
 * @module     local_reportium/aihelp
 * @copyright  2026 Pedro Luis (Reportium)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';

/**
 * Escape text for HTML.
 *
 * @param {String} s
 * @returns {String}
 */
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

/**
 * Without an AI provider on the site: an instructions file (AGENTS.md) of the report to copy or download and
 * paste into any AI chat outside Moodle. Its content comes from the web service local_reportium_chat_prompt_file.
 */
export default class AiHelp {
    /**
     * Constructor.
     *
     * @param {Object} config {reportid, courseid, title, text, admintext, copy, download, copied, usesql, example}
     * @param {Function} getLevel level being edited in the SQL Inspector
     */
    constructor(config, getLevel) {
        this.config = config;
        this.getLevel = getLevel;
        const c = config;
        this.element = document.createElement('div');
        this.element.className = 'reportium-sqlchat reportium-aihelp';
        this.element.setAttribute('data-region', 'ai-help');
        this.element.innerHTML = `
            <div class="reportium-sqlchat-header"><i class="fa fa-magic" aria-hidden="true"></i>
                <div><strong>${esc(c.title)}</strong></div></div>
            <p class="reportium-aihelp-text">${esc(c.text)}</p>
            ${c.admintext ? `<p class="reportium-aihelp-admin small">${c.admintext}</p>` : ''}
            <div class="reportium-aihelp-actions">
                <button type="button" class="btn btn-sm btn-primary" data-action="ai-help-copy">
                    <i class="fa fa-copy" aria-hidden="true"></i> ${esc(c.copy)}</button>
                <button type="button" class="btn btn-sm btn-outline-primary" data-action="ai-help-download">
                    <i class="fa fa-download" aria-hidden="true"></i> ${esc(c.download)}</button>
            </div>
            <p class="small text-muted mb-1">${esc(c.usesql)}</p>
            ${c.example ? `<p class="small mb-0 reportium-aihelp-example">
                <i class="fa fa-lightbulb-o fa-lightbulb" aria-hidden="true"></i> ${esc(c.example)}</p>` : ''}
            <div class="small text-success" data-region="ai-help-status" role="status"></div>`;
        this.element.addEventListener('click', (e) => {
            if (e.target.closest('[data-action="ai-help-copy"]')) {
                this.file().then((file) => this.copy(file.content)).catch(() => null);
            } else if (e.target.closest('[data-action="ai-help-download"]')) {
                this.file().then((file) => this.download(file)).catch(() => null);
            }
        });
    }

    /**
     * The instructions file of the report, for the level being edited.
     *
     * @returns {Promise<Object>} {filename, content}
     */
    file() {
        return Ajax.call([{
            methodname: 'local_reportium_chat_prompt_file',
            args: {reportid: this.config.reportid, courseid: this.config.courseid, level: this.getLevel()},
        }])[0];
    }

    /**
     * Copy text to the clipboard (with a fallback for browsers without the clipboard API).
     *
     * @param {String} text
     */
    async copy(text) {
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            const area = document.createElement('textarea');
            area.value = text;
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        this.element.querySelector('[data-region="ai-help-status"]').textContent = this.config.copied;
    }

    /**
     * Save the file.
     *
     * @param {Object} file {filename, content}
     */
    download(file) {
        const url = URL.createObjectURL(new Blob([file.content], {type: 'text/markdown'}));
        const link = document.createElement('a');
        link.href = url;
        link.download = file.filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }
}
