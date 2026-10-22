<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * One record's newest changes in this module's own tables: their activity ids, the fields they changed — the
 * choices of the record's activity panel — and the values of chosen changed fields, for the hints under the fields
 *
 * Only the record's newest CHANGES changes are read, found through the change table's (kind, id) index, and the
 * field list stops at LIMIT entries, so a record changed thousands of times costs no more than one changed a few
 * hundred times. Ids are compared as text, as the change table keeps them. Values are read only for the field rows
 * asked for by change and code. The hints say who changed a field: a field set automatically, which nobody asked
 * for, gives no hint, while the panel's choices still offer it.
 *
 * @phpstan-type ChangedValue array{activityId: int, field: string, before: string|null, after: string|null,
 *     masked: bool}
 */
class RecordHistory
{
    /**
     * Newest changes of the record looked at
     */
    public const CHANGES = 500;

    /**
     * Most entries of a list
     */
    public const LIMIT = 200;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Codes of the fields the record's changes changed, in code order
     *
     * @param string $entityType Tracked entity code
     * @param string $entityId
     * @return list<string>
     */
    public function changedFields(string $entityType, string $entityId): array
    {
        $select = $this->resourceConnection->getConnection()->select()
            ->distinct()
            ->from(['recent' => $this->recent($entityType, $entityId)], [])
            ->join(
                ['f' => $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE)],
                'f.activity_id = recent.activity_id',
                ['field']
            )
            ->where('f.is_changed = ?', 1)
            ->order('f.field ASC')
            ->limit(self::LIMIT);

        return $this->texts($select);
    }

    /**
     * Activity ids of the record's newest changes, the newest first
     *
     * @param string $entityType Tracked entity code
     * @param string $entityId
     * @return list<int>
     */
    public function recentActivityIds(string $entityType, string $entityId): array
    {
        $ids = [];
        foreach ($this->resourceConnection->getConnection()->fetchCol($this->recent($entityType, $entityId)) as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Codes of the fields each of these changes changed as asked, by activity id
     *
     * Each code once per change; fields set automatically are left out.
     *
     * @param list<int> $activityIds
     * @return array<int, list<string>>
     */
    public function changedFieldsOf(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE);
        $fields = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->distinct()
                ->from($table, ['activity_id', 'field'])
                ->where('activity_id IN (?)', $activityIds)
                ->where('is_changed = ?', 1)
                ->where('is_automatic = ?', 0)
        ) as $row) {
            if (is_array($row) && is_numeric($row['activity_id'] ?? null) && is_string($row['field'] ?? null)) {
                $fields[(int) $row['activity_id']][] = $row['field'];
            }
        }

        return $fields;
    }

    /**
     * The changed row of each field in the change named for it, with its values
     *
     * The first such row of a change only, and never a field set automatically.
     *
     * @param array<string,int> $activityByField Activity id of the change wanted, by field code
     * @return list<ChangedValue>
     */
    public function changedValues(array $activityByField): array
    {
        if ($activityByField === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $values = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from(
                    $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE),
                    ['activity_id', 'field', 'before_value', 'after_value', 'is_masked']
                )
                ->where('activity_id IN (?)', array_values(array_unique($activityByField)))
                ->where('field IN (?)', array_map('strval', array_keys($activityByField)))
                ->where('is_changed = ?', 1)
                ->where('is_automatic = ?', 0)
                ->order('field_row_id ASC')
        ) as $row) {
            $value = is_array($row) ? $this->changedValue($row) : null;
            if ($value !== null
                && ($activityByField[$value['field']] ?? null) === $value['activityId']
                && !isset($values[$value['field']])
            ) {
                $values[$value['field']] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * Activity ids of the record's newest CHANGES changes
     *
     * @param string $entityType
     * @param string $entityId
     * @return Select
     */
    private function recent(string $entityType, string $entityId): Select
    {
        return $this->resourceConnection->getConnection()->select()
            ->from(['change' => $this->resourceConnection->getTableName(EntityChangeStore::TABLE)], ['activity_id'])
            ->where('change.entity_type = ?', $entityType)
            ->where('change.entity_id = ?', $entityId)
            ->order('change.activity_id DESC')
            ->limit(self::CHANGES);
    }

    /**
     * A changed row with its values, or null for a row that does not hold them
     *
     * @param array<mixed> $row
     * @return ChangedValue|null
     */
    private function changedValue(array $row): ?array
    {
        $activityId = $row['activity_id'] ?? null;
        $field = $row['field'] ?? null;
        if (!is_numeric($activityId) || !is_string($field)) {
            return null;
        }

        $before = $row['before_value'] ?? null;
        $after = $row['after_value'] ?? null;

        return [
            'activityId' => (int) $activityId,
            'field' => $field,
            'before' => is_scalar($before) ? (string) $before : null,
            'after' => is_scalar($after) ? (string) $after : null,
            'masked' => (bool) ($row['is_masked'] ?? false),
        ];
    }

    /**
     * The first column of the rows, non-blank text only
     *
     * @param Select $select
     * @return list<string>
     */
    private function texts(Select $select): array
    {
        $texts = [];
        foreach ($this->resourceConnection->getConnection()->fetchCol($select) as $value) {
            if (is_scalar($value) && trim((string) $value) !== '') {
                $texts[] = (string) $value;
            }
        }

        return $texts;
    }
}
