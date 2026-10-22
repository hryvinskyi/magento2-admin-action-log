<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

/**
 * Where the records of one kind are looked up: by a natural key typed in full, or by part of their name
 */
interface RecordLookupInterface
{
    /**
     * Ids of the records whose natural key (a SKU, an identifier, an email, a number) is exactly the text
     *
     * @param string $text
     * @return list<string>
     */
    public function exact(string $text): array;

    /**
     * Ids of up to $limit records whose name contains the text, wildcards in it taken literally
     *
     * @param string $text
     * @param int $limit
     * @return list<string>
     */
    public function named(string $text, int $limit): array;
}
