<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison;

/**
 * A Yes/No field that holds no value reads as No, so no value and "0" are the same
 *
 * A form that submits "No" for a Yes/No field never set stores "0" where nothing was stored; nothing changed for
 * anyone reading the record.
 */
class NoValueMeansNo implements ValueEquivalenceInterface
{
    private const NO = '0';

    /**
     * @inheritDoc
     */
    public function same(?string $before, ?string $after): bool
    {
        return $this->isNo($before) && $this->isNo($after);
    }

    /**
     * Whether a value reads as No
     *
     * @param string|null $value
     * @return bool
     */
    private function isNo(?string $value): bool
    {
        return $value === null || $value === '' || $value === self::NO;
    }
}
