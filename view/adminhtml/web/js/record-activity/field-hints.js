/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Hints under the product fields changed in the last days: "Changed 40 minutes ago by john · 34.00 → 39.00 ·
 * History" (record-activity/hint-model.js holds the rules), written as text under the field's control.
 *
 * A field is found by the data-index Magento's form fields render, inside the container the product form puts each
 * attribute in ("container_<code>"), which keeps option rows and other lists with a field of the same name out; the
 * code goes into those selectors escaped, so whatever it holds it names a field and nothing else. Forms draw a section only when it opens, so fields drawn later get their hint as they appear; a field gets one
 * hint however often it is looked at. "History" asks the record's activity panel to open on that field
 * (record-activity/open-event.js).
 *
 * Public surface: mount(doc, rawConfig) returns the function that removes the hints and stops watching.
 */
define([
    'Hryvinskyi_ActivityLog/js/search/clock',
    'Hryvinskyi_ActivityLog/js/search/days',
    'Hryvinskyi_AdminActionLog/js/record-activity/hint-model',
    'Hryvinskyi_AdminActionLog/js/record-activity/open-event'
], function (clockModule, days, hintModel, openEvent) {
    'use strict';

    var HISTORY = 'record-activity-history';

    /**
     * @param {Document} doc
     * @param {*} rawConfig The hints config the product form passed on
     * @returns {Function} Removes the hints
     */
    function mount(doc, rawConfig) {
        var config = hintModel.parseConfig(rawConfig),
            view = doc.defaultView,
            clock = clockModule.create(config.serverNowMs, Date.now(), Date.now),
            relativeTime = new Intl.RelativeTimeFormat(config.locale.replace(/_/g, '-'), {numeric: 'auto'}),
            lifetime = new AbortController(),
            hinted = new WeakSet(),
            placed = [],
            pending = false,
            observer;

        /**
         * @param {number} ms
         * @returns {string}
         */
        function relative(ms) {
            var distance = days.ago(ms, clock.now());

            return relativeTime.format(distance.value, distance.unit);
        }

        /**
         * @param {string} tagName
         * @param {string} className
         * @param {string=} text
         * @returns {HTMLElement}
         */
        function element(tagName, className, text) {
            var node = doc.createElement(tagName);

            node.className = className;
            if (text !== undefined) {
                node.textContent = text;
            }

            return node;
        }

        /**
         * @param {{text: string, empty: boolean}} value
         * @param {string} side "before" or "after"
         * @returns {HTMLElement}
         */
        function valueOf(value, side) {
            return element(
                'span',
                'record-activity-hint__value record-activity-hint__value--' + side
                    + (value.empty ? ' record-activity-hint__value--empty' : ''),
                value.text
            );
        }

        /**
         * @param {Object} hint
         * @returns {HTMLElement}
         */
        function render(hint) {
            var parts = hintModel.parts(hint, config.wording, relative),
                box = element('div', 'record-activity-hint'),
                history = element('button', 'record-activity-hint__history', parts.history);

            box.setAttribute('data-role', 'record-activity-hint');
            box.appendChild(element('span', '', parts.lead));
            box.appendChild(element('strong', 'record-activity-hint__actor', parts.actor));
            box.appendChild(element('span', '', parts.trail));
            if (parts.values !== null) {
                box.appendChild(element('span', 'record-activity-hint__muted', ' · '));
                box.appendChild(valueOf(parts.values.before, 'before'));
                box.appendChild(element('span', 'record-activity-hint__muted', ' → '));
                box.appendChild(valueOf(parts.values.after, 'after'));
            }
            if (parts.masked !== null) {
                box.appendChild(element('span', 'record-activity-hint__muted', ' · ' + parts.masked));
            }
            box.appendChild(element('span', 'record-activity-hint__muted', ' · '));
            history.setAttribute('type', 'button');
            history.setAttribute('data-action', HISTORY);
            history.setAttribute('data-field', hint.field);
            history.setAttribute('aria-label', parts.historyLabel);
            box.appendChild(history);

            return box;
        }

        /** Gives every drawn field that changed its hint, once. */
        function place() {
            pending = false;
            config.hints.forEach(function (hint) {
                var code = view.CSS.escape(hint.field);

                doc.querySelectorAll('[data-index="container_' + code + '"]').forEach(function (container) {
                    container.querySelectorAll('[data-index="' + code + '"]').forEach(function (field) {
                        var control;

                        if (hinted.has(field)) {
                            return;
                        }
                        hinted.add(field);
                        control = field.querySelector('.admin__field-control') || field;
                        placed.push(control.appendChild(render(hint)));
                    });
                });
            });
        }

        /** Looks for new fields once the page has finished drawing what it is drawing. */
        function schedule() {
            if (!pending) {
                pending = true;
                view.requestAnimationFrame(place);
            }
        }

        doc.addEventListener('click', function (event) {
            var control = event.target instanceof view.Element ? event.target.closest('[data-action="' + HISTORY + '"]')
                : null;

            if (control !== null) {
                event.preventDefault();
                doc.dispatchEvent(new view.CustomEvent(openEvent.NAME, {
                    detail: openEvent.detail(control.getAttribute('data-field'))
                }));
            }
        }, {signal: lifetime.signal});

        observer = new view.MutationObserver(schedule);
        observer.observe(doc.body, {childList: true, subtree: true});
        schedule();

        return function unmount() {
            observer.disconnect();
            lifetime.abort();
            placed.forEach(function (node) {
                node.remove();
            });
        };
    }

    return Object.freeze({mount: mount});
});
