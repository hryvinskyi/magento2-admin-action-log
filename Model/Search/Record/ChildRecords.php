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
 * Records owned by other records, found through their owner: the comments of the orders a person names
 *
 * Only records that still exist are found: the owner link is read from the owned records' table.
 */
class ChildRecords implements RecordResolverInterface
{
    /**
     * @param RecordResolverInterface $parents
     * @param AbstractDb $resource The owned records' resource model
     * @param string $parentColumn Column of the owned records holding the owner's id
     */
    public function __construct(
        private readonly RecordResolverInterface $parents,
        private readonly AbstractDb $resource,
        private readonly string $parentColumn
    ) {
    }

    /**
     * @inheritDoc
     */
    public function idsFor(string $text): array
    {
        return $this->ownedBy($this->parents->idsFor($text));
    }

    /**
     * @inheritDoc
     */
    public function idsOf(string $id): array
    {
        return $this->ownedBy($this->parents->idsOf($id));
    }

    /**
     * Ids of the records the owners own, as text
     *
     * @param list<string> $ownerIds
     * @return list<string>
     */
    private function ownedBy(array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \LogicException(sprintf('%s has no database connection.', $this->resource::class));
        }

        $ids = [];
        foreach ($connection->fetchCol(
            $connection->select()
                ->from($this->resource->getMainTable(), [$this->resource->getIdFieldName()])
                ->where($connection->quoteIdentifier($this->parentColumn) . ' IN (?)', $ownerIds)
        ) as $id) {
            if (is_scalar($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
