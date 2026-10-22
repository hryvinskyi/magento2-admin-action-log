<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

use Magento\Eav\Model\Entity\AbstractEntity;

/**
 * Records of an EAV entity that still exist, through the entity's resource model, in one query on its entity table
 */
class EavRecordPresence implements RecordPresenceInterface
{
    /**
     * @param AbstractEntity $resource
     */
    public function __construct(
        private readonly AbstractEntity $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $idField = (string) $this->resource->getEntityIdField();
        $existing = [];
        foreach ($connection->fetchCol(
            $connection->select()
                ->from($this->resource->getEntityTable(), [$idField])
                ->where($connection->quoteIdentifier($idField) . ' IN (?)', $ids)
        ) as $id) {
            if (is_scalar($id)) {
                $existing[] = (string) $id;
            }
        }

        return $existing;
    }
}
