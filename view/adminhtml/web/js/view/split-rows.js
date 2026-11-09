/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Draws the long changed values of the entity diff panel as split diffs
 *
 * The server marks each such field row with data-split="1" and prints its two values in a JSON block inside the row;
 * the values are read from that block, which holds each value exactly as it was stored, never from the cells' text,
 * which the browser's HTML parser has already changed. The row's two value cells give way to one cell spanning both,
 * holding the split diff. A row is left as the server drew it when its block is missing or malformed, when its values
 * show no change line by line, or when the panel lacks the wording. The wording comes from the panel's
 * data-fold-label (with "%1" for the number of lines a fold hides, written the way the page's language writes
 * numbers) and data-filler-label. A DOM adapter with no listeners: it builds with the panel's own document.
 */
define([
    'Hryvinskyi_ActivityLog/js/model/split-diff',
    'Hryvinskyi_ActivityLog/js/view/split-diff'
], function (splitDiff, splitDiffView) {
    'use strict';

    var ROWS = '[data-role="field-row"][data-split="1"]',
        VALUES = '[data-role="split-values"]',
        DRAWN = 'is-split';

    /**
     * @param {HTMLElement} panel
     * @returns {?{fold: string, filler: string}} The table's wording, or null when the panel does not carry it
     */
    function wordingOf(panel) {
        var fold = panel.getAttribute('data-fold-label'),
            filler = panel.getAttribute('data-filler-label');

        return fold === null || filler === null ? null : {fold: fold, filler: filler};
    }

    /**
     * How the page writes a number: the way its language (the lang of its root element) does, or as the plain digits
     * when the page names no language or one Intl does not know
     *
     * @param {Document} doc
     * @returns {function(number): string}
     */
    function numberWriterOf(doc) {
        var root = doc.documentElement,
            lang = root ? root.getAttribute('lang') || '' : '',
            format;

        if (lang === '') {
            return String;
        }
        try {
            format = new Intl.NumberFormat(lang);
        } catch (unknownLanguage) {
            return String;
        }

        return function (value) {
            return format.format(value);
        };
    }

    /**
     * @param {?HTMLElement} block The row's JSON block
     * @returns {?{before: string, after: string}} Both values, or null when the block is missing or not that shape
     */
    function valuesOf(block) {
        var parsed;

        if (block === null) {
            return null;
        }
        try {
            parsed = JSON.parse(block.textContent || '');
        } catch (error) {
            return null;
        }
        if (parsed === null || typeof parsed !== 'object'
            || typeof parsed.before !== 'string' || typeof parsed.after !== 'string') {
            return null;
        }

        return {before: parsed.before, after: parsed.after};
    }

    /**
     * @param {HTMLElement} row
     * @returns {Array<HTMLElement>} The row's value cells: its last two <td>s
     */
    function valueCells(row) {
        return Array.prototype.filter.call(row.children, function (cell) {
            return cell.tagName === 'TD';
        }).slice(-2);
    }

    /**
     * Replaces one row's two value cells with its split diff
     *
     * @param {HTMLElement} row
     * @param {{fold: string, filler: string}} words
     * @param {function(number): string} formatNumber How a fold's count of lines is written
     * @returns {boolean} Whether the row was drawn
     */
    function drawRow(row, words, formatNumber) {
        var values = valuesOf(row.querySelector(VALUES)),
            doc = row.ownerDocument,
            cells = valueCells(row),
            model,
            cell;

        if (values === null || cells.length !== 2) {
            console.error('The values of field "' + (row.getAttribute('data-field') || '')
                + '" cannot be drawn side by side; it keeps its two cells.');

            return false;
        }
        model = splitDiff.split(values.before, values.after);
        if (model.changes === 0) {
            return false;
        }
        cells.forEach(function (old) {
            old.remove();
        });
        cell = doc.createElement('td');
        cell.className = 'admin-action-diff__split';
        cell.setAttribute('colspan', '2');
        cell.appendChild(splitDiffView.render(doc, model, words, {formatNumber: formatNumber}));
        row.appendChild(cell);
        row.classList.add(DRAWN);

        return true;
    }

    /**
     * Draws every marked row of the panel not drawn yet
     *
     * @param {HTMLElement} panel The entity diff panel
     * @returns {number} How many rows were drawn
     */
    function draw(panel) {
        var words = wordingOf(panel),
            formatNumber = numberWriterOf(panel.ownerDocument),
            drawn = 0;

        if (words === null) {
            return 0;
        }
        panel.querySelectorAll(ROWS).forEach(function (row) {
            if (!row.classList.contains(DRAWN) && drawRow(row, words, formatNumber)) {
                drawn += 1;
            }
        });

        return drawn;
    }

    return Object.freeze({
        draw: draw
    });
});
