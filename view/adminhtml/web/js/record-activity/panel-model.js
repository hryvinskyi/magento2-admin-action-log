/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The record activity panel's model: its config as the server printed it, the choices on its toolbar, the search
 * those choices make, and the texts around the results.
 *
 * The panel searches what the activity log page searches: the record by its exact value ("product: #1245"), the
 * channels that record changes, and the choices as more filters — the field by its exact code in double quotes
 * ('field: "price"', so a field's history never takes in special_price), the actor by their exact name in double
 * quotes ('by: "admin"', so the user named admin is never taken for every admin) and, with "All store views" off,
 * the scopes the server names for the page ("store"). The periods are the log's "last 7 days" and "last 30
 * days" presets, so a count the server made for the last 30 days matches the 30-day list, and "all", from the first
 * millisecond to now. "Open in Activity Log" opens the log page on the same filters over the last 30 days. The
 * toolbar counts the changes the choices find out of all the record's changes, over all time and whatever is chosen;
 * that total is a search of its own (recordSearch).
 *
 * Pure: the clock's time, the timezone (search/zone.js) and the number and time writers are passed in.
 */
define([
    'Hryvinskyi_ActivityLog/js/search/range',
    'Hryvinskyi_ActivityLog/js/search/grammar',
    'Hryvinskyi_ActivityLog/js/search/wording'
], function (range, grammar, wording) {
    'use strict';

    var ALL = 'all',
        DEFAULT_PERIOD = '30d',
        LINK_RANGE = '30d',
        PERIODS = Object.freeze(['7d', '30d', ALL]),
        FIELD_KEY = 'field',
        ACTOR_KEY = 'by',
        SCOPE_KEY = 'store',
        WORDS = Object.freeze(['idLine', 'keyLine', 'summaryOne', 'summaryMany', 'summaryNone', 'countOne',
            'countMany', 'atLeast', 'badge', 'allTime', 'noMatchTitle', 'noMatchText']);

    /**
     * @param {string} message
     * @constructor
     */
    function PanelConfigError(message) {
        this.name = 'PanelConfigError';
        this.message = message;
    }

    PanelConfigError.prototype = Object.create(Error.prototype);

    /**
     * @param {*} value
     * @param {string} what
     * @returns {string}
     */
    function text(value, what) {
        if (typeof value !== 'string') {
            throw new PanelConfigError(what + ' is not text');
        }

        return value;
    }

    /**
     * @param {*} value
     * @param {string} what
     * @returns {?string}
     */
    function optionalText(value, what) {
        return value === null || value === undefined ? null : text(value, what);
    }

    /**
     * @param {*} value
     * @param {string} what
     * @returns {number}
     */
    function count(value, what) {
        if (typeof value !== 'number' || !isFinite(value) || Math.floor(value) !== value || value < 0) {
            throw new PanelConfigError(what + ' is not a whole number');
        }

        return value;
    }

    /**
     * @param {*} value
     * @param {string} what
     * @returns {Object}
     */
    function object(value, what) {
        if (value === null || typeof value !== 'object' || Array.isArray(value)) {
            throw new PanelConfigError(what + ' is not an object');
        }

        return value;
    }

    /**
     * @param {*} value
     * @param {string} what
     * @returns {Array}
     */
    function list(value, what) {
        if (!Array.isArray(value)) {
            throw new PanelConfigError(what + ' is not a list');
        }

        return value;
    }

    /**
     * @param {*} raw
     * @returns {Object} The words, each checked to be text
     */
    function wordsOf(raw) {
        var words = object(raw, 'The wording'),
            periods = object(words.periods, 'The period names'),
            parsed = {periods: {}};

        WORDS.forEach(function (name) {
            parsed[name] = text(words[name], 'The wording "' + name + '"');
        });
        PERIODS.forEach(function (period) {
            parsed.periods[period] = text(periods[period], 'The name of the period "' + period + '"');
        });
        Object.freeze(parsed.periods);

        return Object.freeze(parsed);
    }

    /**
     * The panel's config as the server printed it, checked
     *
     * @param {*} raw The decoded JSON
     * @returns {Object}
     * @throws {PanelConfigError} When a part is missing or of another kind
     */
    function parseConfig(raw) {
        var config = object(raw, 'The panel config'),
            record = object(config.record, 'The record'),
            summary = object(config.summary, 'The summary'),
            naturalKey = record.naturalKey === null || record.naturalKey === undefined
                ? null
                : object(record.naturalKey, 'The natural key');

        return Object.freeze({
            searchUrl: text(config.searchUrl, 'The search address'),
            logUrl: text(config.logUrl, 'The log address'),
            timeZone: text(config.timeZone, 'The timezone'),
            locale: text(config.locale, 'The locale'),
            serverNowMs: count(config.serverNowMs, 'The server time'),
            timeoutMs: count(config.timeoutMs, 'The timeout'),
            days: count(config.days, 'The days of the summary'),
            record: Object.freeze({
                key: text(record.key, 'The record key'),
                value: text(record.value, 'The record value'),
                id: text(record.id, 'The record id'),
                title: optionalText(record.title, 'The record title'),
                naturalKey: naturalKey === null ? null : Object.freeze({
                    label: text(naturalKey.label, 'The natural key name'),
                    value: text(naturalKey.value, 'The natural key')
                })
            }),
            summary: Object.freeze({
                count: count(summary.count, 'The count'),
                atLeast: summary.atLeast === true,
                latestMs: summary.latestMs === null ? null : count(summary.latestMs, 'The newest change time'),
                latestActor: optionalText(summary.latestActor, 'The newest change actor')
            }),
            fields: Object.freeze(list(config.fields, 'The fields').map(function (field) {
                var parsed = object(field, 'A field');

                return Object.freeze({
                    value: text(parsed.value, 'A field code'),
                    label: text(parsed.label, 'A field label')
                });
            })),
            actors: Object.freeze(list(config.actors, 'The actors').map(function (actor) {
                return text(actor, 'An actor');
            })),
            scope: optionalText(config.scope, 'The scope'),
            wording: wordsOf(config.wording)
        });
    }

    /**
     * The toolbar's choices when the panel first opens: every field, anyone, the last 30 days, every store view
     *
     * @returns {{field: string, actor: string, period: string, allScopes: boolean}}
     */
    function initial() {
        return Object.freeze({field: '', actor: '', period: DEFAULT_PERIOD, allScopes: true});
    }

    /**
     * The choices on opening: from the button, every field; from a field's history, that field alone over the last
     * 30 days by anyone
     *
     * @param {Object} state
     * @param {?string} field The field asked for, or null
     * @returns {Object}
     */
    function opened(state, field) {
        return Object.freeze(field === null || field === ''
            ? Object.assign({}, state, {field: ''})
            : Object.assign({}, state, {field: field, actor: '', period: DEFAULT_PERIOD}));
    }

    /**
     * The choices after one control changed
     *
     * @param {Object} state
     * @param {{field: (string|undefined), actor: (string|undefined), period: (string|undefined),
     *     allScopes: (boolean|undefined)}} changes
     * @returns {Object}
     * @throws {RangeError} For a period the panel does not offer
     */
    function update(state, changes) {
        if (changes.period !== undefined && PERIODS.indexOf(changes.period) === -1) {
            throw new RangeError('The panel has no period "' + changes.period + '"');
        }

        return Object.freeze(Object.assign({}, state, changes));
    }

    /**
     * The filters of the choices: the record first, then the field, the actor and the scope chosen
     *
     * @param {Object} config
     * @param {Object} state
     * @returns {Array<{key: string, value: string}>}
     */
    function chips(config, state) {
        var chosen = [{key: config.record.key, value: config.record.value}];

        if (state.field !== '') {
            chosen.push({key: FIELD_KEY, value: '"' + state.field + '"'});
        }
        if (state.actor !== '') {
            chosen.push({key: ACTOR_KEY, value: '"' + state.actor + '"'});
        }
        if (!state.allScopes && config.scope !== null) {
            chosen.push({key: SCOPE_KEY, value: config.scope});
        }

        return chosen.map(function (chip) {
            return Object.freeze(chip);
        });
    }

    /**
     * The range of a period at this moment
     *
     * @param {string} period
     * @param {number} nowMs
     * @param {Object} zone
     * @returns {{preset: string, from: number, to: number}}
     */
    function rangeOf(period, nowMs, zone) {
        var preset;

        if (period === ALL) {
            return {preset: ALL, from: 0, to: nowMs};
        }
        preset = range.preset(period, nowMs, zone);
        if (!preset.ok) {
            throw new RangeError('The panel has no period "' + period + '"');
        }

        return preset.range;
    }

    /**
     * The search the choices make, as the timeline shows it
     *
     * @param {Object} config
     * @param {Object} state
     * @param {number} nowMs
     * @param {Object} zone
     * @returns {{chips: Array<{key: string, value: string}>, range: {preset: string, from: number, to: number},
     *     changesOnly: boolean}}
     */
    function search(config, state, nowMs, zone) {
        return {chips: chips(config, state), range: rangeOf(state.period, nowMs, zone), changesOnly: true};
    }

    /**
     * The search that counts all the record's changes: the record alone, over all time, one change per page since
     * only its total is read
     *
     * @param {Object} config
     * @param {number} nowMs
     * @returns {{chips: Array<{key: string, value: string}>, range: {preset: string, from: number, to: number},
     *     changesOnly: boolean, cursor: null, limit: number}}
     */
    function recordSearch(config, nowMs) {
        return {
            chips: [Object.freeze({key: config.record.key, value: config.record.value})],
            range: rangeOf(ALL, nowMs, null),
            changesOnly: true,
            cursor: null,
            limit: 1
        };
    }

    /**
     * The activity log page searching for these filters over the last 30 days
     *
     * @param {string} logUrl The page's address, as the server built it
     * @param {Array<{key: string, value: string}>} filters
     * @returns {string}
     */
    function logLink(logUrl, filters) {
        return logUrl + (logUrl.indexOf('?') === -1 ? '?' : '&') + grammar.toUrl(filters, LINK_RANGE);
    }

    /**
     * How a period reads in the results' head: its name, and its two ends or "everything recorded"
     *
     * @param {{preset: string, from: number, to: number}} shown
     * @param {Object} zone
     * @param {Object} words
     * @returns {{label: string, span: string}}
     */
    function describe(shown, zone, words) {
        var described;

        if (shown.preset === ALL) {
            return {label: words.periods[ALL], span: words.allTime};
        }
        described = range.describe(shown, zone, {presets: words.periods}, 'long');

        return {label: described.label, span: described.from + ' – ' + described.to};
    }

    /**
     * A count as the panel writes it, "10,000+" past the search's bound
     *
     * @param {number} value
     * @param {boolean} atLeast
     * @param {Object} words
     * @param {function(number): string} formatNumber
     * @returns {string}
     */
    function countText(value, atLeast, words, formatNumber) {
        return atLeast ? wording.fill(words.atLeast, formatNumber(value)) : formatNumber(value);
    }

    /**
     * The line under the panel's heading: the natural key, the id, and the last 30 days in short
     *
     * @param {Object} config
     * @param {function(number): string} formatNumber
     * @param {function(number): string} relative How long ago an instant was ("40 minutes ago")
     * @returns {string}
     */
    function subtitle(config, formatNumber, relative) {
        var words = config.wording,
            summary = config.summary,
            parts = [];

        if (config.record.naturalKey !== null) {
            parts.push(wording.fill(words.keyLine, config.record.naturalKey.label, config.record.naturalKey.value));
        }
        parts.push(wording.fill(words.idLine, config.record.id));
        if (summary.count === 0 || summary.latestMs === null) {
            parts.push(wording.fill(words.summaryNone, formatNumber(config.days)));
        } else {
            parts.push(wording.fill(
                summary.count === 1 && !summary.atLeast ? words.summaryOne : words.summaryMany,
                countText(summary.count, summary.atLeast, words, formatNumber),
                formatNumber(config.days),
                summary.latestActor === null ? '' : summary.latestActor,
                relative(summary.latestMs)
            ));
        }

        return parts.join(' · ');
    }

    /**
     * The toolbar's count: the changes the choices find, of all the record's changes
     *
     * @param {{total: ?{count: number, atLeast: boolean}}} page The first page of the choices' search
     * @param {?{count: number, atLeast: boolean}} all How many changes the record has (recordSearch's total)
     * @param {Object} words
     * @param {function(number): string} formatNumber
     * @returns {string} Empty while either count is not known
     */
    function resultCount(page, all, words, formatNumber) {
        if (page.total === null || all === null) {
            return '';
        }

        return wording.fill(
            all.count === 1 && !all.atLeast ? words.countOne : words.countMany,
            countText(page.total.count, page.total.atLeast, words, formatNumber),
            countText(all.count, all.atLeast, words, formatNumber)
        );
    }

    /**
     * The panel's own words for choices that found nothing
     *
     * @param {Object} config
     * @returns {{title: string, text: string}}
     */
    function noMatch(config) {
        return {title: config.wording.noMatchTitle, text: config.wording.noMatchText};
    }

    /**
     * The button's badge and its spoken label
     *
     * @param {Object} config
     * @param {number} value The count the button carries
     * @param {boolean} atLeast
     * @param {function(number): string} formatNumber
     * @returns {{badge: string, label: string}}
     */
    function badge(config, value, atLeast, formatNumber) {
        var shown = countText(value, atLeast, config.wording, formatNumber);

        return {badge: shown, label: wording.fill(config.wording.badge, shown, formatNumber(config.days))};
    }

    return Object.freeze({
        PERIODS: PERIODS,
        PanelConfigError: PanelConfigError,
        parseConfig: parseConfig,
        initial: initial,
        opened: opened,
        update: update,
        chips: chips,
        rangeOf: rangeOf,
        search: search,
        recordSearch: recordSearch,
        logLink: logLink,
        describe: describe,
        subtitle: subtitle,
        resultCount: resultCount,
        noMatch: noMatch,
        badge: badge
    });
});
