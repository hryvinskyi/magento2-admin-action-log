<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

/**
 * Turns field values into the text a change stores
 *
 * Scalars become their text, booleans "1"/"0", lists and maps of scalars JSON. Objects (a loaded stock item, a
 * collection) are not field values: they are not comparable and are left out of the change.
 */
class ValueNormalizer
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Whether the value can be stored and compared
     *
     * @param mixed $value
     * @return bool
     */
    public function isComparable(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        if (!is_array($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!$this->isComparable($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The value as text, or null for no value; call isComparable() first
     *
     * @param mixed $value
     * @return string|null
     */
    public function normalize(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => $this->json($value),
        };
    }

    /**
     * A list or map as JSON
     *
     * @param mixed $value
     * @return string|null
     */
    private function json(mixed $value): ?string
    {
        $json = json_encode($value, self::JSON_FLAGS);

        return $json === false ? null : $json;
    }
}
