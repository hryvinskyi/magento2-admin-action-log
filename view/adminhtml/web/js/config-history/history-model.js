/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The settings' history popover's model: its config as the server printed it, a setting as its button describes it,
 * the search of a setting's changes, and the texts and rows the popover shows.
 *
 * A setting's history is what the activity log page finds for "config: <path>" — the exact path, never a longer
 * sibling — over all time, in every scope, newest first, up to the printed limit. Each row shows when, who (with the
 * client and channel a person acted through), the channel, the scope, and the value before → after as the admin sees
 * it; a masked value stays masked, a missing one reads "(empty)". "Open in Activity Log" opens the log page on the
 * same path over the last 30 days.
 *
 * Pure: the clock's time, the timezone (search/zone.js) and the number and time writers are passed in.
 */
define([
    'Hryvinskyi_ActivityLog/js/search/grammar',
    'Hryvinskyi_ActivityLog/js/search/wording'
], function (grammar, wording) {
    'use strict';

    var KEY = 'config',
        LINK_RANGE = '30d',
        MAX_LIMIT = 200,
        WORDS = Object.freeze(['title', 'copy', 'copied', 'changesOne', 'changesMany', 'noChanges', 'atLeast',
            'countLine', 'neverSince', 'never', 'buttonOne', 'buttonMany', 'buttonNever']);

    /**
     * @param {string} message
     * @constructor
     */
    function HistoryConfigError(message) {
        this.name = 'HistoryConfigError';
        this.message = message;
    }

    HistoryConfigError.prototype = Object.create(Error.prototype);

    /**
     * @param {*} value
     * @param {string} what
     * @returns {string}
     */
    function text(value, what) {
        if (typeof value !== 'string') {
            throw new HistoryConfigError(what + ' is not text');
        }

        return value;
    }

    /**
     * @param {*} value
     * @param {string} what
     * @returns {number}
     */
    function count(value, what) {
        if (typeof value !== 'number' || !isFinite(value) || Math.floor(value) !== value || value < 0) {
            throw new HistoryConfigError(what + ' is not a whole number');
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
            throw new HistoryConfigError(what + ' is not an object');
        }

        return value;
    }

    /**
     * The popover's config as the server printed it, checked
     *
     * @param {*} raw The decoded JSON
     * @returns {Object}
     * @throws {HistoryConfigError} When a part is missing or of another kind
     */
    function parseConfig(raw) {
        var config = object(raw, 'The history config'),
            words = object(config.wording, 'The wording'),
            parsedWords = {},
            limit = count(config.limit, 'The limit');

        if (limit < 1 || limit > MAX_LIMIT) {
            throw new HistoryConfigError('The limit is not between 1 and ' + MAX_LIMIT);
        }
        WORDS.forEach(function (name) {
            parsedWords[name] = text(words[name], 'The wording "' + name + '"');
        });

        return Object.freeze({
            searchUrl: text(config.searchUrl, 'The search address'),
            logUrl: text(config.logUrl, 'The log address'),
            timeZone: text(config.timeZone, 'The timezone'),
            locale: text(config.locale, 'The locale'),
            serverNowMs: count(config.serverNowMs, 'The server time'),
            timeoutMs: count(config.timeoutMs, 'The timeout'),
            limit: limit,
            loggingStartedMs: config.loggingStartedMs === null
                ? null
                : count(config.loggingStartedMs, 'The time logging started'),
            wording: Object.freeze(parsedWords)
        });
    }

    /**
     * A setting as its button describes it in its data attributes, read as text
     *
     * @param {{path: ?string, label: ?string, changes: ?string, latestMs: ?string, latestActor: ?string}} attributes
     * @returns {{path: string, label: string, changes: number, latestMs: ?number, latestActor: ?string}}
     * @throws {HistoryConfigError} When the button names no path
     */
    function readSetting(attributes) {
        var path = typeof attributes.path === 'string' ? attributes.path.trim() : '',
            changes = Number(attributes.changes),
            latestMs = attributes.latestMs === null || attributes.latestMs === '' ? NaN : Number(attributes.latestMs),
            actor = typeof attributes.latestActor === 'string' ? attributes.latestActor : '';

        if (path === '') {
            throw new HistoryConfigError('The history button names no setting');
        }

        return Object.freeze({
            path: path,
            label: typeof attributes.label === 'string' && attributes.label.trim() !== '' ? attributes.label : path,
            changes: isFinite(changes) && changes > 0 ? Math.floor(changes) : 0,
            latestMs: isFinite(latestMs) && latestMs >= 0 ? latestMs : null,
            latestActor: actor === '' ? null : actor
        });
    }

    /**
     * The button's title: how often the setting changed and the newest change, or that it never changed
     *
     * @param {Object} setting As readSetting returns it
     * @param {Object} words
     * @param {function(number): string} formatNumber
     * @param {function(number): string} relative How long ago an instant was ("2 days ago")
     * @returns {string}
     */
    function buttonTitle(setting, words, formatNumber, relative) {
        if (setting.changes === 0) {
            return words.buttonNever;
        }

        return wording.fill(
            setting.changes === 1 ? words.buttonOne : words.buttonMany,
            formatNumber(setting.changes),
            setting.latestMs === null ? '' : relative(setting.latestMs),
            setting.latestActor === null ? '' : setting.latestActor
        );
    }

    /**
     * The search of a setting's changes: its exact path, over all time up to now, newest first
     *
     * @param {string} path
     * @param {number} nowMs
     * @param {number} limit
     * @returns {{range: {from: number, to: number}, chips: Array<{key: string, value: string}>,
     *     changesOnly: boolean, cursor: null, limit: number}}
     */
    function search(path, nowMs, limit) {
        return {
            range: {from: 0, to: nowMs},
            chips: [{key: KEY, value: path}],
            changesOnly: true,
            cursor: null,
            limit: limit
        };
    }

    /**
     * The activity log page searching for the setting over the last 30 days
     *
     * @param {string} logUrl The page's address, as the server built it
     * @param {string} path
     * @returns {string}
     */
    function logLink(logUrl, path) {
        return logUrl + (logUrl.indexOf('?') === -1 ? '?' : '&')
            + grammar.toUrl([{key: KEY, value: path}], LINK_RANGE);
    }

    /**
     * The header's count, "3 changes · all scopes", on a line of its own below the path: the search's total once it came, the button's count before
     *
     * @param {?{total: ?{count: number, atLeast: boolean}}} page The first page of the setting's search, or null
     * @param {Object} setting
     * @param {Object} words
     * @param {function(number): string} formatNumber
     * @returns {string}
     */
    function countLine(page, setting, words, formatNumber) {
        var total = page !== null && page.total !== null ? page.total : {count: setting.changes, atLeast: false},
            shown = total.atLeast ? wording.fill(words.atLeast, formatNumber(total.count)) : formatNumber(total.count),
            changes;

        if (total.count === 0) {
            changes = words.noChanges;
        } else {
            changes = wording.fill(total.count === 1 && !total.atLeast ? words.changesOne : words.changesMany, shown);
        }

        return wording.fill(words.countLine, changes);
    }

    /**
     * What the popover says for a setting without changes
     *
     * @param {Object} config
     * @param {{format: function(number, string): string}} zone
     * @returns {string}
     */
    function neverText(config, zone) {
        return config.loggingStartedMs === null
            ? config.wording.never
            : wording.fill(config.wording.neverSince, zone.format(config.loggingStartedMs, 'date'));
    }

    /**
     * A value as a row shows it: no value and an empty text (a setting cleared) both read "(empty)"
     *
     * @param {?string} value
     * @param {boolean} masked
     * @param {string} emptyWord
     * @returns {{text: string, kind: string}} kind "empty", "masked" or "value"
     */
    function valueOf(value, masked, emptyWord) {
        if (value === null || value === '') {
            return {text: emptyWord, kind: 'empty'};
        }

        return {text: value, kind: masked ? 'masked' : 'value'};
    }

    /**
     * The field row of an activity that holds the setting: the one named by its path, else the first
     *
     * @param {Object} event As search/request.js parses it
     * @param {string} path
     * @returns {?Object}
     */
    function fieldOf(event, path) {
        var fields = event.detail === null ? [] : event.detail.fields,
            named = fields.filter(function (field) {
                return field.name === path;
            });

        return named.length > 0 ? named[0] : (fields[0] || null);
    }

    /**
     * The popover's rows for a page of the setting's changes, newest first
     *
     * @param {{events: Array<Object>}} page As search/request.js parses it
     * @param {string} path
     * @param {{zone: {format: function(number, string): string}, relative: function(number): string,
     *     emptyWord: string}} context
     * @returns {Array<Object>} {id, when, ago, actor, via: ?{channel: ?string, client: string}, channel, scope,
     *     before: ?{text, kind}, after: ?{text, kind}}
     */
    function rows(page, path, context) {
        return page.events.map(function (event) {
            var field = fieldOf(event, path),
                masked = field !== null && field.kind === 'masked';

            return Object.freeze({
                id: event.id,
                when: context.zone.format(event.occurredAtMs, 'short'),
                ago: context.relative(event.occurredAtMs),
                actor: event.actor.name,
                via: event.actor.viaClient === null
                    ? null
                    : Object.freeze({channel: event.actor.viaChannel, client: event.actor.viaClient}),
                channel: event.channel,
                scope: field === null ? null : field.scope,
                before: field === null ? null : valueOf(field.before, masked, context.emptyWord),
                after: field === null ? null : valueOf(field.after, masked, context.emptyWord)
            });
        });
    }

    return Object.freeze({
        WORDS: WORDS,
        HistoryConfigError: HistoryConfigError,
        parseConfig: parseConfig,
        readSetting: readSetting,
        buttonTitle: buttonTitle,
        search: search,
        logLink: logLink,
        countLine: countLine,
        neverText: neverText,
        rows: rows
    });
});
