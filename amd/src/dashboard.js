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
 * Opens the chart form in a modal and loads deferred charts from the dashboard.
 *
 * @module     local_aicharts/dashboard
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Config from 'core/config';
import Fragment from 'core/fragment';
import ModalEvents from 'core/modal_events';
import ModalForm from 'core_form/modalform';
import Notification from 'core/notification';
import Templates from 'core/templates';
import {getString} from 'core/str';

const SELECTORS = {
    launcher: '[data-action="addchart"], [data-action="editchart"][data-id], [data-action="duplicatechart"][data-id]',
    loader: '[data-action="loadchart"][data-id]',
    runnow: '[data-action="runnow"][data-id]',
    togglechart: '[data-action="togglechart"][data-id]',
    deletechart: '[data-action="deletechart"][data-id]',
    card: '[data-region="aic-card"]',
    cardbody: '[data-region="aic-card-body"]',
    deferrednote: '[data-region="aic-deferred-note"]',
    preview: '[data-region="aic-preview"]',
    filter: '[data-region="aic-filter"]',
    item: '[data-region="aic-card"], [data-region="aic-row"]',
    nomatch: '[data-region="aic-nomatch"]',
    count: '[data-region="aic-count"]',
    header: '[data-region="header"]',
    closer: '[data-action="hide"], [data-action="cancel"]',
    chartjson: 'input[name="chartjson"]',
    prompt: 'textarea[name="prompt"]',
    generating: '[data-region="aic-generating"]',
    generatingtext: '[data-region="aic-generating-text"]',
};

const FILTER_DELAY = 200;

const TICK = 1000;

let initialised = false;

/**
 * Show the chart form for a new or an existing chart.
 *
 * @param {HTMLElement} trigger The button that was clicked.
 */
const showForm = (trigger) => {
    const id = parseInt(trigger.dataset.id, 10) || 0;
    const duplicate = trigger.dataset.action === 'duplicatechart' ? 1 : 0;
    let title = 'addchart';
    if (id) {
        title = duplicate ? 'duplicatechart' : 'editchart';
    }

    const modalForm = new ModalForm({
        formClass: 'local_aicharts\\form\\chart_form',
        args: {id, duplicate},
        modalConfig: {
            title: getString(title, 'local_aicharts'),
            large: true,
        },
        saveButtonText: getString('savechanges'),
        returnFocus: trigger,
    });

    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => window.location.reload());

    const state = {generating: false};
    const guard = (event) => guardClose(event, modalForm, state);
    modalForm.addEventListener('click', guard, true);
    modalForm.addEventListener('keydown', guard, true);

    modalForm.addEventListener(modalForm.events.NOSUBMIT_BUTTON_PRESSED, () => showGenerating(modalForm.modal, state));

    modalForm.show();
};

/**
 * Whether the form holds a generated preview or an edited prompt.
 *
 * @param {HTMLElement} root The modal root.
 * @return {boolean} Whether closing would lose something.
 */
const hasUnsavedWork = (root) => {
    const chartjson = root.querySelector(SELECTORS.chartjson);
    if (chartjson && chartjson.value !== '') {
        return true;
    }

    const prompt = root.querySelector(SELECTORS.prompt);
    return !!prompt && prompt.value !== prompt.defaultValue;
};

/**
 * Ask before the close button, Cancel or Escape throws away a preview.
 *
 * @param {Event} event The click or keydown seen on the modal root.
 * @param {ModalForm} modalForm The chart form.
 * @param {Object} state Whether a generation is running, in which case Escape cancels it.
 */
const guardClose = (event, modalForm, state) => {
    if (state.generating || (event.type === 'keydown' ? event.key !== 'Escape' : !event.target.closest(SELECTORS.closer))) {
        return;
    }

    const root = modalForm.modal.getRoot()[0];
    if (!hasUnsavedWork(root)) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();
    Notification.saveCancel(
        getString('discardpreview', 'local_aicharts'),
        getString('discardpreviewconfirm', 'local_aicharts'),
        getString('discard', 'local_aicharts'),
        () => modalForm.modal.destroy()
    );
};

/**
 * Show a status line with the elapsed seconds while the model is asked.
 *
 * @param {Modal} modal The chart modal.
 * @param {Object} state The state shared with the close guard.
 */
const showGenerating = async(modal, state) => {
    const dialogue = modal.getModal()[0];
    const header = dialogue.querySelector(SELECTORS.header);
    if (!header || header.parentNode.querySelector(SELECTORS.generating)) {
        return;
    }

    // The pressed button is about to be replaced, so keep the focus in the dialogue for Escape.
    dialogue.focus();

    const strip = document.createElement('div');
    strip.className = 'local-aicharts-generating d-flex align-items-center gap-2';
    strip.dataset.region = 'aic-generating';
    strip.setAttribute('role', 'status');
    strip.setAttribute('aria-live', 'polite');
    strip.innerHTML = '<i class="icon fa fa-spinner fa-spin m-0" aria-hidden="true"></i>' +
        '<span data-region="aic-generating-text"></span>';
    header.insertAdjacentElement('afterend', strip);

    const started = Date.now();
    const label = strip.querySelector(SELECTORS.generatingtext);
    const tick = async() => {
        const seconds = Math.round((Date.now() - started) / TICK);
        label.textContent = await getString('generating', 'local_aicharts', seconds);
    };
    const timer = window.setInterval(tick, TICK);
    tick();

    state.generating = true;
    const stop = () => {
        state.generating = false;
        window.clearInterval(timer);
        strip.remove();
    };
    modal.getRoot().one(ModalEvents.bodyRendered, stop);
    modal.getRoot().one(ModalEvents.hidden, stop);
};

/**
 * Run one deferred chart and put its result in the card.
 *
 * @param {HTMLElement} trigger The Load chart button that was clicked.
 */
const loadChart = async(trigger) => {
    const card = trigger.closest(SELECTORS.card);
    const body = card?.querySelector(SELECTORS.cardbody);
    if (!body) {
        return;
    }

    trigger.disabled = true;
    try {
        // The fragment promise resolves with two arguments, so it cannot be awaited directly.
        await Fragment.loadFragment('local_aicharts', 'card', Config.contextid, {id: parseInt(trigger.dataset.id, 10)})
            .then((html, js) => Templates.replaceNodeContents(body, html, js));
        card.querySelector(SELECTORS.deferrednote)?.remove();
    } catch (error) {
        trigger.disabled = false;
        Notification.exception(error);
    }
};

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
 * Listen for the dashboard buttons that open the chart form or load a chart.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('click', (event) => {
        const loader = event.target.closest(SELECTORS.loader);
        if (loader) {
            event.preventDefault();
            loadChart(loader);
            return;
        }

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

        const trigger = event.target.closest(SELECTORS.launcher);
        if (!trigger) {
            return;
        }
        event.preventDefault();
        showForm(trigger);
    });

    initFilter();
};

/**
 * Move the focus to the preview heading of a freshly generated chart.
 */
export const focusPreview = () => {
    document.querySelector(SELECTORS.preview)?.focus();
};
