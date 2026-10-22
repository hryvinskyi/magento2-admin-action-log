/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * A record's activity panel on its edit page: a slide-out (Magento's modal-slide) with the record's changes, drawn
 * by the activity timeline, and the toolbar that narrows them (record-activity/panel-model.js holds the rules).
 *
 * The page's "Activity" button opens it and gets its badge here; a field's "History" opens it on that field through
 * the open event (record-activity/open-event.js). On opening, focus moves to the panel's heading; Esc, the overlay
 * and the close button close it, and focus goes back to whatever opened it. Closing drops the search in flight.
 * The toolbar carries the one count, "N of M": what the choices find of all the record's changes, a total read once,
 * the first time the panel opens. The timeline's own count line is left out, and choices that find nothing say so
 * in the panel's own words. Every request has a deadline; mounting is once per element.
 */
define([
    'jquery',
    'Hryvinskyi_ActivityLog/js/search/clock',
    'Hryvinskyi_ActivityLog/js/search/zone',
    'Hryvinskyi_ActivityLog/js/search/days',
    'Hryvinskyi_ActivityLog/js/timeline/assets',
    'Hryvinskyi_ActivityLog/js/timeline/search-client',
    'Hryvinskyi_ActivityLog/js/timeline/timeline',
    'Hryvinskyi_AdminActionLog/js/record-activity/panel-model',
    'Hryvinskyi_AdminActionLog/js/record-activity/open-event',
    'Magento_Ui/js/modal/modal'
], function (
    $,
    clockModule,
    zoneModule,
    days,
    timelineAssets,
    searchClient,
    timelineModule,
    model,
    openEvent
) {
    'use strict';

    var BUTTON = '[data-role="record-activity-open"]',
        HEADING = '[data-role="record-activity-heading"]',
        FIELD_TERMS = Object.freeze([Object.freeze({key: 'field', matchesFieldNames: true})]),
        mounted = new WeakSet();

    /**
     * @param {*} failure
     */
    function warn(failure) {
        console.warn('Record activity:', failure);
    }

    /**
     * @param {HTMLElement} root
     * @returns {Function} Unmount
     */
    function mount(root) {
        var script = root.querySelector('script[data-role="record-activity-config"]'),
            config = model.parseConfig(JSON.parse(script ? script.textContent : 'null')),
            lifetime = new AbortController(),
            signal = lifetime.signal,
            clock = clockModule.create(config.serverNowMs, Date.now(), Date.now),
            zone = zoneModule.create(config.timeZone, config.locale),
            tag = config.locale.replace(/_/g, '-'),
            numbers = new Intl.NumberFormat(tag),
            relativeTime = new Intl.RelativeTimeFormat(tag, {numeric: 'auto'}),
            slot = function (name) {
                return root.querySelector('[data-slot="' + name + '"]');
            },
            controls = {
                field: slot('field'),
                actor: slot('actor'),
                period: slot('period'),
                allScopes: slot('all-scopes')
            },
            count = slot('count'),
            logLink = slot('log-link'),
            heading = root.querySelector(HEADING),
            button = document.querySelector(BUTTON),
            $root = $(root),
            search = searchClient.create(config.searchUrl, config.timeoutMs, window),
            state = model.initial(),
            timeline = null,
            opener = null,
            shownPage = null,
            recordTotal = {known: null, asked: false, failed: false};

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
         * @param {HTMLSelectElement} select
         * @param {string} value
         * @param {string} label
         */
        function addOption(select, value, label) {
            var option = document.createElement('option');

            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        }

        /**
         * A field asked for from outside the choices (older than the changes they list) becomes a choice
         *
         * @param {string} field
         */
        function offerField(field) {
            var offered = Array.prototype.some.call(controls.field.options, function (option) {
                return option.value === field;
            });

            if (!offered) {
                addOption(controls.field, field, field);
            }
        }

        /** Shows the choices on the controls. */
        function sync() {
            controls.field.value = state.field;
            controls.actor.value = state.actor;
            controls.period.value = state.period;
            if (controls.allScopes) {
                controls.allScopes.checked = state.allScopes;
            }
        }

        /** Writes the toolbar's count once both of its numbers are known. */
        function drawCount() {
            var all = recordTotal.failed && shownPage !== null ? shownPage.overall : recordTotal.known;

            count.textContent = shownPage === null ? '' : model.resultCount(shownPage, all, config.wording, formatNumber);
        }

        /**
         * Reads how many changes the record has, once; should that fail, the count falls back to the choices' own
         * total over all time
         */
        function loadRecordTotal() {
            if (recordTotal.asked) {
                return;
            }
            recordTotal.asked = true;
            search.page(model.recordSearch(config, clock.now()), signal).then(function (page) {
                recordTotal.known = page.total;
                drawCount();
            }, function (failure) {
                if (failure && failure.kind === 'aborted') {
                    return;
                }
                warn(failure);
                recordTotal.failed = true;
                drawCount();
            });
        }

        /** Draws the search of the choices and points "Open in Activity Log" at it. */
        function show() {
            sync();
            logLink.setAttribute('href', model.logLink(config.logUrl, model.chips(config, state)));
            shownPage = null;
            drawCount();
            timeline.show(model.search(config, state, clock.now(), zone));
        }

        /**
         * @param {Object} changes
         */
        function change(changes) {
            state = model.update(state, changes);
            show();
        }

        /**
         * @returns {{show: function(Object), stop: function(), destroy: function()}}
         */
        function mountTimeline() {
            var mountedTimeline = timelineModule.mount(slot('results'), {
                assets: timelineAssets.read(document),
                search: search,
                clock: clock,
                zone: zone,
                locale: config.locale,
                keys: FIELD_TERMS,
                report: warn,
                describeRange: function (shown) {
                    return model.describe(shown, zone, config.wording);
                },
                searchLink: function (chips) {
                    return model.logLink(config.logUrl, chips);
                },
                countLine: false,
                noResults: function () {
                    return model.noMatch(config);
                },
                onPage: function (shown, page) {
                    shownPage = page;
                    drawCount();
                }
            });

            signal.addEventListener('abort', mountedTimeline.destroy, {once: true});

            return mountedTimeline;
        }

        /**
         * @param {?string} field A field to show alone, or null for every field
         */
        function open(field) {
            opener = document.activeElement instanceof HTMLElement && document.activeElement !== document.body
                ? document.activeElement
                : button;
            if (field !== null) {
                offerField(field);
            }
            state = model.opened(state, field);
            slot('subtitle').textContent = model.subtitle(config, formatNumber, relative);
            timeline = timeline || mountTimeline();
            loadRecordTotal();
            $root.modal('openModal');
            if (button) {
                button.setAttribute('aria-expanded', 'true');
            }
            show();
        }

        /** After the panel closed: nothing stays in flight, focus goes back to what opened it. */
        function closed() {
            if (timeline !== null) {
                timeline.stop();
            }
            if (button) {
                button.setAttribute('aria-expanded', 'false');
            }
            if (opener !== null && document.contains(opener)) {
                opener.focus();
            }
        }

        /** Gives the page's button its badge and tells assistive technology what it opens. */
        function decorateButton() {
            var name = button.textContent.trim(),
                badge = document.createElement('span'),
                text = model.badge(
                    config,
                    Number(button.getAttribute('data-count')) || 0,
                    button.getAttribute('data-count-at-least') === 'true',
                    formatNumber
                );

            badge.className = 'record-activity-button__badge';
            badge.setAttribute('aria-hidden', 'true');
            badge.textContent = text.badge;
            button.appendChild(badge);
            button.setAttribute('aria-haspopup', 'dialog');
            button.setAttribute('aria-expanded', 'false');
            button.setAttribute('aria-label', name + ', ' + text.label);
            button.addEventListener('click', function (event) {
                event.preventDefault();
                open(null);
            }, {signal: signal});
        }

        config.fields.forEach(function (field) {
            addOption(controls.field, field.value, field.label);
        });
        config.actors.forEach(function (actor) {
            addOption(controls.actor, actor, actor);
        });
        slot('title').textContent = config.record.title === null ? '' : config.record.title;

        root.hidden = false;
        $root.modal({
            type: 'slide',
            title: '',
            buttons: [],
            modalClass: 'record-activity-modal',
            focus: HEADING,
            closed: closed
        });
        if (root.closest('[role="dialog"]') !== null && heading !== null) {
            root.closest('[role="dialog"]').setAttribute('aria-labelledby', heading.id);
        }
        if (button) {
            decorateButton();
        }

        controls.field.addEventListener('change', function () {
            change({field: controls.field.value});
        }, {signal: signal});
        controls.actor.addEventListener('change', function () {
            change({actor: controls.actor.value});
        }, {signal: signal});
        controls.period.addEventListener('change', function () {
            change({period: controls.period.value});
        }, {signal: signal});
        if (controls.allScopes) {
            controls.allScopes.addEventListener('change', function () {
                change({allScopes: controls.allScopes.checked});
            }, {signal: signal});
        }
        document.addEventListener(openEvent.NAME, function (event) {
            var asked = openEvent.read(event.detail);

            if (asked !== null) {
                open(asked.field);
            }
        }, {signal: signal});

        return function unmount() {
            lifetime.abort();
        };
    }

    /**
     * Entry point for data-mage-init.
     *
     * @param {Object} config Unused: the panel reads its config from its JSON script element
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
