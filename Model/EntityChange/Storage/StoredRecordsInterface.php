<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Storage;

use Magento\Framework\Model\AbstractModel;

/**
 * Records of one kind as they are stored, each in a model holding at least the fields that name it: what names a
 * record a change was made through another model of (a product named for a change of its stock item), or without a
 * model at all
 */
interface StoredRecordsInterface
{
    /**
     * The record with the id, or null when there is none
     *
     * @param string $id
     * @return AbstractModel|null
     */
    public function record(string $id): ?AbstractModel;

    /**
     * The records with these ids, read together, keyed by id; an id with no record is left out
     *
     * @param list<string> $ids
     * @return array<string, AbstractModel>
     */
    public function records(array $ids): array;
}
