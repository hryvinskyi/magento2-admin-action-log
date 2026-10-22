/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * "Open the record's activity", the event a part of an edit page dispatches on the document to open the record's
 * activity panel — a field's "History" asks for that field alone.
 *
 * Public surface: NAME; detail(field) builds the event's detail; read(detail) returns {field} or null for a detail
 * of another shape. Pure.
 */
define([], function () {
    'use strict';

    var NAME = 'hryvinskyi:record-activity:open',
        FIELD_CODE = /^[A-Za-z0-9_]{1,255}$/;

    /**
     * @param {?string} field The field to show alone, or null for every field
     * @returns {{field: ?string}}
     */
    function detail(field) {
        return Object.freeze({field: typeof field === 'string' && FIELD_CODE.test(field) ? field : null});
    }

    /**
     * @param {*} raw An event's detail
     * @returns {?{field: ?string}}
     */
    function read(raw) {
        if (raw === null || typeof raw !== 'object') {
            return null;
        }
        if (raw.field !== null && (typeof raw.field !== 'string' || !FIELD_CODE.test(raw.field))) {
            return null;
        }

        return Object.freeze({field: raw.field});
    }

    return Object.freeze({
        NAME: NAME,
        detail: detail,
        read: read
    });
});
