<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

/**
 * The ids, as stored with each change, of the records a person means by what they typed
 *
 * Ids are strings: the change table keeps them as text, and comparing that column with numbers would skip its index.
 */
interface RecordResolverInterface
{
    /**
     * Records a person means by text such as a SKU, a name or an identifier; possibly none
     *
     * @param string $text Trimmed, as typed
     * @return list<string>
     */
    public function idsFor(string $text): array;

    /**
     * Records that belong to the record with this id: itself, or the records it owns
     *
     * @param string $id
     * @return list<string>
     */
    public function idsOf(string $id): array;
}
