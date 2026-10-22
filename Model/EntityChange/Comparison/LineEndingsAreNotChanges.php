<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison;

/**
 * Two texts that differ only in how their lines end ("\n", "\r\n" or "\r") are the same text
 *
 * A browser submits a text area's line breaks as "\r\n", so saving a form without touching a text that was stored
 * with "\n" writes it back with other line endings; nobody changed a word of it.
 */
class LineEndingsAreNotChanges implements ValueEquivalenceInterface
{
    private const LINE_ENDINGS = ["\r\n", "\r"];
    private const LINE_ENDING = "\n";

    /**
     * @inheritDoc
     */
    public function same(?string $before, ?string $after): bool
    {
        if ($before === null || $after === null) {
            return false;
        }

        return $this->lines($before) === $this->lines($after);
    }

    /**
     * The text with every line ending written the same way
     *
     * @param string $text
     * @return string
     */
    private function lines(string $text): string
    {
        return str_replace(self::LINE_ENDINGS, self::LINE_ENDING, $text);
    }
}
