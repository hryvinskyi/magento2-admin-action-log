/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load, activityLogTestHelper} = require('./amd.cjs');
const {FakeDocument, builder, fire} = activityLogTestHelper('dom-fake.cjs');

const model = load('config-history/history-model');
const placement = load('config-history/placement');
const keepEnabled = load('config-history/keep-enabled');
const mountPopover = load('config-history/popover');
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
const flush = () => new Promise((resolve) => setImmediate(resolve));

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
    buttonNever: 'Never changed since logging started',
    cutNote: 'Only the first 4,000 characters of each value are shown.'
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
        after: {text: 'Yes', kind: 'value'},
        split: null,
        truncated: false
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

/**
 * The changed lines of a split diff: each changed row's before and after text, a missing side as null
 *
 * @param {Object} split As model/split-diff's split() gives it
 * @returns {Array<Array<?string>>}
 */
function changedLines(split) {
    const text = (side) => (side === null ? null : side.parts.map((part) => part.text).join(''));

    return split.rows
        .filter((row) => row.type === 'line' && (row.before === null || row.after === null || row.before.op !== 'equal'))
        .map((row) => [text(row.before), text(row.after)]);
}

test('a long value carries the split diff of its raw before and after; a short, masked or JSON list value carries none', () => {
    const lines = Array.from({length: 12}, (unused, index) => 'line ' + (index + 1));
    const long = field({
        name: 'design/head/includes',
        kind: 'long',
        before: lines.join('\n'),
        after: lines.map((line) => (line === 'line 6' ? 'line six' : line)).join('\n')
    });
    const rows = plain(model.rows(page([
        event(1, [long]),
        event(2, [field()]),
        event(3, [field({kind: 'masked', before: '••••••', after: '••••••'})]),
        event(4, [field({kind: 'json', before: '["a","b"]', after: '["a","c"]'})]),
        event(5, [field({kind: 'json', before: '{"a":1}', after: '{"a":2}'})]),
        event(6, [field({kind: 'long', before: null, after: 'first\nsecond\nthird\nfourth'})])
    ], null), 'carriers/freeshipping/active', {zone: zone, relative: relative, emptyWord: '(empty)'}));

    assert.equal(rows[0].split.changes, 1);
    assert.deepEqual(changedLines(rows[0].split), [['line 6', 'line six']], 'The changed line, paired');
    assert.deepEqual(rows[0].split.rows.map((row) => row.type), ['fold', 'line', 'line', 'line', 'line', 'line', 'line',
        'line', 'fold'], 'Unchanged stretches folded, three lines of context kept on each side');
    assert.deepEqual([rows[0].before.text, rows[0].after.kind], [long.before, 'value'], 'Before and after stay');
    assert.deepEqual([rows[1].split, rows[2].split, rows[3].split], [null, null, null]);
    assert.deepEqual(changedLines(rows[4].split), [['{"a":1}', '{"a":2}']], 'A JSON value that is not a list is long text');
    assert.deepEqual(changedLines(rows[5].split), [
        [null, 'first'], [null, 'second'], [null, 'third'], [null, 'fourth']
    ], 'A missing value is no lines, never "(empty)"');
});

test('a row says whether the search cut the setting\'s values, and a row without the setting says it did not', () => {
    const lines = Array.from({length: 12}, (unused, index) => 'line ' + (index + 1));
    const rows = plain(model.rows(page([
        event(1, [field({kind: 'long', before: lines.join('\n'), after: lines.join('\n') + '\nline 13', truncated: true})]),
        event(2, [field({truncated: true})]),
        event(3, [field({name: 'other/path/x', truncated: true}), field()]),
        Object.assign(event(4, []), {detail: null})
    ], null), 'carriers/freeshipping/active', {zone: zone, relative: relative, emptyWord: '(empty)'}));

    assert.deepEqual(rows.map((row) => row.truncated), [true, true, false, false],
        'The flag of the setting\'s own field, not of another field of the same activity');
});

test('a long value that changed one word and every line ending shows that one line changed, and nothing for the endings', () => {
    const rows = plain(model.rows(page([event(1, [field({
        kind: 'long',
        before: 'one\r\ntwo\r\nthree\r\nfour',
        after: 'one\ntwo\nthirty\nfour'
    })])], null), 'carriers/freeshipping/active', {zone: zone, relative: relative, emptyWord: '(empty)'}));

    assert.equal(rows[0].split.changes, 1);
    assert.deepEqual(changedLines(rows[0].split), [['three', 'thirty']]);
});

