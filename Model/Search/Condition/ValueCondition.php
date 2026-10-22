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
 * "value: shipping": changes with the text, as typed, in a changed field's value before or after
 *
 * Only changed fields count: a full snapshot also records fields that kept their value, and a change that left a value
 * as it was did not set it. Masked values are never searched: what is stored for them is the mask, and a secret's
 * history must not be findable. Values carry no index, so the condition is residual and runs only among the newest
 * candidates.
 */
class ValueCondition implements SearchConditionInterface
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param DbHelper $dbHelper
     * @param string $key
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DbHelper $dbHelper,
        private readonly string $key = 'value'
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
        return true;
    }

    /**
     * @inheritDoc
     */
    public function activityIds(string $value): ?Select
    {
        $connection = $this->resourceConnection->getConnection();
        $like = $this->dbHelper->escapeLikeValue($value, ['position' => 'any']);

        return $connection->select()
            ->from(
                ['valued' => $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE)],
                ['activity_id']
            )
            ->where('valued.is_changed = ?', 1)
            ->where('valued.is_masked = ?', 0)
            ->where(
                $connection->quoteInto('valued.before_value LIKE ?', $like)
                . ' OR ' . $connection->quoteInto('valued.after_value LIKE ?', $like),
                null,
                Select::TYPE_CONDITION
            );
    }
}
