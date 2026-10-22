<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Condition;

use Hryvinskyi\ActivityLog\Api\Search\SearchConditionInterface;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Helper as DbHelper;
use Magento\Framework\DB\Select;

/**
 * "config: catalog/frontend": changes of a setting by its path, or of every setting under a group's path
 *
 * The path matches whole segments only: "a/b/active" finds that setting and never "a/b/active_x", while "a/b" finds
 * every setting of group "a/b". Only changes of the configured setting entity are searched, so a field of another
 * kind that happens to share the name is not found. The field index serves both forms.
 */
class ConfigPathCondition implements SearchConditionInterface
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param DbHelper $dbHelper
     * @param string $key
     * @param string $entityType Tracked entity code of a setting's value
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DbHelper $dbHelper,
        private readonly string $key = 'config',
        private readonly string $entityType = 'config_value'
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
        $path = trim($value, " \t/");
        if ($path === '') {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();

        return $connection->select()
            ->from(
                ['setting' => $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE)],
                ['activity_id']
            )
            ->join(
                ['recorded' => $this->resourceConnection->getTableName(EntityChangeStore::TABLE)],
                'recorded.activity_id = setting.activity_id',
                []
            )
            ->where('recorded.entity_type = ?', $this->entityType)
            ->where(
                $connection->quoteInto('setting.field = ?', $path) . ' OR '
                . $connection->quoteInto('setting.field LIKE ?', $this->dbHelper->escapeLikeValue($path) . '/%'),
                null,
                Select::TYPE_CONDITION
            );
    }
}
