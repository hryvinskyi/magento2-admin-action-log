<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ResourceModel;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Magento\Framework\App\ResourceConnection;

/**
 * The entity change detail of an activity: one row for the change, one per field compared
 *
 * A changed field is marked automatic when it was set without the admin's form asking for it; such fields come after
 * the fields that were asked for wherever the rows of a change are listed.
 *
 * @phpstan-type StoredField array{
 *     field: string,
 *     before: string|null,
 *     after: string|null,
 *     changed: bool,
 *     masked: bool,
 *     automatic: bool
 * }
 * @phpstan-type FieldRow array{
 *     scopeType: string,
 *     scopeId: int,
 *     scopeLabel: string,
 *     field: string,
 *     before: string|null,
 *     after: string|null,
 *     changed: bool,
 *     masked: bool,
 *     automatic: bool
 * }
 * @phpstan-type ChangeSummary array{
 *     entityType: string,
 *     entityId: string|null,
 *     operation: ChangeOperation,
 *     mode: DiffMode,
 *     unchangedCount: int
 * }
 * @phpstan-type ChangeDetail array{
 *     entityType: string,
 *     entityId: string|null,
 *     operation: ChangeOperation,
 *     mode: DiffMode,
 *     unchangedCount: int,
 *     fields: list<FieldRow>
 * }
 * @phpstan-type FieldPage array{
 *     rows: list<FieldRow>,
 *     changed: int,
 *     automatic: int,
 *     unchanged: int,
 *     changedFields: list<string>
 * }
 */
class EntityChangeStore
{
    public const TABLE = 'hryvinskyi_admin_action_log_entity';
    public const FIELD_TABLE = 'hryvinskyi_admin_action_log_entity_field';

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Store the change and its fields
     *
     * @param int $activityId
     * @param string $entityType
     * @param string|null $entityId
     * @param ChangeOperation $operation
     * @param DiffMode $mode
     * @param int $unchangedCount
     * @param EntityScopeInterface $scope
     * @param list<StoredField> $fields
     * @return void
     */
    public function save(
        int $activityId,
        string $entityType,
        ?string $entityId,
        ChangeOperation $operation,
        DiffMode $mode,
        int $unchangedCount,
        EntityScopeInterface $scope,
        array $fields
    ): void {
        $connection = $this->resourceConnection->getConnection();
        $connection->insert($this->resourceConnection->getTableName(self::TABLE), [
            'activity_id' => $activityId,
            'entity_type' => $entityType,
            'entity_id' => $entityId === null ? null : mb_substr($entityId, 0, 64),
            'operation' => $operation->value,
            'diff_mode' => $mode->value,
            'unchanged_count' => $unchangedCount,
        ]);

        if ($fields === []) {
            return;
        }

        $rows = [];
        foreach ($fields as $field) {
            $rows[] = [
                'activity_id' => $activityId,
                'scope_type' => $scope->getType(),
                'scope_id' => $scope->getId(),
                'scope_label' => $scope->getLabel(),
                'field' => mb_substr($field['field'], 0, 255),
                'before_value' => $field['before'],
                'after_value' => $field['after'],
                'is_changed' => (int) $field['changed'],
                'is_masked' => (int) $field['masked'],
                'is_automatic' => (int) $field['automatic'],
            ];
        }

        $connection->insertMultiple($this->resourceConnection->getTableName(self::FIELD_TABLE), $rows);
    }

