/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The entity diff panel: filters rows by field name, shows unchanged rows on request, keeps each scope's row count
 * current and expands clipped values. Wording comes from the markup.
 */
define(['Hryvinskyi_AdminActionLog/js/model/field-filter'], function (fieldFilter) {
    'use strict';

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
            var button = event.target instanceof Element ? event.target.closest('[data-role="expand"]') : null,
                row = button ? button.closest('tr') : null,
                expanded;

            if (!button || !row) {
                return;
            }

            expanded = row.classList.toggle('is-expanded');
            if (!button.hasAttribute('data-expand-label')) {
                button.setAttribute('data-expand-label', button.textContent || '');
            }
            button.textContent = button.getAttribute(expanded ? 'data-collapse-label' : 'data-expand-label') || '';
        });

        if (filter) {
            filter.addEventListener('input', apply);
        }
        if (toggle) {
            toggle.addEventListener('change', apply);
        }

        apply();
    };
});
