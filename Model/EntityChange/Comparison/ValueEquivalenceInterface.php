<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison;

/**
 * When two stored values of one kind of field mean the same, beyond being the same text
 *
 * Registered in FieldEquivalences under the input type of the fields it applies to, or among the rules of every
 * field.
 */
interface ValueEquivalenceInterface
{
    /**
     * Whether the two values mean the same
     *
     * @param string|null $before
     * @param string|null $after
     * @return bool
     */
    public function same(?string $before, ?string $after): bool;
}
