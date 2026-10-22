/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

/**
 * Where the settings' history popover goes: a small gap below the whole setting row, as wide as the row's value column
 * allows and never wider than the maximum, so it never runs into the "Use system value" column or off a narrow screen.
 * It starts where the value column starts; when the button that opened it sits further right than that leaves room
 * for (a control as wide as the column), it moves right until it ends at the button. Its arrow points at the button.
 *
 * Positions are given in the coordinates of the element the popover is positioned against (its offset parent), from
 * boxes measured in the viewport. Pure: the boxes are passed in.
 */
define([], function () {
    'use strict';

    var GAP = 7,
        ARROW = 12,
        ARROW_MARGIN = 12;

    /**
     * @param {number} value
     * @param {number} min
     * @param {number} max
     * @returns {number}
     */
    function clamp(value, min, max) {
        return Math.max(min, Math.min(max, value));
    }

    /**
     * @param {{bottom: number, right: number}} row The setting row's box
     * @param {{left: number}} column The row's value column's box
     * @param {{left: number, width: number}} button The history button's box
     * @param {{left: number, top: number, scrollLeft: number, scrollTop: number, clientLeft: number,
     *     clientTop: number}} parent The offset parent's box, scroll offsets and border widths
     * @param {number} maxWidth
     * @returns {{top: number, left: number, width: number, arrowLeft: number}}
     */
    function place(row, column, button, parent, maxWidth) {
        var width = Math.max(0, Math.min(maxWidth, row.right - column.left)),
            left = clamp(button.left + button.width - width, column.left, Math.max(column.left, row.right - width)),
            arrowLeft = clamp(
                button.left + button.width / 2 - left - ARROW / 2,
                ARROW_MARGIN,
                Math.max(ARROW_MARGIN, width - ARROW - ARROW_MARGIN)
            );

        return {
            top: Math.round(row.bottom + GAP - parent.top + parent.scrollTop - parent.clientTop),
            left: Math.round(left - parent.left + parent.scrollLeft - parent.clientLeft),
            width: Math.round(width),
            arrowLeft: Math.round(arrowLeft)
        };
    }

    return Object.freeze({place: place});
});
