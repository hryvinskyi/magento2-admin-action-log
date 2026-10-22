<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Format;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * A decimal attribute's value with its trailing zeros dropped down to two decimals, as the admin's forms write it
 * ("34.000000" → "34.00", "0.125000" → "0.125"); text that is not a plain decimal number is left as stored
 */
class DecimalFormat implements AttributeValueFormatInterface
{
    private const DECIMAL = 'decimal';
    private const DECIMAL_PATTERN = '/^(-?\d+)(?:\.(\d*))?$/D';

    /**
     * @inheritDoc
     */
    public function supports(AbstractAttribute $attribute): bool
    {
        return $attribute->getBackendType() === self::DECIMAL;
    }

    /**
     * @inheritDoc
     */
    public function format(AbstractAttribute $attribute, array $values): array
    {
        $shown = [];
        foreach ($values as $value) {
            if (preg_match(self::DECIMAL_PATTERN, $value, $parts) === 1) {
                $shown[$value] = $parts[1] . '.' . str_pad(rtrim($parts[2] ?? '', '0'), 2, '0');
            }
        }

        return $shown;
    }
}
