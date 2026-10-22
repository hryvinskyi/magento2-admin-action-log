<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

/**
 * The natural keys records of one kind are known by today (a SKU), read together for a list of ids
 */
interface RecordKeysInterface
{
    /**
     * Current natural key of each record that still exists, keyed by id
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    public function keysOf(array $ids): array;
}
