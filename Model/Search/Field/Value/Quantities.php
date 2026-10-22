<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Value;

/**
 * Quantities as the admin's stock forms write them: a stored decimal without its trailing zeros ("100.0000" → "100",
 * "4.5000" → "4.5"); text that is not a plain decimal number is left as stored
 *
 * @api
 * @since 1.1.0
 */
class Quantities implements ValueNamesInterface
{
    private const DECIMAL_PATTERN = '/^(-?\d+)(?:\.(\d*))?$/D';

    /**
     * @inheritDoc
     */
    public function namesOf(array $ids): array
    {
        $shown = [];
        foreach ($ids as $value) {
            if (preg_match(self::DECIMAL_PATTERN, $value, $parts) !== 1) {
                continue;
            }

            $fraction = rtrim($parts[2] ?? '', '0');
            $shown[$value] = $fraction === '' ? $parts[1] : $parts[1] . '.' . $fraction;
        }

        return $shown;
    }
}
