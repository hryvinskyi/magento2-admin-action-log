/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load, activityLogTestHelper} = require('./amd.cjs');
const {FakeDocument, builder} = activityLogTestHelper('dom-fake.cjs');

const model = load('record-activity/panel-model');
const hints = load('record-activity/hint-model');
const openEvent = load('record-activity/open-event');
const mountHints = load('record-activity/field-hints-mount');
const range = load('Hryvinskyi_ActivityLog/js/search/range');
const zone = load('Hryvinskyi_ActivityLog/js/search/zone').create('Europe/Copenhagen', 'en_US');

// 2026-10-22 14:05 in Copenhagen (summer time ends on the 25th)
const NOW = Date.UTC(2026, 9, 22, 12, 5);
const MINUTE = 60000;
const formatNumber = (value) => new Intl.NumberFormat('en-US').format(value);
// Objects made inside the loaded modules come from another realm: compare their data, not their prototypes
const plain = (value) => JSON.parse(JSON.stringify(value));

/**
 * The config as the server prints it for a product
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
        days: 30,
        record: {
            key: 'product',
            value: '#1245',
            id: '1245',
            title: 'Joust Duffle Bag',
            naturalKey: {label: 'SKU', value: '24-MB01'}
        },
        summary: {count: 5, atLeast: false, latestMs: NOW - 40 * MINUTE, latestActor: 'john'},
        fields: [{value: 'price', label: 'Price'}, {value: 'status', label: 'Enable Product'}],
        actors: ['john', 'anna'],
        scope: 'default | All Store Views',
        wording: {
            idLine: 'ID %1',
            keyLine: '%1 %2',
            summaryOne: '%1 change in the last %2 days · last by %3 %4',
            summaryMany: '%1 changes in the last %2 days · last by %3 %4',
            summaryNone: 'No changes in the last %1 days',
            countOne: '%1 of %2 change',
            countMany: '%1 of %2 changes',
            atLeast: '%1+',
            badge: '%1 changes in the last %2 days',
            periods: {'7d': 'Last 7 days', '30d': 'Last 30 days', all: 'All time'},
            allTime: 'everything recorded',
            noMatchTitle: 'No changes match',
            noMatchText: 'Try "All fields", "Anyone" or a longer period.'
        }
    }, overrides);
}

test('panel config: the server\'s config is read whole, and a broken one is refused', () => {
    const config = model.parseConfig(rawConfig());

    assert.equal(config.record.value, '#1245');
    assert.deepEqual(plain(config.record.naturalKey), {label: 'SKU', value: '24-MB01'});
    assert.equal(config.summary.latestActor, 'john');
    assert.deepEqual(plain(config.fields.map((field) => field.value)), ['price', 'status']);
    assert.equal(config.scope, 'default | All Store Views');

    assert.equal(model.parseConfig(rawConfig({scope: null})).scope, null);
    assert.equal(model.parseConfig(rawConfig({
        record: {key: 'page', value: '#3', id: '3', title: null, naturalKey: null}
    })).record.naturalKey, null);
    assert.throws(() => model.parseConfig(null), model.PanelConfigError);
    assert.throws(() => model.parseConfig(rawConfig({record: {key: 'product'}})), model.PanelConfigError);
    assert.throws(
        () => model.parseConfig(rawConfig({wording: Object.assign({}, rawConfig().wording, {periods: {}})})),
        model.PanelConfigError
    );
    assert.throws(() => model.parseConfig(rawConfig({actors: [3]})), model.PanelConfigError);
});

test('panel search: the record by its exact value, then the field, the actor by exact name and the scopes', () => {
    const config = model.parseConfig(rawConfig());
    let state = model.initial();

    assert.deepEqual(plain(model.chips(config, state)), [{key: 'product', value: '#1245'}]);

    state = model.update(state, {field: 'price', actor: 'john', allScopes: false});
    assert.deepEqual(plain(model.chips(config, state)), [
        {key: 'product', value: '#1245'},
        {key: 'field', value: '"price"'},
        {key: 'by', value: '"john"'},
        {key: 'store', value: 'default | All Store Views'}
    ]);

    // A kind of record without store views ignores the switch
    assert.deepEqual(plain(
        model.chips(model.parseConfig(rawConfig({scope: null})), model.update(model.initial(), {allScopes: false}))),
        [{key: 'product', value: '#1245'}]
    );
});

test('panel search: the periods are the log\'s presets and "all time", over the channels that record changes', () => {
    const config = model.parseConfig(rawConfig());
    const thirty = model.search(config, model.initial(), NOW, zone);
    const seven = model.search(config, model.update(model.initial(), {period: '7d'}), NOW, zone);
    const all = model.search(config, model.update(model.initial(), {period: 'all'}), NOW, zone);

    assert.equal(thirty.changesOnly, true);
    assert.deepEqual(plain(thirty.range), plain(range.preset('30d', NOW, zone).range));
    assert.deepEqual(plain(seven.range), plain(range.preset('7d', NOW, zone).range));
    // 30 days back from the 22nd is local midnight of 23 September, in summer time (UTC+2)
    assert.equal(thirty.range.from, Date.UTC(2026, 8, 22, 22, 0));
    assert.deepEqual(plain(all.range), {preset: 'all', from: 0, to: NOW});
    assert.throws(() => model.update(model.initial(), {period: 'hour'}), RangeError);
});

test('panel opening: the button shows every field; a field\'s history shows it alone, by anyone, over 30 days', () => {
    const chosen = model.update(model.initial(), {field: 'status', actor: 'anna', period: '7d', allScopes: false});

    assert.deepEqual(plain(model.opened(chosen, null)),
        {field: '', actor: 'anna', period: '7d', allScopes: false});
    assert.deepEqual(plain(model.opened(chosen, 'price')),
        {field: 'price', actor: '', period: '30d', allScopes: false});
});

test('panel link: the activity log on the same filters over the last 30 days', () => {
    const config = model.parseConfig(rawConfig());
    const filters = model.chips(config, model.update(model.initial(), {field: 'price'}));
    const link = model.logLink('/admin/activitylog/activity/index/key/def/', filters);
    const params = new URLSearchParams(link.split('?')[1]);

    assert.equal(link.startsWith('/admin/activitylog/activity/index/key/def/?'), true);
    assert.equal(params.get('product'), '#1245');
    assert.equal(params.get('field'), '"price"', 'the field travels in its exact, quoted form');
    assert.equal(params.get('range'), '30d');
    assert.equal(model.logLink('/admin/log?a=1', filters).startsWith('/admin/log?a=1&product=%231245'), true);
});

test('panel grammar: the search bar and links keep the quotes of an exact field', () => {
    const grammar = load('Hryvinskyi_ActivityLog/js/search/grammar');
    const typed = grammar.parse('field: "price"', ['field', 'product']);
    const link = grammar.toUrl([{key: 'field', value: '"price"'}], '30d');

    assert.deepEqual(plain(typed.chip), {key: 'field', value: '"price"'});
    assert.deepEqual(plain(grammar.fromUrl(link, ['field']).chips), [{key: 'field', value: '"price"'}]);
    assert.deepEqual(plain(grammar.parse('field: price', ['field']).chip), {key: 'field', value: 'price'},
        'unquoted stays a part of a code');
});

test('panel empty state: the panel\'s own words', () => {
    assert.deepEqual(plain(model.noMatch(model.parseConfig(rawConfig()))),
        {title: 'No changes match', text: 'Try "All fields", "Anyone" or a longer period.'});
    assert.throws(() => model.parseConfig(rawConfig({wording: Object.assign({}, rawConfig().wording,
        {noMatchText: null})})), model.PanelConfigError);
});

test('panel texts: the period, the heading line, the count and the badge', () => {
    const config = model.parseConfig(rawConfig());
    const relative = (ms) => Math.round((NOW - ms) / MINUTE) + ' minutes ago';

    assert.deepEqual(plain(model.describe({preset: 'all', from: 0, to: NOW}, zone, config.wording)),
        {label: 'All time', span: 'everything recorded'});
    assert.equal(model.describe(range.preset('7d', NOW, zone).range, zone, config.wording).label, 'Last 7 days');

    assert.equal(model.subtitle(config, formatNumber, relative),
        'SKU 24-MB01 · ID 1245 · 5 changes in the last 30 days · last by john 40 minutes ago');
    assert.equal(
        model.subtitle(model.parseConfig(rawConfig({
            record: {key: 'block', value: '#7', id: '7', title: 'Footer', naturalKey: null},
            summary: {count: 1, atLeast: false, latestMs: NOW - 5 * MINUTE, latestActor: 'anna'}
        })), formatNumber, relative),
        'ID 7 · 1 change in the last 30 days · last by anna 5 minutes ago'
    );
    assert.equal(
        model.subtitle(model.parseConfig(rawConfig({
            summary: {count: 0, atLeast: false, latestMs: null, latestActor: null}
        })), formatNumber, relative),
        'SKU 24-MB01 · ID 1245 · No changes in the last 30 days'
    );

    assert.equal(model.resultCount({total: {count: 3, atLeast: false}, overall: {count: 3, atLeast: false}},
        {count: 12, atLeast: false}, config.wording, formatNumber), '3 of 12 changes',
    'M is all the record\'s changes, not the choices\' own total over all time');
    assert.equal(model.resultCount({total: {count: 1, atLeast: false}}, {count: 1, atLeast: false},
        config.wording, formatNumber), '1 of 1 change');
    assert.equal(model.resultCount({total: {count: 10000, atLeast: true}}, {count: 10000, atLeast: true},
        config.wording, formatNumber), '10,000+ of 10,000+ changes');
    assert.equal(model.resultCount({total: null}, {count: 4, atLeast: false}, config.wording, formatNumber), '');
    assert.equal(model.resultCount({total: {count: 3, atLeast: false}}, null, config.wording, formatNumber), '',
        'nothing is written until the record\'s total is known');

    assert.deepEqual(plain(model.recordSearch(config, NOW)), {
        chips: [{key: 'product', value: '#1245'}],
        range: {preset: 'all', from: 0, to: NOW},
        changesOnly: true,
        cursor: null,
        limit: 1
    }, 'the record\'s total: the record alone, over all time, whatever is chosen');

    assert.deepEqual(plain(model.badge(config, 5, false, formatNumber)),
        {badge: '5', label: '5 changes in the last 30 days'});
    assert.equal(model.badge(config, 10000, true, formatNumber).badge, '10,000+');
});

/**
 * The hints config as the product form passes it
 *
 * @param {Array<Object>} list
 * @returns {Object}
 */
