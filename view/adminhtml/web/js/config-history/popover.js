/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The settings' history on Stores › Configuration: each setting's clock button gets its title, and opens a popover
 * below the setting's row with the setting's changes in every scope (config-history/history-model.js holds the rules,
 * config-history/placement.js the geometry).
 *
 * One popover at a time: opening another closes the first, and so do Esc, the close button and a click outside it.
 * The popover is placed in the setting's value column, right after the button, so the Tab key goes from the button
 * into it; it takes focus when it opens, and Esc and the close button give focus back to the button. Closing drops
 * the search in flight, and an answer for a popover no longer open is ignored. Every request has a deadline;
 * mounting is once per element.
 */
define([
    'Hryvinskyi_ActivityLog/js/search/clock',
    'Hryvinskyi_ActivityLog/js/search/zone',
    'Hryvinskyi_ActivityLog/js/search/days',
    'Hryvinskyi_ActivityLog/js/search/wording',
    'Hryvinskyi_ActivityLog/js/timeline/assets',
    'Hryvinskyi_ActivityLog/js/timeline/search-client',
    'Hryvinskyi_AdminActionLog/js/config-history/history-model',
    'Hryvinskyi_AdminActionLog/js/config-history/placement'
], function (clockModule, zoneModule, days, wording, timelineAssets, searchClient, model, placement) {
    'use strict';

    var BUTTON = '[data-role="config-history-open"]',
        MAX_WIDTH = 560,
        COPIED_MS = 1500,
        mounted = new WeakSet(),
        sequence = 0;

    /**
     * @param {*} failure
     */
    function warn(failure) {
        console.warn('Config history:', failure);
    }

    /**
     * @param {HTMLElement} root
     * @returns {Function} Unmount
     */
    function mount(root) {
        var script = root.querySelector('script[data-role="config-history-config"]'),
            config = model.parseConfig(JSON.parse(script ? script.textContent : 'null')),
            shared = timelineAssets.read(document).config,
            words = config.wording,
            lifetime = new AbortController(),
            signal = lifetime.signal,
            clock = clockModule.create(config.serverNowMs, Date.now(), Date.now),
            zone = zoneModule.create(config.timeZone, config.locale),
            tag = config.locale.replace(/_/g, '-'),
            numbers = new Intl.NumberFormat(tag),
            relativeTime = new Intl.RelativeTimeFormat(tag, {numeric: 'auto'}),
            client = searchClient.create(config.searchUrl, config.timeoutMs, window),
            templates = {
                popover: root.querySelector('template[data-template="config-history-popover"]'),
                row: root.querySelector('template[data-template="config-history-row"]')
            },
            shown = null,
            actions = Object.freeze({
                close: function () {
                    close(true);
                },
                copy: copy,
                retry: load
            });

        /**
         * @param {number} value
         * @returns {string}
         */
        function formatNumber(value) {
            return numbers.format(value);
        }

        /**
         * @param {number} ms
         * @returns {string}
         */
        function relative(ms) {
            var distance = days.ago(ms, clock.now());

            return relativeTime.format(distance.value, distance.unit);
        }

        /**
         * @param {string} name
         * @returns {HTMLElement}
         */
        function clone(name) {
            return templates[name].content.cloneNode(true).children[0];
        }

        /**
         * @param {HTMLElement} node
         * @param {string} name
         * @returns {HTMLElement}
         */
        function slot(node, name) {
            return node.querySelector('[data-slot="' + name + '"]');
        }

        /**
         * @param {string} tagName
         * @param {string} className
         * @param {string} text
         * @returns {HTMLElement}
         */
        function element(tagName, className, text) {
            var node = document.createElement(tagName);

            node.className = className;
            node.textContent = text;

            return node;
        }

        /**
         * @param {HTMLElement} button
         * @returns {Object} The setting the button describes
         */
        function settingOf(button) {
            return model.readSetting({
                path: button.getAttribute('data-path'),
                label: button.getAttribute('data-label'),
                changes: button.getAttribute('data-changes'),
                latestMs: button.getAttribute('data-latest-ms'),
                latestActor: button.getAttribute('data-latest-actor')
            });
        }

        /**
         * A channel's badge: its registered name and colours, or its code on a neutral badge
         *
         * @param {string} code
         * @param {string} className
         * @returns {HTMLElement}
         */
        function badge(code, className) {
            var channel = shared.channels[code],
                node = element('span', className + (channel === undefined ? ' ' + className + '--unknown' : ''),
                    channel === undefined ? code.toUpperCase() : channel.label);

            if (channel !== undefined) {
                node.style.backgroundColor = channel.bg;
                node.style.color = channel.fg;
            }

            return node;
        }

        /**
         * @param {?{text: string, kind: string}} value
         * @param {HTMLElement} node
         */
        function paintValue(value, node) {
            node.textContent = value === null ? '' : value.text;
            node.classList.add('config-history-row__value--' + (value === null ? 'empty' : value.kind));
        }

        /**
         * @param {Object} row As history-model.rows gives it
         * @returns {HTMLElement}
         */
        function rowNode(row) {
            var node = clone('row'),
                who = slot(node, 'who');

            slot(node, 'when').textContent = row.when;
            slot(node, 'when').setAttribute('title', row.ago);
            slot(node, 'ago').textContent = row.ago;
            who.appendChild(element('strong', 'config-history-row__actor', row.actor));
            if (row.via !== null) {
                who.appendChild(element('span', 'config-history-row__via-word', shared.wording.via));
                if (row.via.channel !== null) {
                    who.appendChild(badge(row.via.channel, 'config-history-row__via'));
                }
                who.appendChild(element('strong', 'config-history-row__client', row.via.client));
            }
            who.appendChild(badge(row.channel, 'config-history-row__channel'));
            if (row.scope !== null && row.scope !== '') {
                who.appendChild(element('span', 'config-history-row__scope', row.scope));
            }
            if (row.before === null) {
                slot(node, 'values').hidden = true;
            } else {
                paintValue(row.before, slot(node, 'before'));
                paintValue(row.after, slot(node, 'after'));
            }

            return node;
        }

        /**
         * @param {?string} text Null hides the state line
         * @param {boolean} retry Whether to offer "Retry"
         */
        function state(text, retry) {
            var line = slot(shown.node, 'state'),
                retryButton = shown.node.querySelector('[data-action="retry"]');

            line.hidden = text === null;
            slot(shown.node, 'state-text').textContent = text === null ? '' : text;
            retryButton.hidden = !retry;
            retryButton.textContent = shared.wording.retry;
        }

        /** Puts the popover below its setting's row. */
        function place() {
            var node = shown.node,
                row = shown.button.closest('tr') || shown.button.parentNode,
                column = shown.button.closest('td') || row,
                parent = node.offsetParent || document.body,
                parentBox = parent.getBoundingClientRect(),
                spot = placement.place(
                    row.getBoundingClientRect(),
                    column.getBoundingClientRect(),
                    shown.button.getBoundingClientRect(),
                    {
                        left: parentBox.left,
                        top: parentBox.top,
                        scrollLeft: parent.scrollLeft,
                        scrollTop: parent.scrollTop,
                        clientLeft: parent.clientLeft,
                        clientTop: parent.clientTop
                    },
                    MAX_WIDTH
                );

            node.style.top = spot.top + 'px';
            node.style.left = spot.left + 'px';
            node.style.width = spot.width + 'px';
            slot(node, 'arrow').style.left = spot.arrowLeft + 'px';
        }

        /**
         * @param {Object} page The first page of the setting's changes
         */
        function show(page) {
            var rows = slot(shown.node, 'rows');

            slot(shown.node, 'count').textContent = model.countLine(page, shown.setting, words, formatNumber);
            rows.textContent = '';
            model.rows(page, shown.setting.path, {zone: zone, relative: relative, emptyWord: shared.wording.empty})
                .forEach(function (row) {
                    rows.appendChild(rowNode(row));
                });
            state(page.events.length === 0 ? model.neverText(config, zone) : null, false);
        }

        /** Fetches the shown setting's changes; an answer for a popover no longer shown is dropped. */
        function load() {
            var current = shown;

            if (current.request !== null) {
                current.request.abort();
            }
            current.request = new AbortController();
            state(shared.wording.loading, false);
            client.page(model.search(current.setting.path, clock.now(), config.limit), current.request.signal)
                .then(function (page) {
                    if (shown === current) {
                        show(page);
                    }
                }, function (failure) {
                    if (shown !== current || failure.kind === 'aborted') {
                        return;
                    }
                    warn(failure);
                    state(failure.kind === 'refused' ? failure.reason : shared.wording.loadError, true);
                });
        }

        /**
         * @param {boolean} returnFocus Whether focus goes back to the button
         */
        function close(returnFocus) {
            var closing = shown;

            if (closing === null) {
                return;
            }
            shown = null;
            if (closing.request !== null) {
                closing.request.abort();
            }
            window.clearTimeout(closing.copyTimer);
            closing.node.remove();
            closing.button.setAttribute('aria-expanded', 'false');
            if (returnFocus && document.contains(closing.button)) {
                closing.button.focus();
            }
        }

        /** Copies the setting's path, or selects it where the page may not write to the clipboard. */
        function copy() {
            var current = shown,
                label = slot(current.node, 'copy-label'),
                copied = function () {
                    label.textContent = words.copied;
                    window.clearTimeout(current.copyTimer);
                    current.copyTimer = window.setTimeout(function () {
                        label.textContent = words.copy;
                    }, COPIED_MS);
                },
                select = function () {
                    window.getSelection().selectAllChildren(slot(current.node, 'path'));
                };

            if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
                select();

                return;
            }
            navigator.clipboard.writeText(current.setting.path).then(copied, function (failure) {
                warn(failure);
                select();
            });
        }

        /**
         * @param {HTMLElement} button
         */
        function open(button) {
            var setting = settingOf(button),
                node = clone('popover'),
                titleId = 'config-history-title-' + (sequence += 1),
                column = button.closest('td') || button.parentNode;

            close(false);
            slot(node, 'title').textContent = wording.fill(words.title, setting.label);
            slot(node, 'title').id = titleId;
            slot(node, 'path').textContent = setting.path;
            slot(node, 'copy-label').textContent = words.copy;
            slot(node, 'count').textContent = model.countLine(null, setting, words, formatNumber);
            slot(node, 'log-link').setAttribute('href', model.logLink(config.logUrl, setting.path));
            node.setAttribute('aria-labelledby', titleId);
            node.addEventListener('click', function (event) {
                var action = event.target.closest('[data-action]'),
                    run = action !== null && node.contains(action)
                        ? actions[action.getAttribute('data-action')]
                        : null;

                if (typeof run === 'function') {
                    run();
                }
            });

            column.appendChild(node);
            shown = {button: button, setting: setting, node: node, request: null, copyTimer: 0};
            button.setAttribute('aria-expanded', 'true');
            place();
            node.focus();
            load();
        }

        document.querySelectorAll(BUTTON).forEach(function (button) {
            try {
                button.setAttribute('title', model.buttonTitle(settingOf(button), words, formatNumber, relative));
            } catch (malformed) {
                warn(malformed);
            }
        });

        document.addEventListener('click', function (event) {
            var button = event.target instanceof Element ? event.target.closest(BUTTON) : null;

            if (button !== null) {
                event.preventDefault();
                if (shown !== null && shown.button === button) {
                    close(true);

                    return;
                }
                open(button);

                return;
            }
            if (shown !== null && event.target instanceof Node && !shown.node.contains(event.target)) {
                close(false);
            }
        }, {signal: signal});
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && shown !== null) {
                event.preventDefault();
                close(true);
            }
        }, {signal: signal});
        window.addEventListener('resize', function () {
            if (shown !== null) {
                place();
            }
        }, {signal: signal});

        return function unmount() {
            close(false);
            lifetime.abort();
        };
    }

    /**
     * Entry point for data-mage-init.
     *
     * @param {Object} config Unused: the popover reads its config from its JSON script element
     * @param {HTMLElement} root
     * @returns {?Function} Unmount
     */
    return function (config, root) {
        if (mounted.has(root)) {
            return null;
        }

        mounted.add(root);

        return mount(root);
    };
});