test('the popover goes just below the button, not below the row, from the value column, at most as wide as allowed, arrow on the button', () => {
    const atBody = {left: 0, top: -300, scrollLeft: 0, scrollTop: 0, clientLeft: 0, clientTop: 0};

    assert.deepEqual(plain(placement.place(
        {bottom: 400, right: 1300},
        {left: 500},
        {left: 820, width: 30, bottom: 350},
        atBody,
        560
    )), {top: 662, left: 500, width: 560, arrowLeft: 329});
    assert.deepEqual(plain(placement.place(
        {bottom: 400, right: 900},
        {left: 500},
        {left: 870, width: 30, bottom: 350},
        atBody,
        560
    )), {top: 662, left: 500, width: 400, arrowLeft: 376}, 'A narrow row narrows it; the arrow stays inside');
    assert.deepEqual(plain(placement.place(
        {bottom: 140, right: 900},
        {left: 500},
        {left: 520, width: 30, bottom: 120},
        {left: 480, top: 100, scrollLeft: 0, scrollTop: 20, clientLeft: 1, clientTop: 1},
        560
    )), {top: 51, left: 19, width: 400, arrowLeft: 29}, 'Against a scrolled, bordered parent');
    assert.deepEqual(plain(placement.place(
        {bottom: 400, right: 1760},
        {left: 760},
        {left: 1686, width: 41, bottom: 350},
        atBody,
        560
    )), {top: 662, left: 1167, width: 560, arrowLeft: 534}, 'A control as wide as the column: it ends at the button');
});

/**
 * A button as the scope toggle and the page see it: a disabled property reflected as an attribute, and classes
 *
 * @returns {Object}
 */
function fakeButton() {
    const classes = new Set(['config-history__button']);

    return {
        disabled: false,
        classList: {
            contains: (name) => classes.has(name),
            add: (name) => classes.add(name),
            remove: (name) => classes.delete(name)
        },
        classes
    };
}

/**
 * A mutation observer the test fires by hand, as the page would after the script that changed the button
 */
class FakeObserver {
    static last = null;

    constructor(callback) {
        this.callback = callback;
        this.observed = [];
        this.disconnected = false;
        FakeObserver.last = this;
    }

    observe(target, options) {
        this.observed.push({target, options});
    }

    disconnect() {
        this.disconnected = true;
    }

    fire(targets) {
        this.callback(targets.map((target) => ({type: 'attributes', target})));
    }
}

/**
 * What the admin's scope toggle does to every control of an inheriting setting's value cell
 *
 * @param {Object} button
 * @param {boolean} inherits
 */
function toggle(button, inherits) {
    button.disabled = inherits;
    if (inherits) {
        button.classList.add('disabled');
    } else {
        button.classList.remove('disabled');
    }
}

test('a history button the scope toggle disabled before the popover started is enabled when it starts', () => {
    const button = fakeButton();
    toggle(button, true);

    keepEnabled.keep([button], FakeObserver, new AbortController().signal);

    assert.equal(button.disabled, false);
    assert.equal(button.classes.has('disabled'), false);
    assert.deepEqual(
        plain(FakeObserver.last.observed.map((entry) => entry.options)),
        [{attributes: true, attributeFilter: ['disabled', 'class']}],
        'Only the button\'s disabled state and its classes are watched'
    );
    assert.equal(FakeObserver.last.observed[0].target, button);
});

test('a history button stays enabled however often "Use Default" is ticked and unticked', () => {
    const button = fakeButton();
    keepEnabled.keep([button], FakeObserver, new AbortController().signal);
    const observer = FakeObserver.last;

    [false, true, false, true].forEach((inherits) => {
        toggle(button, inherits);
        observer.fire([button]);

        assert.equal(button.disabled, false, inherits ? 'Ticked' : 'Unticked');
        assert.equal(button.classes.has('disabled'), false);
        assert.equal(button.classes.has('config-history__button'), true, 'Its own classes stay');
    });
});

test('only the history buttons are kept enabled, and the watch ends with the popover', () => {
    const button = fakeButton();
    const lifetime = new AbortController();
    keepEnabled.keep([button], FakeObserver, lifetime.signal);
    const observer = FakeObserver.last;

    lifetime.abort();

    assert.equal(observer.disconnected, true);
    assert.equal(observer.observed.length, 1, 'Nothing but the button handed over is observed');
});

test('nothing is observed without a button, or once the popover is gone', () => {
    FakeObserver.last = null;
    keepEnabled.keep([], FakeObserver, new AbortController().signal);
    assert.equal(FakeObserver.last, null);

    const ended = new AbortController();
    ended.abort();
    const button = fakeButton();
    toggle(button, true);
    keepEnabled.keep([button], FakeObserver, ended.signal);
    assert.equal(FakeObserver.last, null);
    assert.equal(button.disabled, true, 'An ended page is left as it is');
});

/**
 * The timeline's config as its assets print it: every word it requires, the verbs, outcomes and one channel
 *
 * @returns {Object}
 */