function rawHints(list) {
    return {
        hints: list,
        serverNowMs: NOW,
        locale: 'en_US',
        wording: {
            changed: 'Changed %1 by %2',
            empty: '(empty)',
            masked: 'masked value',
            history: 'History',
            historyOf: 'History of %1'
        }
    };
}

test('field hints: who changed the field and when, with short values written out and others described', () => {
    const config = hints.parseConfig(rawHints([
        {field: 'price', label: 'Price', ms: NOW - 40 * MINUTE, actor: 'john', before: '34.00', after: '39.00',
            kind: 'short'},
        {field: 'meta_title', label: 'Meta Title', ms: NOW, actor: 'anna', before: null, after: '<b>Bags</b>',
            kind: 'short'},
        {field: 'description', label: 'Description', ms: NOW, actor: 'anna', before: null, after: null, kind: 'long'},
        {field: 'api_key', label: 'API key', ms: NOW, actor: 'anna', before: null, after: null, kind: 'masked'},
        {field: 'name', label: 'Name', ms: NOW, actor: 'anna', before: 'x'.repeat(200), after: 'y', kind: 'short'}
    ]));
    const relative = () => '40 minutes ago';
    const [price, title, description, secret, name] = config.hints.map((hint) => hints.parts(hint, config.wording,
        relative));

    assert.deepEqual(plain([price.lead, price.actor, price.trail]), ['Changed 40 minutes ago by ', 'john', '']);
    assert.deepEqual(plain(price.values),
        {before: {text: '34.00', empty: false}, after: {text: '39.00', empty: false}});
    assert.equal(price.historyLabel, 'History of Price');
    assert.deepEqual(plain(title.values.before), {text: '(empty)', empty: true});
    assert.equal(title.values.after.text, '<b>Bags</b>', 'Markup in a value stays text to be written as text');
    assert.equal(description.values, null);
    assert.equal(description.masked, null);
    assert.equal(secret.values, null);
    assert.equal(secret.masked, 'masked value');
    assert.equal(name.values.before.text.length, hints.SHOWN_CHARS);
    assert.equal(name.values.before.text.endsWith('…'), true);
});

