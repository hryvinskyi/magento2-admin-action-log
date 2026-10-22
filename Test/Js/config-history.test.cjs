/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

const model = load('config-history/history-model');
const placement = load('config-history/placement');
const grammar = load('Hryvinskyi_ActivityLog/js/search/grammar');
const request = load('Hryvinskyi_ActivityLog/js/search/request');
const zone = load('Hryvinskyi_ActivityLog/js/search/zone').create('Europe/Copenhagen', 'en_US');

// 2026-10-22 14:05 in Copenhagen
const NOW = Date.UTC(2026, 9, 22, 12, 5);
const DAY = 86400000;
const formatNumber = (value) => new Intl.NumberFormat('en-US').format(value);
const relative = (ms) => Math.round((NOW - ms) / DAY) + ' days ago';
// Objects made inside the loaded modules come from another realm: compare their data, not their prototypes
const plain = (value) => JSON.parse(JSON.stringify(value));

const WORDING = {
    title: 'History · %1',
    copy: 'Copy',
    copied: 'Copied',
    changesOne: '%1 change',
    changesMany: '%1 changes',
    noChanges: 'no changes',
    atLeast: '%1+',
    countLine: '%1 · all scopes',
    neverSince: 'Never changed since logging started (%1). The value is the system default.',
    never: 'Never changed since logging started. The value is the system default.',
    buttonOne: '%1 change · last %2 by %3',
    buttonMany: '%1 changes · last %2 by %3',
    buttonNever: 'Never changed since logging started'
};

/**
 * The config as the server prints it
 *
 * @param {Object=} overrides
 * @returns {Object}
 */
function rawConfig(overrides = {}) {
    return Object.assign({
        searchUrl: '/admin/activitylog/activity/search/key/abc/',
        logUrl: '/admin/activitylog/activity/index/key/def/',
        timeZone: 'Europe/Copenhagen',
        locale: 'en_US',
        serverNowMs: NOW,
        timeoutMs: 20000,
        limit: 50,
        loggingStartedMs: Date.UTC(2026, 8, 20, 10, 0),
        wording: WORDING
    }, overrides);
}

/**
 * A field row as the search endpoint answers it
 *
 * @param {Object=} overrides
 * @returns {Object}
 */
function field(overrides = {}) {
    return Object.assign({
        name: 'carriers/freeshipping/active',
        label: 'Free Shipping › Enabled',
        scope: 'Main Website',
        before: 'No',
        after: 'Yes',
        kind: 'short',
        changed: true,
        automatic: false,
        truncated: false,
        beforeLength: 2,
        afterLength: 3
    }, overrides);
}

/**
 * An activity as the search endpoint answers it
 *
 * @param {number} id
 * @param {Array<Object>} fields
 * @param {Object=} actor
 * @returns {Object}
 */
function event(id, fields, actor = {}) {
    return {
        id: id,
        occurredAtMs: NOW - id * DAY,
        channel: 'entity',
        correlationId: 'op-' + id,
        siblingCount: 0,
        actor: Object.assign({type: 'admin', name: 'john', viaClient: null, viaVersion: null, viaChannel: null}, actor),
        action: 'Config value saved',
        outcome: 'success',
        message: null,
        durationMs: null,
        ip: null,
        subject: {type: 'config_value', id: '12', label: 'Config value carriers/freeshipping/active'},
        verb: 'changed',
        detail: {
            what: 'Free Shipping › Enabled',
            fieldCount: fields.length,
            automaticCount: 0,
            subjectTypeLabel: 'Config value',
            subjectLabel: null,
            subjectUrl: null,
            fields: fields,
            moreFieldCount: 0,
            unchangedCount: 0,
            unchangedRecorded: true
        },
        detailUrl: '/admin/activitylog/activity/view/id/' + id + '/'
    };
}

/**
 * A result page parsed the way the page parses it
 *
 * @param {Array<Object>} events
 * @param {?Object} total
 * @returns {Object}
 */
function page(events, total) {
    return request.parsePage({
        events: events,
        nextCursor: null,
        narrowed: false,
        total: total,
        outside: null,
        latestOutsideMs: null,
        overall: null,
        journalEmpty: false
    });
}

