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
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldLabelSourceInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Helper as DbHelper;
use Magento\Framework\DB\Select;

/**
 * "field: price": changes in which a field changed, named by its code, part of its code, or its whole label
 *
 * A value in double quotes names one field code exactly: field: "price" finds changes of price and never of
 * special_price, nor of a field labelled "price".
 *
 * The value is first turned into the field codes it names: the stored codes containing it (one entry per distinct
 * code, read through the field index without visiting the rows; the first STORED_CODES of them in code order) and
 * the codes whose admin label it is. The list is cut after it is read: a limit in the query would turn the index
 * read into a sort. The changes are then found through the same index alone, which carries whether the field
 * changed: a field a full snapshot kept unchanged does not count, so the key answers who changed the field.
 */
class FieldCondition implements SearchConditionInterface
{
    /**
     * Most stored field codes a part of a code stands for
     */
    public const STORED_CODES = 50;

    private const EXACT_PATTERN = '/^"([^"]+)"$/D';

    /**
     * @param ResourceConnection $resourceConnection
     * @param DbHelper $dbHelper
     * @param FieldLabelSourceInterface $labels
     * @param string $key
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DbHelper $dbHelper,
        private readonly FieldLabelSourceInterface $labels,
        private readonly string $key = 'field'
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
        $codes = preg_match(self::EXACT_PATTERN, $value, $exact) === 1
            ? [$exact[1]]
            : array_values(array_unique([
                ...$this->storedCodesContaining($value),
                ...$this->labels->codesLabelled($value),
            ]));
        if ($codes === []) {
            return null;
        }

        return $this->resourceConnection->getConnection()->select()
            ->from(['changed' => $this->table()], ['activity_id'])
            ->where('changed.field IN (?)', $codes)
            ->where('changed.is_changed = ?', 1);
    }

    /**
     * Distinct recorded field codes that contain the value, at most STORED_CODES, in code order
     *
     * @param string $value
     * @return list<string>
     */
    private function storedCodesContaining(string $value): array
    {
        $connection = $this->resourceConnection->getConnection();
        $codes = [];
        foreach ($connection->fetchCol(
            $connection->select()
                ->distinct()
                ->from($this->table(), ['field'])
                ->where(
                    $connection->quoteInto(
                        'field LIKE ?',
                        $this->dbHelper->escapeLikeValue($value, ['position' => 'any'])
                    ),
                    null,
                    Select::TYPE_CONDITION
                )
        ) as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }
        sort($codes);

        return array_slice($codes, 0, self::STORED_CODES);
    }

    /**
     * The field table's name
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE);
    }
}
