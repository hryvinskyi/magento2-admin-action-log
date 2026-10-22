<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Condition;

use Hryvinskyi\ActivityLog\Api\Search\SearchConditionInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * "entity: cms_block": changes of one kind of entity, by its code or its label ("CMS block"), case aside
 */
class EntityTypeCondition implements SearchConditionInterface
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param TrackedEntityPool $pool
     * @param string $key
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly TrackedEntityPool $pool,
        private readonly string $key = 'entity'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @inheritDoc
     */
    public function isResidual(): bool
    {
        return false;
    }

    /**
     * @inheritDoc
     */
    public function activityIds(string $value): ?Select
    {
        $wanted = mb_strtolower($value);
        $codes = [];
        foreach ($this->pool->all() as $entity) {
            $names = [$entity->getCode(), $entity->getLabel(), (string) __($entity->getLabel())];
            if (in_array($wanted, array_map('mb_strtolower', $names), true)) {
                $codes[] = $entity->getCode();
            }
        }

        if ($codes === []) {
            return null;
        }

        return $this->resourceConnection->getConnection()->select()
            ->from(['recorded' => $this->resourceConnection->getTableName(EntityChangeStore::TABLE)], ['activity_id'])
            ->where('recorded.entity_type IN (?)', $codes);
    }
}
