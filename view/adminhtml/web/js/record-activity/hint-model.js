/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * The hints under the product fields changed in the last days: their config as the server passed it, and the parts
 * of one hint — "Changed 40 minutes ago by john · 34.00 → 39.00 · History".
 *
 * A short value is written out, cut at SHOWN_CHARS with an ellipsis; an empty one reads "(empty)"; a masked value
 * reads as masked; a long text or a JSON value is not written out at all, the hint only says who changed it when.
 *
 * Pure: the clock's time and the relative-time writer are passed in.
 */
define([
    'Hryvinskyi_ActivityLog/js/search/wording'
], function (wording) {
    'use strict';

    var SHOWN_CHARS = 60,
        SHORT = 'short',
        MASKED = 'masked',
        KINDS = Object.freeze([SHORT, 'long', 'json', MASKED]),
        WORDS = Object.freeze(['changed', 'empty', 'masked', 'history', 'historyOf']),
        FIELD_CODE = /^[A-Za-z0-9_]{1,255}$/;

    /**
     * @param {string} message
     * @constructor
     */
    function HintConfigError(message) {
        this.name = 'HintConfigError';
        this.message = message;
    }

    HintConfigError.prototype = Object.create(Error.prototype);

    /**
     * @param {*} value
     * @param {string} what
     * @returns {string}
     */
    function text(value, what) {
        if (typeof value !== 'string') {
            throw new HintConfigError(what + ' is not text');
        }

        return value;
    }

    /**
     * @param {*} value
     * @param {string} what
     * @returns {?string}
     */
    function optionalText(value, what) {
        return value === null ? null : text(value, what);
    }

    /**
     * @param {*} value
     * @param {string} what
     * @returns {number}
     */
    function time(value, what) {
        if (typeof value !== 'number' || !isFinite(value) || Math.floor(value) !== value || value < 0) {
            throw new HintConfigError(what + ' is not a time in milliseconds');
        }

        return value;
    }

    /**
     * @param {*} raw
     * @returns {Object}
     */
    function hintOf(raw) {
        if (raw === null || typeof raw !== 'object') {
            throw new HintConfigError('A hint is not an object');
        }
        if (!FIELD_CODE.test(text(raw.field, 'A field code'))) {
            throw new HintConfigError('A field code is malformed');
        }
        if (KINDS.indexOf(raw.kind) === -1) {
            throw new HintConfigError('A hint has no known kind of value');
        }

        return Object.freeze({
            field: raw.field,
            label: text(raw.label, 'A field label'),
            ms: time(raw.ms, 'A change time'),
            actor: text(raw.actor, 'An actor'),
            before: optionalText(raw.before, 'A value before'),
            after: optionalText(raw.after, 'A value after'),
            kind: raw.kind
        });
    }

    /**
     * The hints' config as the server passed it, checked
     *
     * @param {*} raw
     * @returns {{hints: Array<Object>, serverNowMs: number, locale: string, wording: Object<string, string>}}
     * @throws {HintConfigError} When a part is missing or of another kind
     */
    function parseConfig(raw) {
        var words = {};

        if (raw === null || typeof raw !== 'object' || !Array.isArray(raw.hints)) {
            throw new HintConfigError('The hints config has no hints');
        }
        if (raw.wording === null || typeof raw.wording !== 'object') {
            throw new HintConfigError('The hints config has no wording');
        }
        WORDS.forEach(function (name) {
            words[name] = text(raw.wording[name], 'The wording "' + name + '"');
        });

        return Object.freeze({
            hints: Object.freeze(raw.hints.map(hintOf)),
            serverNowMs: time(raw.serverNowMs, 'The server time'),
            locale: text(raw.locale, 'The locale'),
            wording: Object.freeze(words)
        });
    }

    /**
     * @param {?string} value
     * @param {Object<string, string>} words
     * @returns {{text: string, empty: boolean}}
     */
    function shown(value, words) {
        if (value === null || value === '') {
            return {text: words.empty, empty: true};
        }

        return {
            text: value.length > SHOWN_CHARS ? value.slice(0, SHOWN_CHARS - 1) + '…' : value,
            empty: false
        };
    }

    /**
     * The parts of one hint, in reading order
     *
     * @param {Object} hint A parsed hint
     * @param {Object<string, string>} words
     * @param {function(number): string} relative How long ago an instant was ("40 minutes ago")
     * @returns {{lead: string, actor: string, trail: string, values: ?{before: {text: string, empty: boolean},
     *     after: {text: string, empty: boolean}}, masked: ?string, history: string, historyLabel: string}}
     *     lead, the actor and trail make the sentence ("Changed 40 minutes ago by ", "john", "")
     */
    function parts(hint, words, relative) {
        var sentence = wording.fill(words.changed, relative(hint.ms), '\u0000'),
            split = sentence.split('\u0000');

        return {
            lead: split[0],
            actor: hint.actor,
            trail: split.slice(1).join(''),
            values: hint.kind === SHORT ? {before: shown(hint.before, words), after: shown(hint.after, words)} : null,
            masked: hint.kind === MASKED ? words.masked : null,
            history: words.history,
            historyLabel: wording.fill(words.historyOf, hint.label)
        };
    }

    return Object.freeze({
        SHOWN_CHARS: SHOWN_CHARS,
        HintConfigError: HintConfigError,
        parseConfig: parseConfig,
        parts: parts
    });
});