test('field hints: a translation may put the actor first', () => {
    const config = hints.parseConfig(Object.assign(rawHints([
        {field: 'price', label: 'Price', ms: NOW, actor: 'john', before: '1', after: '2', kind: 'short'}
    ]), {}));
    const words = Object.assign({}, config.wording, {changed: '%2 a modifié ceci %1'});
    const parts = hints.parts(config.hints[0], words, () => 'il y a 5 minutes');

    assert.deepEqual(plain([parts.lead, parts.actor, parts.trail]),
        ['', 'john', ' a modifié ceci il y a 5 minutes']);
});

test('field hints: a config with a malformed field or an unknown kind of value is refused', () => {
    const hint = {field: 'price', label: 'Price', ms: NOW, actor: 'john', before: '1', after: '2', kind: 'short'};

    assert.equal(hints.parseConfig(rawHints([hint])).hints.length, 1);
    assert.throws(() => hints.parseConfig(rawHints([Object.assign({}, hint, {field: 'a"] [b'})])),
        hints.HintConfigError);
    assert.throws(() => hints.parseConfig(rawHints([Object.assign({}, hint, {kind: 'html'})])),
        hints.HintConfigError);
    assert.throws(() => hints.parseConfig(rawHints([Object.assign({}, hint, {ms: 'yesterday'})])),
        hints.HintConfigError);
    assert.throws(() => hints.parseConfig({hints: [], serverNowMs: NOW, locale: 'en_US', wording: {}}),
        hints.HintConfigError);
});

