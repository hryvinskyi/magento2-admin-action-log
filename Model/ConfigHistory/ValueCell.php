<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ConfigHistory;

/**
 * The value cell of a configuration row: the row's own `td` whose class is `value`
 *
 * A row's value cell can hold whole tables of its own (a table of rows, a grid), and the scripts printed inside it
 * can hold cell markup in JavaScript strings, so the cell's end is found by pairing the row's cell tags rather than by
 * the first or last closing tag: tags inside scripts, styles, templates, text areas and comments are not markup and
 * are skipped. A row whose own cells cannot be paired, or that has no value cell, has no end to append to.
 */
class ValueCell
{
    /**
     * A cell's opening or closing tag, or a span whose content is not markup
     */
    private const TOKENS = '~<!--.*?-->|<(script|style|template|textarea)\b.*?</\1\s*>|<td\b[^>]*>|</td\s*>~is';

    /**
     * The class token that marks the value cell
     */
    private const VALUE_CLASS = '~(?<![\w-])class\s*=\s*(["\'])(?:[^"\']*\s)?value(?:\s[^"\']*)?\1~i';

    /**
     * The row with the markup appended at the end of its value cell, or null when the row has no value cell
     *
     * @param string $rowHtml
     * @param string $html
     * @return string|null
     */
    public function append(string $rowHtml, string $html): ?string
    {
        $end = $this->end($rowHtml);

        return $end === null ? null : substr($rowHtml, 0, $end) . $html . substr($rowHtml, $end);
    }

    /**
     * Where the closing tag of the row's value cell starts, or null when the row has no value cell it can pair
     *
     * @param string $rowHtml
     * @return int|null
     */
    private function end(string $rowHtml): ?int
    {
        if (preg_match_all(self::TOKENS, $rowHtml, $tokens, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $depth = 0;
        $inValueCell = false;
        foreach ($tokens[0] as [$tag, $offset]) {
            if (stripos($tag, '</td') === 0) {
                if (--$depth === 0 && $inValueCell) {
                    return $offset;
                }
                if ($depth < 0) {
                    return null;
                }
                continue;
            }
            if (stripos($tag, '<td') !== 0) {
                continue;
            }
            if ($depth === 0) {
                $inValueCell = preg_match(self::VALUE_CLASS, $tag) === 1;
            }
            $depth++;
        }

        return null;
    }
}
