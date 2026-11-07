/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Keeps the settings' history buttons usable, whatever disables the controls around them
 *
 * A setting that inherits its value has every control of its value cell disabled by the admin's scope toggle: on
 * load for a table of rows, and on every click of the setting's "Use Default" or "Use Website" checkbox for every
 * setting. The history button sits in that cell, and the history of an inherited setting is still worth opening; the
 * button changes nothing, so it is never disabled. Each button is enabled when this starts, and every time anything
 * sets its `disabled` (the attribute or the property) or gives it the `disabled` class, both are taken back. Mutation
 * observers are called right after the script that made the change, before the page is drawn again and before it
 * takes the next click or key, so the button never looks or acts disabled. The button stays a native button, with its
 * keyboard handling and its accessible name, where it is in the markup.
 *
 * Only the buttons handed over are observed, and only those two attributes of them: the toggle and the other controls
 * of the cell are left as they are. Pure of the page: the buttons, the observer constructor and the signal ending the
 * watch are passed in.
 */
define([], function () {
    'use strict';

    var DISABLED_CLASS = 'disabled',
        WATCHED = Object.freeze(['disabled', 'class']);

    /**
     * @param {HTMLButtonElement} button
     */
    function enable(button) {
        if (button.disabled) {
            button.disabled = false;
        }
        if (button.classList.contains(DISABLED_CLASS)) {
            button.classList.remove(DISABLED_CLASS);
        }
    }

    /**
     * Enable the buttons now, and again whenever something disables them, until the signal aborts
     *
     * @param {Array<HTMLButtonElement>} buttons
     * @param {Function} Observer The page's MutationObserver
     * @param {AbortSignal} signal
     */
    function keep(buttons, Observer, signal) {
        var observer;

        if (buttons.length === 0 || signal.aborted) {
            return;
        }

        observer = new Observer(function (records) {
            records.forEach(function (record) {
                enable(record.target);
            });
        });
        buttons.forEach(function (button) {
            enable(button);
            observer.observe(button, {attributes: true, attributeFilter: WATCHED.slice()});
        });
        signal.addEventListener('abort', function () {
            observer.disconnect();
        }, {once: true});
    }

    return Object.freeze({keep: keep});
});
