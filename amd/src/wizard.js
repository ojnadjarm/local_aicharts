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
 * Opens the series modal of the chart wizard, reloads the page once a series is saved and shows a status
 * line while the assistant or a query runs.
 *
 * @module     local_aicharts/wizard
 * @copyright  2026 Oscar Nadjar
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalEvents from 'core/modal_events';
import ModalForm from 'core_form/modalform';
import {getString} from 'core/str';

const SELECTORS = {
    wizard: '[data-region="aic-wizard"]',
    opener: '[data-action="addseries"], [data-action="editseries"]',
    stepform: 'form.local-aicharts-step',
    header: '[data-region="header"]',
    running: '[data-region="aic-running"]',
    runningtext: '[data-region="aic-running-text"]',
};

const TICK = 1000;

const TYPICAL = 30;

let initialised = false;

/**
 * Show the series form for a new or an existing series.
 *
 * @param {HTMLElement} trigger The button that was clicked.
 * @param {HTMLElement} wizard The wizard root carrying the token, the step URL and the timeouts.
 */
const showForm = (trigger, wizard) => {
    const index = trigger.dataset.action === 'editseries' ? parseInt(trigger.dataset.index, 10) : -1;

    const modalForm = new ModalForm({
        formClass: 'local_aicharts\\form\\series_form',
        args: {w: wizard.dataset.token, index},
        modalConfig: {
            title: getString(index < 0 ? 'addseries' : 'editseries', 'local_aicharts'),
            large: true,
        },
        saveButtonText: getString('saveseries', 'local_aicharts'),
        returnFocus: trigger,
    });

    modalForm.addEventListener(modalForm.events.FORM_SUBMITTED, () => window.location.assign(wizard.dataset.url));
    modalForm.addEventListener(modalForm.events.NOSUBMIT_BUTTON_PRESSED, (event) => {
        showRunning(modalForm.modal, wizard, event.detail);
    });

    modalForm.show();
};

/**
 * The status line and the longest wait for a pressed no-submit button.
 *
 * @param {HTMLElement|null} button The button that was pressed.
 * @param {HTMLElement} wizard The wizard root carrying the assistant and query timeouts.
 * @return {{key: string, max: number}} The string key and the wait in seconds.
 */
const stripText = (button, wizard) => {
    const querytimeout = parseInt(wizard.dataset.querytimeout, 10) || 0;
    if (button?.name === 'generate') {
        return {key: 'generating', max: (parseInt(wizard.dataset.timeout, 10) || 0) + querytimeout};
    }
    return {key: 'runningqueries', max: querytimeout};
};

/**
 * A status line counting the elapsed seconds for a pressed no-submit button.
 *
 * @param {HTMLElement} wizard The wizard root carrying the assistant and query timeouts.
 * @param {HTMLElement|null} button The no-submit button that was pressed.
 * @return {{strip: HTMLElement, stop: Function}} The line and the function that removes it.
 */
const createStrip = (wizard, button) => {
    const strip = document.createElement('div');
    strip.className = 'local-aicharts-generating d-flex align-items-center gap-2';
    strip.dataset.region = 'aic-running';
    strip.setAttribute('role', 'status');
    strip.setAttribute('aria-live', 'polite');
    strip.innerHTML = '<i class="icon fa fa-spinner fa-spin m-0" aria-hidden="true"></i>' +
        '<span data-region="aic-running-text"></span>';

    const started = Date.now();
    const label = strip.querySelector(SELECTORS.runningtext);
    const {key, max} = stripText(button, wizard);
    const tick = async() => {
        const seconds = Math.round((Date.now() - started) / TICK);
        label.textContent = await getString(key, 'local_aicharts', {typical: TYPICAL, max, seconds});
    };
    const timer = window.setInterval(tick, TICK);
    tick();

    const stop = () => {
        window.clearInterval(timer);
        strip.remove();
    };
    return {strip, stop};
};

/**
 * Show the status line under the modal header while the assistant or the query runs and the form is rebuilt.
 *
 * @param {Modal} modal The series modal.
 * @param {HTMLElement} wizard The wizard root carrying the assistant and query timeouts.
 * @param {HTMLElement|null} button The no-submit button that was pressed.
 */
const showRunning = (modal, wizard, button) => {
    const dialogue = modal.getModal()[0];
    const header = dialogue.querySelector(SELECTORS.header);
    if (!header || header.parentNode.querySelector(SELECTORS.running)) {
        return;
    }
    dialogue.focus();

    const {strip, stop} = createStrip(wizard, button);
    header.insertAdjacentElement('afterend', strip);
    modal.getRoot().one(ModalEvents.bodyRendered, stop);
    modal.getRoot().one(ModalEvents.hidden, stop);
};

/**
 * Show the status line above the step form while the assistant fills the chart controls.
 *
 * @param {HTMLFormElement} form The step form being submitted.
 * @param {HTMLElement} wizard The wizard root carrying the assistant and query timeouts.
 * @param {HTMLElement} button The Ask the assistant button.
 */
const showRunningOnPage = (form, wizard, button) => {
    const column = form.parentNode;
    if (column.querySelector(SELECTORS.running)) {
        return;
    }
    const {strip} = createStrip(wizard, button);
    strip.classList.add('mb-3');
    column.insertAdjacentElement('afterbegin', strip);
};

/**
 * Listen for the buttons that open the series modal.
 */
export const init = () => {
    if (initialised) {
        return;
    }
    initialised = true;

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest(SELECTORS.opener);
        const wizard = trigger?.closest(SELECTORS.wizard);
        if (!trigger || !wizard) {
            return;
        }
        event.preventDefault();
        showForm(trigger, wizard);
    });

    document.addEventListener('submit', (event) => {
        const form = event.target.closest(SELECTORS.stepform);
        const wizard = form?.closest(SELECTORS.wizard);
        if (!wizard || event.submitter?.name !== 'generate') {
            return;
        }
        showRunningOnPage(form, wizard, event.submitter);
    });
};