function timelineConfig() {
    const words = {};

    load('Hryvinskyi_ActivityLog/js/timeline/config').WORDING.forEach((name) => {
        words[name] = name;
    });
    Object.assign(words, {via: 'via', empty: '(empty)', loading: 'Loading…', foldLines: 'Show %1 unchanged lines',
        noLine: 'No line', truncated: 'Only the start of the value is shown'});

    return {
        wording: words,
        verbs: Object.fromEntries(request.VERBS.map((verb) => [verb, verb])),
        channels: {entity: {label: 'Record change', bg: '#eee', fg: '#111'}},
        actorTypes: {admin: 'Admin'},
        outcomes: Object.fromEntries(request.OUTCOMES.map((outcome) => [outcome, outcome])),
        candidateLimit: 10000
    };
}

/**
 * A window whose fetch the test answers; its timers never fire on their own
 *
 * @returns {Object}
 */
function fakeWindow() {
    const fetches = [];

    return {
        fetches,
        AbortController,
        navigator: {},
        setTimeout: () => 0,
        clearTimeout: () => undefined,
        addEventListener: () => undefined,
        MutationObserver: FakeObserver,
        fetch: (url, init) => new Promise((resolve, reject) => {
            fetches.push({url, resolve});
            init.signal.addEventListener('abort', () => reject(new Error('aborted')));
        })
    };
}

/**
 * Stores › Configuration with one setting and its history button, the timeline's assets and the popover's config and
 * templates as the layout prints them, the popover mounted
 *
 * @returns {{doc: FakeDocument, win: Object, button: Object}}
 */
function configPage() {
    const doc = new FakeDocument();
    const win = fakeWindow();
    const el = builder(doc);
    const slot = (tag, name, attrs = {}, children = []) => el(tag, Object.assign({'data-slot': name}, attrs), children);
    const box = (element, rect) => Object.assign(element, {getBoundingClientRect: () => rect});
    const button = box(el('button', {
        type: 'button',
        'data-role': 'config-history-open',
        'data-path': 'carriers/freeshipping/active',
        'data-label': 'Enabled',
        'data-changes': '2',
        'data-latest-ms': String(NOW - DAY),
        'data-latest-actor': 'john'
    }), {left: 820, width: 30, bottom: 350});
    const column = box(el('td', {className: 'value'}, [button]), {left: 500});
    const assets = load('Hryvinskyi_ActivityLog/js/timeline/assets').TEMPLATES.map((name) => el('template',
        {'data-template': name}, [el('div')]));

    doc.defaultView = win;
    Object.assign(doc.body, {scrollLeft: 0, scrollTop: 0, clientLeft: 0, clientTop: 0});
    box(doc.body, {left: 0, top: 0});
    doc.body.appendChild(el('table', {}, [box(el('tr', {}, [column]), {bottom: 400, right: 1300})]));
    doc.body.appendChild(el('div', {'data-role': 'activity-timeline-assets', hidden: true}, [
        el('script', {'data-role': 'activity-timeline-config', text: JSON.stringify(timelineConfig())})
    ].concat(assets)));
    const root = el('div', {'data-role': 'config-history', hidden: true}, [
        el('script', {'data-role': 'config-history-config', text: JSON.stringify(rawConfig())}),
        el('template', {'data-template': 'config-history-popover'}, [
            el('div', {className: 'config-history-popover', role: 'dialog'}, [
                slot('span', 'arrow'),
                slot('div', 'title'),
                slot('span', 'path'),
                el('button', {type: 'button', 'data-action': 'copy'}, [slot('span', 'copy-label')]),
                slot('span', 'count'),
                el('button', {type: 'button', 'data-action': 'close'}),
                slot('div', 'state', {hidden: true}, [
                    slot('span', 'state-text'),
                    el('button', {type: 'button', 'data-action': 'retry', hidden: true})
                ]),
                slot('div', 'rows'),
                slot('a', 'log-link', {href: '#'})
            ])
        ]),
        el('template', {'data-template': 'config-history-row'}, [
            el('div', {className: 'config-history-row'}, [
                slot('div', 'when'),
                slot('div', 'ago'),
                slot('div', 'who'),
                slot('div', 'values', {className: 'config-history-row__values'}, [
                    slot('span', 'before', {className: 'config-history-row__value'}),
                    el('span', {className: 'config-history-row__arrow', text: '→'}),
                    slot('span', 'after', {className: 'config-history-row__value'})
                ])
            ])
        ])
    ]);
    doc.body.appendChild(root);
    mountPopover({}, root);

    return {doc, win, button};
}

/**
 * Opens the popover and answers its search with the given activities
 *
 * @param {{doc: FakeDocument, win: Object, button: Object}} page
 * @param {Array<Object>} events As the search endpoint answers them
 * @returns {Promise<Object>} The popover
 */
