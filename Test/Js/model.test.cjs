/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const {load} = require('./amd.cjs');

test('field filter: a field matches text anywhere in its name, whatever the case', () => {
    const filter = load('model/field-filter');

    assert.equal(filter.matches('special_price', 'PRICE'), true);
    assert.equal(filter.matches('web/secure/base_url', ' secure/ '), true);
    assert.equal(filter.matches('description', 'price'), false);
    assert.equal(filter.matches('anything', ''), true);
});

test('field filter: unchanged rows show only when asked for, and still have to match', () => {
    const filter = load('model/field-filter');
    const unchanged = {field: 'meta_title', changed: false};

    assert.equal(filter.isVisible({field: 'price', changed: true}, '', false), true);
    assert.equal(filter.isVisible(unchanged, '', false), false);
    assert.equal(filter.isVisible(unchanged, 'meta', true), true);
    assert.equal(filter.isVisible(unchanged, 'price', true), false);
});
