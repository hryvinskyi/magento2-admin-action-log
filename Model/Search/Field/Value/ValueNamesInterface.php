<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Value;

/**
 * How the values a field stores are shown: by the names of the records or options they are ids of (attribute sets,
 * product types, websites, categories), or written as the admin's forms write them (a quantity)
 *
 * The "names" of a field declared in a virtual type of StaticFields.
 *
 * @api
 * @since 1.1.0
 */
interface ValueNamesInterface
{
    /**
     * Names by id, read together
     *
     * An id that names nothing (an option no longer offered, a record deleted), or a value that cannot be written
     * otherwise, is left out.
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    public function namesOf(array $ids): array;
}