    /**
     * The change of an activity with its fields, or null
     *
     * @param int $activityId
     * @return ChangeDetail|null
     */
    public function find(int $activityId): ?array
    {
        $summary = $this->changesOf([$activityId])[$activityId] ?? null;
        if ($summary === null) {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $fields = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::FIELD_TABLE))
                ->where('activity_id = ?', $activityId)
                ->order(['is_automatic ASC', 'field_row_id ASC'])
        ) as $row) {
            if (is_array($row)) {
                $fields[] = $this->fieldRow($row);
            }
        }

        return $summary + ['fields' => $fields];
    }

    /**
     * The changes of several activities without their fields, keyed by activity id; an id without one is left out
     *
     * @param list<int> $activityIds
     * @return array<int, ChangeSummary>
     */
    public function changesOf(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $changes = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE))
                ->where('activity_id IN (?)', $activityIds)
        ) as $change) {
            if (is_array($change) && is_numeric($change['activity_id'] ?? null)) {
                $changes[(int) $change['activity_id']] = $this->summary($change);
            }
        }

        return $changes;
    }

    /**
     * Up to $rowLimit field rows of each activity, with its row counts and changed field codes, by activity id
     *
     * The changed rows come first, those the admin asked for before those set automatically, then those that kept
     * their value, each in the order they were recorded. The values of rows beyond the limit are never read: a
     * snapshot of a large record stores hundreds of them. The codes of the changed fields are all there, beyond the
     * limit too, and so is the number of them that were set automatically.
     *
     * @param list<int> $activityIds
     * @param int $rowLimit Rows per activity, 1 or more
     * @return array<int, FieldPage>
     */
    public function fieldsOf(array $activityIds, int $rowLimit): array
    {
        if ($activityIds === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::FIELD_TABLE);
        $pages = [];
        $wanted = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($table, ['field_row_id', 'activity_id', 'is_changed', 'is_automatic', 'field'])
                ->where('activity_id IN (?)', $activityIds)
                ->order(['activity_id ASC', 'is_changed DESC', 'is_automatic ASC', 'field_row_id ASC'])
        ) as $row) {
            if (!is_array($row)
                || !is_numeric($row['activity_id'] ?? null)
                || !is_numeric($row['field_row_id'] ?? null)
            ) {
                continue;
            }

            $activityId = (int) $row['activity_id'];
            $changed = (bool) ($row['is_changed'] ?? false);
            $pages[$activityId] ??= [
                'rows' => [],
                'changed' => 0,
                'automatic' => 0,
                'unchanged' => 0,
                'changedFields' => [],
            ];
            $pages[$activityId][$changed ? 'changed' : 'unchanged']++;
            if ($changed) {
                $pages[$activityId]['changedFields'][] = $this->text($row['field'] ?? null);
                $pages[$activityId]['automatic'] += (bool) ($row['is_automatic'] ?? false) ? 1 : 0;
            }
            if ($pages[$activityId]['changed'] + $pages[$activityId]['unchanged'] <= $rowLimit) {
                $wanted[] = (int) $row['field_row_id'];
            }
        }

        if ($wanted === []) {
            return $pages;
        }

        foreach ($connection->fetchAll(
            $connection->select()
                ->from($table)
                ->where('field_row_id IN (?)', $wanted)
                ->order(['activity_id ASC', 'is_changed DESC', 'is_automatic ASC', 'field_row_id ASC'])
        ) as $row) {
            if (is_array($row) && is_numeric($row['activity_id'] ?? null) && isset($pages[(int) $row['activity_id']])) {
                $pages[(int) $row['activity_id']]['rows'][] = $this->fieldRow($row);
            }
        }

        return $pages;
    }

    /**
     * A change row without its fields
     *
     * @param array<mixed> $change
     * @return ChangeSummary
     */
    private function summary(array $change): array
    {
        return [
            'entityType' => $this->text($change['entity_type'] ?? null),
            'entityId' => $this->nullableText($change['entity_id'] ?? null),
            'operation' => ChangeOperation::tryFrom($this->text($change['operation'] ?? null))
                ?? ChangeOperation::Update,
            'mode' => DiffMode::tryFrom($this->text($change['diff_mode'] ?? null)) ?? DiffMode::Changed,
            'unchangedCount' => is_numeric($change['unchanged_count'] ?? null) ? (int) $change['unchanged_count'] : 0,
        ];
    }

    /**
     * A field row as stored
     *
     * @param array<mixed> $row
     * @return FieldRow
     */
    private function fieldRow(array $row): array
    {
        return [
            'scopeType' => $this->text($row['scope_type'] ?? null),
            'scopeId' => is_numeric($row['scope_id'] ?? null) ? (int) $row['scope_id'] : 0,
            'scopeLabel' => $this->text($row['scope_label'] ?? null),
            'field' => $this->text($row['field'] ?? null),
            'before' => $this->nullableText($row['before_value'] ?? null),
            'after' => $this->nullableText($row['after_value'] ?? null),
            'changed' => (bool) ($row['is_changed'] ?? false),
            'masked' => (bool) ($row['is_masked'] ?? false),
            'automatic' => (bool) ($row['is_automatic'] ?? false),
        ];
    }

    /**
     * A column value as text
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A column value as text, or null
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullableText(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
