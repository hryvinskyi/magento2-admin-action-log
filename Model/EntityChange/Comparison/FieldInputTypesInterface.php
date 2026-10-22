<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison;

/**
 * The input type the admin edits a field of one kind of record with ("boolean", "date", "select")
 */
interface FieldInputTypesInterface
{
    /**
     * The field's input type, or null when the field has none
     *
     * @param string $field
     * @return string|null
     */
    public function inputTypeOf(string $field): ?string;
}
