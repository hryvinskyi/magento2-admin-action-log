/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Starts the hints under a record's recently changed fields from the element the page prints for them: reads the
 * JSON beside it and hands it to record-activity/field-hints.js. The config is read as JSON and nothing else, so no
 * part of it is evaluated as a template or as markup. A config it cannot read costs the hints, never the page.
 *
 * Public surface: the function the page's data-mage-init calls with (options, element); it returns the function that
 * removes the hints, or a function that does nothing when there were none to write.
 */
define([
    'Hryvinskyi_AdminActionLog/js/record-activity/field-hints'
], function (fieldHints) {
    'use strict';

    var CONFIG = 'script[data-role="record-activity-hints-config"]';

    /**
     * @param {*} options Unused: the config travels in the JSON beside the element
     * @param {HTMLElement} element
     * @returns {Function} Removes the hints
     */
    return function (options, element) {
        var script = element.querySelector(CONFIG);

        try {
            return fieldHints.mount(element.ownerDocument, JSON.parse(script === null ? 'null' : script.textContent));
        } catch (error) {
            console.warn('Record activity hints:', error);

            return function () {};
        }
    };
});
