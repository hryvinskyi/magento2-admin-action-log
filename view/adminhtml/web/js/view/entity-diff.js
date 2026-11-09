/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The entity diff panel: draws long changed values as split diffs and opens their folds, filters rows by field name,
 * shows unchanged rows on request, keeps each scope's row count current and expands clipped values. Wording comes
 * from the markup.
 */
define([
    'Hryvinskyi_AdminActionLog/js/model/field-filter',
    'Hryvinskyi_AdminActionLog/js/view/split-rows',
    'Hryvinskyi_ActivityLog/js/view/split-diff'
], function (fieldFilter, splitRows, splitDiffView) {
    'use strict';

    /**
     * What a click on each of the panel's controls does, by the control's selector: a fold of a split diff opens, a
     * clipped value expands or collapses
     */
    var CONTROLS = Object.freeze([
        Object.freeze({selector: '[data-action="fold"]', run: splitDiffView.expand}),
        Object.freeze({selector: '[data-role="expand"]', run: toggleClipped})
    ]);

    /**
     * Expands or collapses a clipped value, and swaps the button's label to match
     *
     * @param {HTMLElement} button
     */
    function toggleClipped(button) {
        var row = button.closest('tr'),
            expanded;

        if (!row) {
            return;
        }
        expanded = row.classList.toggle('is-expanded');
        if (!button.hasAttribute('data-expand-label')) {
            button.setAttribute('data-expand-label', button.textContent || '');
        }
        button.textContent = button.getAttribute(expanded ? 'data-collapse-label' : 'data-expand-label') || '';
    }

    /**
     * @param {Object} config
     * @param {HTMLElement} element
     */
    return function (config, element) {
        var filter = element.querySelector('[data-role="field-filter"]'),
            toggle = element.querySelector('[data-role="show-unchanged"]'),
            noMatch = element.querySelector('[data-role="no-match"]');

        /**
         * @param {HTMLElement} row
         * @returns {boolean}
         */
        function rowVisible(row) {
            return fieldFilter.isVisible(
                {field: row.getAttribute('data-field') || '', changed: row.getAttribute('data-changed') === '1'},
                filter ? filter.value : '',
                Boolean(toggle && toggle.checked)
            );
        }

        /**
         * @param {HTMLElement} group
         * @returns {number} Rows left visible in the group
         */
        function applyToGroup(group) {
            var shown = 0,
                count = group.querySelector('[data-role="scope-count"]');

            group.querySelectorAll('[data-role="field-row"]').forEach(function (row) {
                var visible = rowVisible(row);

                row.hidden = !visible;
                shown += visible ? 1 : 0;
            });

            group.hidden = shown === 0;
            if (count) {
                count.textContent = (count.getAttribute('data-label') || '%1').replace('%1', String(shown));
            }

            return shown;
        }

        function apply() {
            var shown = 0;

            element.querySelectorAll('[data-role="scope-group"]').forEach(function (group) {
                shown += applyToGroup(group);
            });

            if (noMatch) {
                noMatch.hidden = shown !== 0;
            }
        }

        element.addEventListener('click', function (event) {
            CONTROLS.some(function (control) {
                var target = event.target.closest(control.selector);

                if (target === null || !element.contains(target)) {
                    return false;
                }
                control.run(target);

                return true;
            });
        });

        if (filter) {
            filter.addEventListener('input', apply);
        }
        if (toggle) {
            toggle.addEventListener('change', apply);
        }

        splitRows.draw(element);
        apply();
    };
});