/**
 * A product form drawn far enough for the hints: one field in its attribute's container, and the hints element the
 * page prints, with its JSON as the server prints it (tags hex-escaped)
 *
 * @param {Object} config
 * @returns {{doc: FakeDocument, root: Object, control: Object}}
 */
function hintsPage(config) {
    const doc = new FakeDocument();
    const el = builder(doc);
    const control = el('div', {className: 'admin__field-control'});
    const json = JSON.stringify(config).replace(/</g, '\\u003C').replace(/>/g, '\\u003E');
    const root = el('div', {'data-role': 'record-activity-hints', hidden: true}, [
        el('script', {type: 'application/json', 'data-role': 'record-activity-hints-config', text: json})
    ]);

    doc.defaultView = {
        escaped: [],
        CSS: {escape: (value) => {
            doc.defaultView.escaped.push(value);

            return value;
        }},
        requestAnimationFrame: (callback) => callback(),
        MutationObserver: class {
            observe() {}

            disconnect() {}
        },
        Element: function Element() {},
        CustomEvent: function CustomEvent() {}
    };
    doc.body.appendChild(el('div', {'data-index': 'container_meta_title'}, [
        el('div', {'data-index': 'meta_title'}, [control])
    ]));
    doc.body.appendChild(root);

    return {doc, root, control};
}

test('field hints on the page: a stored value that looks like a template or markup is written as text', () => {
    const template = '${\nalert(1)}';
    const markup = '<img src=x onerror=alert(1)>';
    const {root, control} = hintsPage(rawHints([
        {field: 'meta_title', label: 'Meta Title', ms: NOW - 40 * MINUTE, actor: 'anna', before: markup,
            after: template, kind: 'short'}
    ]));

    const unmount = mountHints({}, root);
    const hint = control.querySelector('div[data-role="record-activity-hint"]');

    assert.notEqual(hint, null, 'The field gets its hint');
    assert.deepEqual(root.ownerDocument.defaultView.escaped, ['meta_title'],
        'The field code goes into the selectors escaped');
    assert.equal(hint.querySelector('span.record-activity-hint__value--before').textContent, markup);
    assert.equal(hint.querySelector('span.record-activity-hint__value--after').textContent, template);
    assert.deepEqual(
        hint.descendants().map((node) => node.tagName).filter((tag) => !['SPAN', 'STRONG', 'BUTTON'].includes(tag)),
        [],
        'No element is made from a value'
    );
    assert.equal(hint.querySelector('span.record-activity-hint__value--after').children.length, 0);

    unmount();
    assert.equal(control.children.length, 0, 'Unmounting removes the hints');
});

test('field hints on the page: a config that is not JSON costs the hints, never the page', () => {
    const {root, control} = hintsPage({});
    const script = root.querySelector('script[data-role="record-activity-hints-config"]');
    const warn = console.warn;
    const warnings = [];

    script.textContent = '{"hints": [';
    console.warn = (...args) => warnings.push(args);
    try {
        const unmount = mountHints({}, root);

        assert.equal(typeof unmount, 'function');
        unmount();
    } finally {
        console.warn = warn;
    }
    assert.equal(control.children.length, 0);
    assert.equal(warnings.length, 1);
});

test('open event: the field asked for travels, anything else reads as every field or is ignored', () => {
    assert.deepEqual(plain(openEvent.detail('price')), {field: 'price'});
    assert.deepEqual(plain(openEvent.detail('a"] b')), {field: null});
    assert.deepEqual(plain(openEvent.read({field: 'price'})), {field: 'price'});
    assert.deepEqual(plain(openEvent.read({field: null})), {field: null});
    assert.equal(openEvent.read({field: 'x y'}), null);
    assert.equal(openEvent.read('price'), null);
});
