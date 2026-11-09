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

const mount = load('view/entity-diff');

const TWENTY_LINES = Array.from({length: 20}, (unused, index) => 'line ' + (index + 1));
const LABELS = {'data-fold-label': 'Show %1 unchanged lines', 'data-filler-label': 'No line'};

/**
 * The panel as the server prints it: a long changed value with a change in the middle of twenty lines, a long value
 * added where there was none, a long value that changed one word and every line ending, a short changed value, a
 * masked long value and a long unchanged one
 *
 * @param {Object=} sectionAttrs The section's attributes; the wording by default
 * @param {Object=} overrides JSON block text by field, in place of the real values
 * @returns {{doc: FakeDocument, section: Object, row: function(string): Object, filter: Object}}
 */
function panel(sectionAttrs = LABELS, overrides = {}) {
    const doc = new FakeDocument();
    const el = builder(doc);

    /**
     * @param {?string} value
     * @param {string} side
     * @param {Object} field
     * @returns {Object} A value cell
     */
    const cell = (value, side, field) => {
        const children = [value === null
            ? el('span', {className: 'admin-action-value admin-action-value--empty', text: '(empty)'})
            : el('span', {className: 'admin-action-value', text: value})];

        if (field.long && side === 'after') {
            children.push(el('button', {
                type: 'button',
                className: 'admin-action-diff__expand',
                'data-role': 'expand',
                'data-collapse-label': 'Collapse',
                text: 'Expand full text'
            }));
        }

        return el('td', {}, children);
    };

    /**
     * @param {{field: string, before: ?string, after: ?string, changed: boolean, long: boolean, split: boolean,
     *     masked: boolean, shownBefore: string}} field shownBefore: the before cell's text, when it differs from the
     *     value
     * @returns {Object} A field row
     */
    const fieldRow = (field) => {
        const head = [];
        const attrs = {
            'data-role': 'field-row',
            'data-field': field.field,
            'data-changed': field.changed ? '1' : '0',
            className: 'admin-action-diff__row' + (field.changed ? '' : ' is-unchanged')
        };

        if (field.masked) {
            head.push(el('span', {className: 'admin-action-tag', text: 'masked'}));
        }
        if (field.split) {
            attrs['data-split'] = '1';
            head.push(el('script', {
                type: 'application/json',
                'data-role': 'split-values',
                text: overrides[field.field] !== undefined
                    ? overrides[field.field]
                    : JSON.stringify({before: field.before || '', after: field.after || ''})
            }));
        }

        return el('tr', attrs, [
            el('th', {text: field.field}, head),
            cell(field.shownBefore !== undefined ? field.shownBefore : field.before, 'before', field),
            cell(field.after, 'after', field)
        ]);
    };

    const fields = [
        {
            field: 'content',
            before: TWENTY_LINES.join('\n'),
            after: TWENTY_LINES.map((line) => (line === 'line 10' ? 'line ten' : line)).join('\n'),
            changed: true,
            long: true,
            split: true
        },
        {field: 'description', before: null, after: 'first\nsecond\nthird\nfourth', changed: true, long: true, split: true},
        {
            field: 'endings',
            before: 'one\r\ntwo\r\nthree\r\nfour',
            // What the HTML parser leaves of the value in the cell's text
            shownBefore: 'one\ntwo\nthree\nfour',
            after: 'one\ntwo\nthirty\nfour',
            changed: true,
            long: true,
            split: true
        },
        {field: 'title', before: 'Old title', after: 'New title', changed: true, long: false, split: false},
        {field: 'password', before: '******', after: '******', changed: true, long: false, split: false, masked: true},
        {field: 'summary', before: 'a\nb\nc\nd', after: 'a\nb\nc\nd', changed: false, long: true, split: false}
    ];
    const filter = el('input', {type: 'search', 'data-role': 'field-filter'});
    const section = el('section', Object.assign({className: 'activity-section admin-action-panel'}, sectionAttrs), [
        el('div', {className: 'admin-action-diff__toolbar'}, [
            el('label', {}, [el('input', {type: 'checkbox', 'data-role': 'show-unchanged'})]),
            filter
        ]),
        el('table', {className: 'admin-action-diff'}, [
            el('tbody', {'data-role': 'scope-group'}, [
                el('tr', {className: 'admin-action-diff__scope'}, [
                    el('td', {}, [el('span', {'data-role': 'scope-count', 'data-label': '%1 field(s)'})])
                ]),
                ...fields.map(fieldRow)
            ]),
            el('tbody', {}, [el('tr', {'data-role': 'no-match', hidden: true})])
        ])
    ]);

    doc.body.appendChild(section);

    return {
        doc,
        section,
        filter,
        row: (name) => section.querySelector('[data-role="field-row"][data-field="' + name + '"]')
    };
}

