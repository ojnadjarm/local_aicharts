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
 * Runs the card actions of the dashboard.
 *
 * @module     local_aicharts/dashboard
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {getString} from 'core/str';

const SELECTORS = {
    runnow: '[data-action="runnow"][data-id]',
    togglechart: '[data-action="togglechart"][data-id]',
    deletechart: '[data-action="deletechart"][data-id]',
    filter: '[data-region="aic-filter"]',
    item: '[data-region="aic-card"], [data-region="aic-row"]',
    nomatch: '[data-region="aic-nomatch"]',
    count: '[data-region="aic-count"]',
};

const FILTER_DELAY = 200;

let initialised = false;

/**
 * Queue a manual run of one scheduled chart, then show the result of the request.
 *
 * @param {HTMLElement} trigger The Run now button or menu item that was clicked.
 */
const runNow = async(trigger) => {
    trigger.setAttribute('aria-disabled', 'true');
    try {
        await Ajax.call([{
            methodname: 'local_aicharts_run_chart_now',
            args: {chartid: parseInt(trigger.dataset.id, 10)},
        }])[0];
        window.location.reload();
    } catch (error) {
        trigger.removeAttribute('aria-disabled');
        Notification.exception(error);
    }
};

/**
 * Pause or resume one scheduled chart, then show the new state of the card.
 *
 * @param {HTMLElement} trigger The Pause or Resume menu item that was clicked.
 */
const toggleChart = async(trigger) => {
    trigger.setAttribute('aria-disabled', 'true');
    try {
        await Ajax.call([{
            methodname: 'local_aicharts_set_chart_enabled',
            args: {
                chartid: parseInt(trigger.dataset.id, 10),
                enabled: trigger.dataset.enabled === '1',
            },
        }])[0];
        window.location.reload();
    } catch (error) {
        trigger.removeAttribute('aria-disabled');
        Notification.exception(error);
    }
};

/**
 * Ask before deleting one chart, then delete it with its stored results.
 *
 * @param {HTMLElement} trigger The Delete menu item that was clicked.
 */
const deleteChart = (trigger) => {
    Notification.deleteCancel(
        getString('deletechart', 'local_aicharts'),
        getString('deletechartconfirm', 'local_aicharts', trigger.dataset.name),
        getString('delete'),
        async() => {
            try {
                await Ajax.call([{
                    methodname: 'local_aicharts_delete_chart',
                    args: {chartid: parseInt(trigger.dataset.id, 10)},
                }])[0];
                window.location.reload();
            } catch (error) {
                Notification.exception(error);
            }
        },
        null,
        {triggerElement: trigger}
    );
};

/**
 * Show only the items whose name contains the filter text.
 *
 * @param {string} value The text typed in the filter field.
 */
const applyFilter = async(value) => {
    const needle = value.trim().toLowerCase();
    const items = document.querySelectorAll(SELECTORS.item);
    let shown = 0;

    items.forEach((item) => {
        const match = needle === '' || (item.dataset.name || '').indexOf(needle) !== -1;
        item.hidden = !match;
        if (match) {
            shown++;
        }
    });

    const nomatch = document.querySelector(SELECTORS.nomatch);
    if (nomatch) {
        nomatch.hidden = needle === '' || shown > 0;
    }

    const count = document.querySelector(SELECTORS.count);
    if (count) {
        count.hidden = needle === '' || shown === 0;
        if (!count.hidden) {
            count.textContent = await getString('filtercount', 'local_aicharts', {shown, total: items.length});
        }
    }
};

/**
 * Filter the items as the user types, without reloading the page.
 */
const initFilter = () => {
    const field = document.querySelector(SELECTORS.filter);
    if (!field) {
        return;
    }

    let timer = null;
    field.addEventListener('input', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(() => applyFilter(field.value), FILTER_DELAY);
    });
};

/**
 * Listen for the dashboard buttons that act on a card.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('click', (event) => {
        const runner = event.target.closest(SELECTORS.runnow);
        if (runner) {
            event.preventDefault();
            if (!runner.hasAttribute('aria-disabled')) {
                runNow(runner);
            }
            return;
        }

        const toggler = event.target.closest(SELECTORS.togglechart);
        if (toggler) {
            event.preventDefault();
            if (!toggler.hasAttribute('aria-disabled')) {
                toggleChart(toggler);
            }
            return;
        }

        const remover = event.target.closest(SELECTORS.deletechart);
        if (remover) {
            event.preventDefault();
            deleteChart(remover);
            return;
        }
    });

    initFilter();
};
