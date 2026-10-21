/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Which rows of an entity diff are shown: unchanged rows only when asked for, and only rows whose field name
 * contains the filter text (case does not matter). Pure: the caller passes plain values.
 */
define([], function () {
    'use strict';

    /**
     * @param {string} query
     * @returns {string}
     */
    function normalize(query) {
        return String(query || '').trim().toLowerCase();
    }

    /**
     * @param {string} field
     * @param {string} query
     * @returns {boolean}
     */
    function matches(field, query) {
        var needle = normalize(query);

        return needle === '' || String(field).toLowerCase().indexOf(needle) !== -1;
    }

    /**
     * @param {{field: string, changed: boolean}} row
     * @param {string} query
     * @param {boolean} showUnchanged
     * @returns {boolean}
     */
    function isVisible(row, query, showUnchanged) {
        return (row.changed || showUnchanged === true) && matches(row.field, query);
    }

    return Object.freeze({matches: matches, isVisible: isVisible});
});