test('the config is read as printed and refused when a word or a number is missing', () => {
    const config = model.parseConfig(rawConfig());

    assert.equal(config.limit, 50);
    assert.equal(config.wording.copy, 'Copy');
    assert.equal(model.parseConfig(rawConfig({loggingStartedMs: null})).loggingStartedMs, null);
    assert.throws(
        () => model.parseConfig(rawConfig({wording: Object.assign({}, WORDING, {neverSince: undefined})})),
        (error) => error.name === 'HistoryConfigError' && /neverSince/.test(error.message)
    );
    assert.throws(() => model.parseConfig(rawConfig({limit: 0})), {name: 'HistoryConfigError'});
    assert.throws(() => model.parseConfig(rawConfig({limit: 500})), {name: 'HistoryConfigError'});
    assert.throws(() => model.parseConfig(rawConfig({serverNowMs: '1'})), {name: 'HistoryConfigError'});
});

test('a button is read from its attributes; blanks become nothing, and a button without a path is refused', () => {
    assert.deepEqual(plain(model.readSetting({
        path: 'carriers/freeshipping/active',
        label: 'Enabled',
        changes: '3',
        latestMs: String(NOW - DAY),
        latestActor: 'john'
    })), {path: 'carriers/freeshipping/active', label: 'Enabled', changes: 3, latestMs: NOW - DAY, latestActor: 'john'});
    assert.deepEqual(plain(model.readSetting({
        path: 'carriers/freeshipping/name',
        label: '',
        changes: '0',
        latestMs: '',
        latestActor: ''
    })), {path: 'carriers/freeshipping/name', label: 'carriers/freeshipping/name', changes: 0, latestMs: null,
        latestActor: null});
    assert.throws(
        () => model.readSetting({path: ' ', label: 'x', changes: '1', latestMs: null, latestActor: null}),
        {name: 'HistoryConfigError'}
    );
});

test('the button title counts the changes and names the newest, or says the setting never changed', () => {
    const changed = model.readSetting({
        path: 'a/b/c', label: 'C', changes: '3', latestMs: String(NOW - 2 * DAY), latestActor: 'anna'
    });
    const once = model.readSetting({path: 'a/b/c', label: 'C', changes: '1', latestMs: String(NOW), latestActor: 'bo'});
    const never = model.readSetting({path: 'a/b/c', label: 'C', changes: '0', latestMs: '', latestActor: ''});

    assert.equal(model.buttonTitle(changed, WORDING, formatNumber, relative), '3 changes · last 2 days ago by anna');
    assert.equal(model.buttonTitle(once, WORDING, formatNumber, relative), '1 change · last 0 days ago by bo');
    assert.equal(model.buttonTitle(never, WORDING, formatNumber, relative), 'Never changed since logging started');
});

test('the search asks for the exact path over all time, changes only, as many as the popover lists', () => {
    const search = model.search('carriers/freeshipping/active', NOW, 50);

    assert.deepEqual(plain(search), {
        range: {from: 0, to: NOW},
        chips: [{key: 'config', value: 'carriers/freeshipping/active'}],
        changesOnly: true,
        cursor: null,
        limit: 50
    });
    assert.equal(
        request.toQuery(search),
        'from=0&to=' + NOW + '&c%5B%5D=config%3Acarriers%2Ffreeshipping%2Factive&changes=1&limit=50'
    );
});

test('"Open in Activity Log" opens the log on the path over the last 30 days, keeping the secret key', () => {
    const link = model.logLink('/admin/activitylog/activity/index/key/def/', 'carriers/freeshipping/active');
    const query = link.slice(link.indexOf('?') + 1);
    const parsed = grammar.fromUrl(query, ['config']);

    assert.ok(link.startsWith('/admin/activitylog/activity/index/key/def/?'));
    assert.deepEqual(plain(parsed.chips), [{key: 'config', value: 'carriers/freeshipping/active'}]);
    assert.equal(parsed.rangeToken, '30d');
    assert.ok(model.logLink('/admin/log/?a=1', 'x/y/z').startsWith('/admin/log/?a=1&'));
});

test('the header counts the search\'s matches once they came, the button\'s count before, and "10,000+" past the bound', () => {
    const setting = model.readSetting({path: 'a/b/c', label: 'C', changes: '2', latestMs: '', latestActor: ''});

    assert.equal(model.countLine(null, setting, WORDING, formatNumber), '2 changes · all scopes');
    assert.equal(
        model.countLine(page([], {count: 1, atLeast: false}), setting, WORDING, formatNumber),
        '1 change · all scopes'
    );
    assert.equal(
        model.countLine(page([], {count: 10000, atLeast: true}), setting, WORDING, formatNumber),
        '10,000+ changes · all scopes'
    );
    assert.equal(
        model.countLine(page([], {count: 0, atLeast: false}), setting, WORDING, formatNumber),
        'no changes · all scopes'
    );
});