async function openWith(page, events) {
    fire(page.button, 'click');
    page.win.fetches[0].resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({
            events: events,
            nextCursor: null,
            narrowed: false,
            total: {count: events.length, atLeast: false},
            outside: null,
            latestOutsideMs: null,
            overall: null,
            journalEmpty: false
        })
    });
    await flush();

    return page.doc.querySelector('.config-history-popover');
}

test('the popover draws a long value as a split diff in its values slot, and a short one as before → after', async () => {
    const lines = Array.from({length: 12}, (unused, index) => 'line ' + (index + 1));
    const page = configPage();
    const popover = await openWith(page, [
        event(1, [field({
            kind: 'long',
            before: lines.join('\n'),
            after: lines.map((line) => (line === 'line 6' ? 'line six' : line)).join('\n')
        })]),
        event(2, [field()])
    ]);
    const [longRow, shortRow] = popover.querySelectorAll('.config-history-row');
    const values = longRow.querySelector('[data-slot="values"]');
    const table = values.querySelector('table.split-diff');

    assert.ok(table, 'The long value is a split diff table');
    assert.equal(values.classList.contains('config-history-row__values--split'), true);
    assert.deepEqual(values.children.filter((child) => child !== table).map((child) => [child.hidden, child.textContent]),
        [[true, ''], [true, '→'], [true, '']], 'Before → after is left empty and hidden');
    assert.deepEqual(table.querySelectorAll('del').map((node) => node.textContent), ['6']);
    assert.deepEqual(table.querySelectorAll('ins').map((node) => node.textContent), ['six']);
    assert.deepEqual(table.querySelectorAll('[data-action="fold"]').map((node) => node.textContent),
        ['Show 2 unchanged lines', 'Show 3 unchanged lines'], 'The fold wording comes from the timeline\'s');

    assert.equal(shortRow.querySelector('table'), null);
    assert.equal(shortRow.querySelector('[data-slot="values"]').classList.contains('config-history-row__values--split'),
        false);
    assert.deepEqual([
        shortRow.querySelector('[data-slot="before"]').textContent,
        shortRow.querySelector('[data-slot="after"]').textContent
    ], ['No', 'Yes']);
});

test('a split diff of values the search cut says so in a line under its table; an uncut or short value says nothing', async () => {
    const lines = Array.from({length: 12}, (unused, index) => 'line ' + (index + 1));
    const long = {kind: 'long', before: lines.join('\n'), after: lines.map((line) => (line === 'line 6' ? 'line six' : line)).join('\n')};
    const page = configPage();
    const popover = await openWith(page, [
        event(1, [field(Object.assign({truncated: true}, long))]),
        event(2, [field(long)]),
        event(3, [field({truncated: true})])
    ]);
    const [cut, whole, short] = popover.querySelectorAll('.config-history-row');
    const values = cut.querySelector('[data-slot="values"]');
    const notes = values.querySelectorAll('.config-history-row__note');

    assert.deepEqual(notes.map((note) => note.textContent), ['Only the first 4,000 characters of each value are shown.'],
        'The popover\'s own sentence for a value the search cut');
    assert.equal(values.children[values.children.length - 1], notes[0], 'The note comes after the table');
    assert.ok(values.children.indexOf(values.querySelector('table.split-diff')) < values.children.indexOf(notes[0]));
    assert.equal(whole.querySelector('.config-history-row__note'), null);
    assert.equal(short.querySelector('.config-history-row__note'), null, 'Only a split diff carries the note');
});

test('opening a fold in the popover shows its lines and leaves the popover open; a click outside still closes it', async () => {
    const lines = Array.from({length: 12}, (unused, index) => 'line ' + (index + 1));
    const page = configPage();
    const popover = await openWith(page, [event(1, [field({
        kind: 'long',
        before: lines.join('\n'),
        after: lines.map((line) => (line === 'line 6' ? 'line six' : line)).join('\n')
    })])]);
    const fold = popover.querySelector('[data-action="fold"]');
    const group = fold.closest('.split-diff__fold-group');

    fire(fold, 'click');

    assert.equal(page.doc.body.contains(popover), true, 'The popover stays open');
    assert.equal(popover.contains(fold), true, 'The fold\'s button is still inside it, hidden with its row');
    assert.equal(fold.closest('.split-diff__fold').hidden, true);
    assert.deepEqual(group.children.slice(1).map((row) => [row.hidden, row.textContent]),
        [[false, '1line 11line 1'], [false, '2line 22line 2']]);
    assert.equal(page.doc.activeElement, group.children[1], 'The focus moves to the first line shown');

    fire(page.doc.body, 'click');

    assert.equal(page.doc.body.contains(popover), false, 'A click outside closes it');
});