/**
 * @param {Object} row A field row
 * @returns {Array<string>} The tag names of the row's cells
 */
const cellsOf = (row) => row.children.map((child) => child.tagName);

/**
 * @param {Object} row A field row drawn as a split diff
 * @returns {Array<{hidden: boolean, change: boolean, before: string, after: string}>} The table's line rows
 */
function linesOf(row) {
    return row.querySelectorAll('tr.split-diff__row').map((line) => ({
        hidden: line.hidden,
        change: line.classList.contains('split-diff__row--change'),
        before: line.children[1].textContent,
        after: line.children[3].textContent
    }));
}

test('a long changed value becomes one cell spanning Before and After with its split diff', () => {
    const {section, row} = panel();

    mount({}, section);

    const content = row('content');
    const split = content.children[content.children.length - 1];

    assert.deepEqual(cellsOf(content), ['TH', 'TD']);
    assert.equal(split.className, 'admin-action-diff__split');
    assert.equal(split.getAttribute('colspan'), '2');
    assert.ok(content.classList.contains('is-split'));
    assert.equal(split.children.length, 1);
    assert.ok(split.children[0].classList.contains('split-diff'));

    const shown = linesOf(content).filter((line) => !line.hidden);

    assert.deepEqual(shown.map((line) => line.before), ['line 7', 'line 8', 'line 9', 'line 10', 'line 11', 'line 12',
        'line 13']);
    assert.deepEqual(shown.filter((line) => line.change).map((line) => [line.before, line.after]),
        [['line 10', 'line ten']]);
    assert.deepEqual(content.querySelectorAll('[data-action="fold"]').map((button) => button.textContent),
        ['Show 6 unchanged lines', 'Show 7 unchanged lines']);
});

test('a value added where there was none has no lines before, never the text "(empty)"', () => {
    const {section, row} = panel();

    mount({}, section);

    const lines = linesOf(row('description'));

    assert.deepEqual(lines.map((line) => line.after), ['first', 'second', 'third', 'fourth']);
    assert.deepEqual(lines.map((line) => line.before), ['No line', 'No line', 'No line', 'No line']);
    assert.ok(row('description').querySelectorAll('.split-diff__num--empty').every((num) => num.textContent === ''));
    assert.ok(!row('description').textContent.includes('(empty)'));
});

test('a value that changed one word and every line ending shows that one line changed, and nothing for the endings', () => {
    const {section, row} = panel();

    mount({}, section);

    assert.deepEqual(linesOf(row('endings')).map((line) => [line.change, line.before, line.after]), [
        [false, 'one', 'one'],
        [false, 'two', 'two'],
        [true, 'three', 'thirty'],
        [false, 'four', 'four']
    ]);
});

test('a value whose line endings alone changed keeps the cells the server drew', () => {
    const endingsOnly = JSON.stringify({before: 'one\r\ntwo\r\nthree\r\nfour', after: 'one\ntwo\nthree\nfour'});
    const {section, row} = panel(LABELS, {endings: endingsOnly});

    mount({}, section);

    assert.deepEqual(cellsOf(row('endings')), ['TH', 'TD', 'TD']);
    assert.ok(!row('endings').classList.contains('is-split'));
});

test('short, masked and unchanged rows keep the cells the server drew', () => {
    const {section, row} = panel();

    mount({}, section);

    ['title', 'password', 'summary'].forEach((name) => {
        assert.deepEqual(cellsOf(row(name)), ['TH', 'TD', 'TD'], name);
        assert.ok(!row(name).classList.contains('is-split'), name);
        assert.equal(row(name).querySelector('table'), null, name);
    });
    assert.equal(row('title').children[1].textContent, 'Old title');
    assert.equal(row('password').children[2].textContent, '******');
});

test('pressing a fold button shows the rows it hid and moves the focus to the first of them', () => {
    const {doc, section, row} = panel();

    mount({}, section);

    const button = row('content').querySelectorAll('[data-action="fold"]')[0];
    const group = button.closest('tbody');

    fire(button, 'click');

    const lines = group.querySelectorAll('tr.split-diff__row');

    assert.equal(lines.length, 6);
    assert.ok(lines.every((line) => !line.hidden));
    assert.ok(button.closest('tr').hidden);
    assert.equal(doc.activeElement, lines[0]);
    assert.equal(linesOf(row('content')).filter((line) => !line.hidden).length, 13);
});