test('a setting without changes says since when the log knows of changes, in the store\'s timezone', () => {
    assert.equal(
        model.neverText(model.parseConfig(rawConfig()), zone),
        'Never changed since logging started (Sep 20, 2026). The value is the system default.'
    );
    assert.equal(
        model.neverText(model.parseConfig(rawConfig({loggingStartedMs: null})), zone),
        'Never changed since logging started. The value is the system default.'
    );
});

test('rows show when, who with the client a person acted through, the scope and the values as the admin sees them', () => {
    const rows = plain(model.rows(page([
        event(1, [field()], {name: 'anna', viaClient: 'Desk Client', viaChannel: 'mcp'}),
        event(2, [field({scope: 'Default Config', before: null, after: 'No'})]),
        event(3, [field({name: 'other/path/x', label: 'X'}), field({before: 'Yes', after: 'No'})]),
        event(4, [field({before: 'Flat rate', after: ''})])
    ], {count: 4, atLeast: false}), 'carriers/freeshipping/active', {zone: zone, relative: relative, emptyWord: '(empty)'}));

    assert.deepEqual(rows[0], {
        id: 1,
        when: zone.format(NOW - DAY, 'short'),
        ago: '1 days ago',
        actor: 'anna',
        via: {channel: 'mcp', client: 'Desk Client'},
        channel: 'entity',
        scope: 'Main Website',
        before: {text: 'No', kind: 'value'},
        after: {text: 'Yes', kind: 'value'}
    });
    assert.deepEqual([rows[1].via, rows[1].scope, rows[1].before, rows[1].after], [
        null,
        'Default Config',
        {text: '(empty)', kind: 'empty'},
        {text: 'No', kind: 'value'}
    ]);
    assert.deepEqual([rows[2].before, rows[2].after], [{text: 'Yes', kind: 'value'}, {text: 'No', kind: 'value'}],
        'The row of the setting\'s own path is shown, not the first');
    assert.deepEqual(rows[3].after, {text: '(empty)', kind: 'empty'}, 'A setting cleared to an empty text reads "(empty)"');
});

test('a masked value stays masked, and an activity without a detail shows no values', () => {
    const masked = event(1, [field({before: '••••••', after: '••••••', kind: 'masked'})]);
    const bare = Object.assign(event(2, []), {detail: null});
    const rows = plain(model.rows(page([masked, bare], null), 'carriers/freeshipping/active',
        {zone: zone, relative: relative, emptyWord: '(empty)'}));

    assert.deepEqual([rows[0].before, rows[0].after], [
        {text: '••••••', kind: 'masked'},
        {text: '••••••', kind: 'masked'}
    ]);
    assert.deepEqual([rows[1].scope, rows[1].before, rows[1].after], [null, null, null]);
});

test('the popover goes just below the row, from the value column, at most as wide as allowed, arrow on the button', () => {
    const atBody = {left: 0, top: -300, scrollLeft: 0, scrollTop: 0, clientLeft: 0, clientTop: 0};

    assert.deepEqual(plain(placement.place(
        {bottom: 400, right: 1300},
        {left: 500},
        {left: 820, width: 30},
        atBody,
        560
    )), {top: 707, left: 500, width: 560, arrowLeft: 329});
    assert.deepEqual(plain(placement.place(
        {bottom: 400, right: 900},
        {left: 500},
        {left: 870, width: 30},
        atBody,
        560
    )), {top: 707, left: 500, width: 400, arrowLeft: 376}, 'A narrow row narrows it; the arrow stays inside');
    assert.deepEqual(plain(placement.place(
        {bottom: 140, right: 900},
        {left: 500},
        {left: 520, width: 30},
        {left: 480, top: 100, scrollLeft: 0, scrollTop: 20, clientLeft: 1, clientTop: 1},
        560
    )), {top: 66, left: 19, width: 400, arrowLeft: 29}, 'Against a scrolled, bordered parent');
    assert.deepEqual(plain(placement.place(
        {bottom: 400, right: 1760},
        {left: 760},
        {left: 1686, width: 41},
        atBody,
        560
    )), {top: 707, left: 1167, width: 560, arrowLeft: 534}, 'A control as wide as the column: it ends at the button');
});
