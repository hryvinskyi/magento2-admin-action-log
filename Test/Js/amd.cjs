/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

'use strict';

/**
 * Loads one of the module's AMD files in Node. Its dependencies are other module files, or files of the activity log
 * module this module declares, loaded the same way; a pure module needs nothing else, which is what these tests
 * prove. The activity log's files are taken from wherever it is installed: beside this module (both in app/code or
 * both installed by Composer), or from the project's vendor directory.
 */
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const MODULE_ROOT = path.resolve(__dirname, '../..');
const SOURCES = [
    {
        prefix: 'Hryvinskyi_AdminActionLog/js/',
        roots: [path.join(MODULE_ROOT, 'view/adminhtml/web/js')]
    },
    {
        prefix: 'Hryvinskyi_ActivityLog/js/',
        roots: [
            path.resolve(MODULE_ROOT, '../ActivityLog/view/adminhtml/web/js'),
            path.resolve(MODULE_ROOT, '../magento2-activity-log/view/adminhtml/web/js'),
            path.resolve(MODULE_ROOT, '../../../../vendor/hryvinskyi/magento2-activity-log/view/adminhtml/web/js')
        ]
    }
];
const cache = new Map();

/**
 * @param {string} id e.g. "Hryvinskyi_AdminActionLog/js/model/field-filter", "model/field-filter" or
 *     "Hryvinskyi_ActivityLog/js/search/range"
 * @returns {string} The file
 */
function fileOf(id) {
    const source = SOURCES.find((candidate) => id.startsWith(candidate.prefix)) || SOURCES[0];
    const relative = id.startsWith(source.prefix) ? id.slice(source.prefix.length) : id;
    const root = source.roots.find((candidate) => fs.existsSync(candidate));

    if (root === undefined) {
        throw new Error(`${source.prefix} is not installed beside this module`);
    }

    return path.join(root, relative + '.js');
}

/**
 * @param {string} id
 * @returns {*} What the module's factory returns
 */
function load(id) {
    const file = fileOf(id);

    if (cache.has(file)) {
        return cache.get(file);
    }

    let exported;
    const sandbox = {
        define: (deps, factory) => {
            exported = factory(...deps.map((dep) => {
                if (!SOURCES.some((source) => dep.startsWith(source.prefix))) {
                    throw new Error(`${id} depends on ${dep}, which a pure module must not`);
                }

                return load(dep);
            }));
        },
        URLSearchParams,
        AbortController,
        Object,
        Array,
        Number,
        String,
        Error,
        RangeError,
        TypeError,
        isFinite,
        Infinity,
        console
    };

    vm.runInNewContext(fs.readFileSync(file, 'utf8'), sandbox, {filename: file});
    cache.set(file, exported);

    return exported;
}

/**
 * A test helper of the activity log module (its DOM fake), from wherever that module is installed
 *
 * @param {string} name e.g. "dom-fake.cjs"
 * @returns {*} What the helper exports
 */
function activityLogTestHelper(name) {
    const root = SOURCES[1].roots.find((candidate) => fs.existsSync(candidate));

    if (root === undefined) {
        throw new Error(`${SOURCES[1].prefix} is not installed beside this module`);
    }

    return require(path.resolve(root, '../../../../Test/Js', name));
}

module.exports = {load, activityLogTestHelper};