test('the "Expand full text" button of an unchanged long value still expands and collapses it', () => {
    const {section, row} = panel();

    mount({}, section);

    const summary = row('summary');
    const button = summary.querySelector('[data-role="expand"]');

    fire(button, 'click');
    assert.ok(summary.classList.contains('is-expanded'));
    assert.equal(button.textContent, 'Collapse');

    fire(button, 'click');
    assert.ok(!summary.classList.contains('is-expanded'));
    assert.equal(button.textContent, 'Expand full text');
});

test('the field filter hides a split row the filter does not match', () => {
    const {section, row, filter} = panel();

    mount({}, section);
    filter.value = 'title';
    fire(filter, 'input');

    assert.ok(row('content').hidden);
    assert.ok(!row('title').hidden);

    filter.value = 'cont';
    fire(filter, 'input');

    assert.ok(!row('content').hidden);
    assert.ok(row('content').classList.contains('is-split'));
});

test('a panel without the wording keeps every row as the server drew it', () => {
    const {section, row} = panel({});

    mount({}, section);

    ['content', 'description', 'endings'].forEach((name) => {
        assert.deepEqual(cellsOf(row(name)), ['TH', 'TD', 'TD'], name);
        assert.equal(row(name).querySelector('table'), null, name);
    });
});

test('a row whose JSON block is malformed keeps its cells, and says why in the console', (t) => {
    const errors = [];

    t.mock.method(console, 'error', (message) => errors.push(message));

    const {section, row} = panel(LABELS, {content: '{"before": "only one side"}', description: 'not json'});

    mount({}, section);

    assert.deepEqual(cellsOf(row('content')), ['TH', 'TD', 'TD']);
    assert.deepEqual(cellsOf(row('description')), ['TH', 'TD', 'TD']);
    assert.deepEqual(cellsOf(row('endings')), ['TH', 'TD']);
    assert.equal(errors.length, 2);
    assert.match(errors[0], /"content"/);
    assert.match(errors[1], /"description"/);
});

test('a row whose values show no change line by line keeps the cells the server drew', () => {
    const same = JSON.stringify({before: 'a\nb\nc\nd', after: 'a\nb\nc\nd'});
    const {section, row} = panel(LABELS, {content: same});

    mount({}, section);

    assert.deepEqual(cellsOf(row('content')), ['TH', 'TD', 'TD']);
    assert.ok(!row('content').classList.contains('is-split'));
});

/**
 * The panel with one long changed value: line 1 changed, then the given number of unchanged lines
 *
 * @param {number} unchanged
 * @param {?string} lang The page's language, or null for a page without one
 * @returns {{section: Object, row: Object}}
 */
function longFoldPanel(unchanged, lang) {
    const doc = new FakeDocument();
    const el = builder(doc);
    const tail = Array.from({length: unchanged}, (unused, index) => 'same ' + (index + 1));
    const values = JSON.stringify({before: ['old'].concat(tail).join('\n'), after: ['new'].concat(tail).join('\n')});
    const row = el('tr', {'data-role': 'field-row', 'data-field': 'content', 'data-changed': '1', 'data-split': '1'}, [
        el('th', {text: 'content'}, [el('script', {type: 'application/json', 'data-role': 'split-values', text: values})]),
        el('td', {text: 'old'}),
        el('td', {text: 'new'})
    ]);
    const section = el('section', Object.assign({className: 'admin-action-panel'}, LABELS), [
        el('table', {}, [el('tbody', {'data-role': 'scope-group'}, [row])])
    ]);

    if (lang !== null) {
        doc.documentElement = el('html', {lang: lang});
    }
    doc.body.appendChild(section);

    return {section, row};
}

test('a fold counts its lines the way the page\'s language writes numbers', () => {
    const german = longFoldPanel(1237, 'de-DE');
    const blank = longFoldPanel(1237, '');
    const odd = longFoldPanel(1237, 'not a language!');
    const none = longFoldPanel(1237, null);

    [german, blank, odd, none].forEach((panel) => mount({}, panel.section));

    const label = (panel) => panel.row.querySelectorAll('[data-action="fold"]').map((button) => button.textContent);

    assert.deepEqual(label(german), ['Show 1.234 unchanged lines']);
    assert.deepEqual(label(blank), ['Show 1234 unchanged lines'], 'No language: the plain number');
    assert.deepEqual(label(odd), ['Show 1234 unchanged lines'], 'A language Intl refuses: the plain number');
    assert.deepEqual(label(none), ['Show 1234 unchanged lines']);
});
