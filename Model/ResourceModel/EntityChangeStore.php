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
 * @phpstan-type StoredField array{field: string, before: string|null, after: string|null, changed: bool, masked: bool}
 * @phpstan-type FieldRow array{
 *     scopeType: string,
 *     scopeId: int,
 *     scopeLabel: string,
 *     field: string,
 *     before: string|null,
 *     after: string|null,
 *     changed: bool,
 *     masked: bool
 * }
 * @phpstan-type ChangeDetail array{
 *     entityType: string,
 *     entityId: string|null,
 *     operation: ChangeOperation,
 *     mode: DiffMode,
 *     unchangedCount: int,
 *     fields: list<FieldRow>
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
        $connection = $this->resourceConnection->getConnection();
        $change = $connection->fetchRow(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::TABLE))
                ->where('activity_id = ?', $activityId)
        );
        if (!is_array($change)) {
            return null;
        }

        $fields = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resourceConnection->getTableName(self::FIELD_TABLE))
                ->where('activity_id = ?', $activityId)
                ->order('field_row_id ASC')
        ) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fields[] = [
                'scopeType' => $this->text($row['scope_type'] ?? null),
                'scopeId' => is_numeric($row['scope_id'] ?? null) ? (int) $row['scope_id'] : 0,
                'scopeLabel' => $this->text($row['scope_label'] ?? null),
                'field' => $this->text($row['field'] ?? null),
                'before' => $this->nullableText($row['before_value'] ?? null),
                'after' => $this->nullableText($row['after_value'] ?? null),
                'changed' => (bool) ($row['is_changed'] ?? false),
                'masked' => (bool) ($row['is_masked'] ?? false),
            ];
        }

        return [
            'entityType' => $this->text($change['entity_type'] ?? null),
            'entityId' => $this->nullableText($change['entity_id'] ?? null),
            'operation' => ChangeOperation::tryFrom($this->text($change['operation'] ?? null))
                ?? ChangeOperation::Update,
            'mode' => DiffMode::tryFrom($this->text($change['diff_mode'] ?? null)) ?? DiffMode::Changed,
            'unchangedCount' => is_numeric($change['unchanged_count'] ?? null) ? (int) $change['unchanged_count'] : 0,
            'fields' => $fields,
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
