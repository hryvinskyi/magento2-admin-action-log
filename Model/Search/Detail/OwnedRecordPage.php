<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Detail;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * The page of the record that owns a record without a page of its own: an order comment opens its order
 *
 * The owner is read from the owned records' table, in one query for a page of records, and the owner's page decides
 * whether it can still be opened and with what. A record whose owner is gone, or that is gone itself, opens nothing.
 */
class OwnedRecordPage implements RecordPageInterface
{
    /**
     * @param AbstractDb $resource The owned records' resource model
     * @param string $ownerColumn Column of the owned records holding the owner's id
     * @param RecordPageInterface $ownerPage
     */
    public function __construct(
        private readonly AbstractDb $resource,
        private readonly string $ownerColumn,
        private readonly RecordPageInterface $ownerPage
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return $this->ownerPage->getAclResource();
    }

    /**
     * @inheritDoc
     */
    public function getRoute(): string
    {
        return $this->ownerPage->getRoute();
    }

    /**
     * @inheritDoc
     */
    public function paramsOf(array $ids): array
    {
        $owners = $this->ownersOf($ids);
        $ownerParams = $this->ownerPage->paramsOf(array_values(array_unique($owners)));

        $params = [];
        foreach ($owners as $id => $owner) {
            if (isset($ownerParams[$owner])) {
                $params[$id] = $ownerParams[$owner];
            }
        }

        return $params;
    }

    /**
     * The owner's id of each record that still exists, keyed by the record's id, as text
     *
     * @param list<string> $ids
     * @return array<string, string>
     */
    private function ownersOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \LogicException(sprintf('%s has no database connection.', $this->resource::class));
        }

        $idField = $this->resource->getIdFieldName();
        $owners = [];
        foreach ($connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getMainTable(), [$idField, $this->ownerColumn])
                ->where($connection->quoteIdentifier($idField) . ' IN (?)', $ids)
        ) as $id => $owner) {
            if (is_scalar($owner) && (string) $owner !== '') {
                $owners[(string) $id] = (string) $owner;
            }
        }

        return $owners;
    }
}
