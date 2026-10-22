<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\Data\Search\ValueKind;

/**
 * How the two values of a changed field are shown, wherever a change is shown
 *
 * - masked: the values are secrets and are never shown;
 * - json: each side is a JSON list (a missing or empty side counts as an empty one), shown as items added,
 *   removed and kept;
 * - long: a side is over 160 characters or holds three line breaks, shown clipped until expanded;
 * - short: anything else, shown inline.
 *
 * The activity detail page clips a value by the same length rule, so the two views never disagree on what is long.
 */
class ValueKinds
{
    private const LONG_LENGTH = 160;
    private const LONG_LINE_BREAKS = 3;

    /**
     * The kind of a field's values
     *
     * @param string|null $before
     * @param string|null $after
     * @param bool $masked
     * @return ValueKind
     */
    public function kindOf(?string $before, ?string $after, bool $masked): ValueKind
    {
        if ($masked) {
            return ValueKind::Masked;
        }

        if ($this->areJsonLists($before, $after)) {
            return ValueKind::Json;
        }

        return $this->isLong($before, $after) ? ValueKind::Long : ValueKind::Short;
    }

    /**
     * Whether either value is long enough to be clipped until expanded
     *
     * @param string|null $before
     * @param string|null $after
     * @return bool
     */
    public function isLong(?string $before, ?string $after): bool
    {
        return $this->isLongValue($before) || $this->isLongValue($after);
    }

    /**
     * Whether one value is long
     *
     * @param string|null $value
     * @return bool
     */
    private function isLongValue(?string $value): bool
    {
        return $value !== null
            && (mb_strlen($value) > self::LONG_LENGTH || substr_count($value, "\n") >= self::LONG_LINE_BREAKS);
    }

    /**
     * Whether both sides are JSON lists and at least one of them is written out
     *
     * @param string|null $before
     * @param string|null $after
     * @return bool
     */
    private function areJsonLists(?string $before, ?string $after): bool
    {
        if (($before === null || $before === '') && ($after === null || $after === '')) {
            return false;
        }

        return $this->isJsonList($before) && $this->isJsonList($after);
    }

    /**
     * Whether a value is a JSON list; nothing at all counts as an empty one
     *
     * @param string|null $value
     * @return bool
     */
    private function isJsonList(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        if (!str_starts_with(ltrim($value), '[')) {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && array_is_list($decoded);
    }
}
