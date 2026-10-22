<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Records of a flat table that still exist, through the resource model that owns the table, in one query
 */
class FlatRecordPresence implements RecordPresenceInterface
{
    /**
     * @param AbstractDb $resource
     */
    public function __construct(
        private readonly AbstractDb $resource
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
        if (!$connection instanceof AdapterInterface) {
            throw new \LogicException(sprintf('%s has no database connection.', $this->resource::class));
        }

        $idField = $this->resource->getIdFieldName();
        $existing = [];
        foreach ($connection->fetchCol(
            $connection->select()
                ->from($this->resource->getMainTable(), [$idField])
                ->where($connection->quoteIdentifier($idField) . ' IN (?)', $ids)
        ) as $id) {
            if (is_scalar($id)) {
                $existing[] = (string) $id;
            }
        }

        return $existing;
    }
}
