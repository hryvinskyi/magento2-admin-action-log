<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ResourceModel;

use Hryvinskyi\ActivityLog\Api\Search\SuggestionProviderInterface;
use Hryvinskyi\ActivityLog\Model\Search\Suggestion\RecentWindow;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * What recent entity changes touched, counted: fields, kinds of record, scopes
 *
 * Each list is one grouped query over this module's own rows of the recent window's activities, read from the
 * activity id indexes from the window's first activity on; nothing older is read, and no value is.
 */
class RecentChanges
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param RecentWindow $window
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly RecentWindow $window
    ) {
    }

    /**
     * Fields recent changes changed, by kind of record, each with the number of changes, the most changed first
     *
     * Fields compared and found unchanged do not count; at most SuggestionProviderInterface::MAX_SUGGESTIONS rows.
     *
     * @param list<string> $entityTypes Kinds of record to count; all when empty
     * @return list<array{field: string, entity_type: string, count: int}>
     */
    public function changedFields(array $entityTypes = []): array
    {
        $select = $this->recent(EntityChangeStore::FIELD_TABLE, 'f', ['f.field', 'change.entity_type']);
        if ($select === null) {
            return [];
        }

        $select->join(
            ['change' => $this->resourceConnection->getTableName(EntityChangeStore::TABLE)],
            'change.activity_id = f.activity_id',
            []
        )
            ->where('f.is_changed = ?', 1)
            ->order(['occurrences DESC', 'f.field ASC', 'change.entity_type ASC']);
        if ($entityTypes !== []) {
            $select->where('change.entity_type IN (?)', $entityTypes);
        }

        $rows = [];
        foreach ($this->fetch($select, ['field', 'entity_type']) as [$values, $count]) {
            $rows[] = ['field' => $values['field'], 'entity_type' => $values['entity_type'], 'count' => $count];
        }

        return $rows;
    }

    /**
     * Number of recent changes of each kind of record that had any, keyed by tracked entity code
     *
     * @return array<string, int>
     */
    public function entityTypes(): array
    {
        $select = $this->recent(EntityChangeStore::TABLE, 'change', ['change.entity_type']);
        if ($select === null) {
            return [];
        }

        $counts = [];
        foreach ($this->fetch($select, ['entity_type']) as [$values, $count]) {
            $counts[$values['entity_type']] = $count;
        }

        return $counts;
    }

    /**
     * Scopes recent changes applied in, by the name recorded for them, the most frequent first
     *
     * @return list<array{type: string, label: string, count: int}>
     */
    public function scopes(): array
    {
        $select = $this->recent(EntityChangeStore::FIELD_TABLE, 'f', ['f.scope_type', 'f.scope_label']);
        if ($select === null) {
            return [];
        }

        $select->order(['occurrences DESC', 'f.scope_label ASC']);
        $rows = [];
        foreach ($this->fetch($select, ['scope_type', 'scope_label']) as [$values, $count]) {
            $rows[] = ['type' => $values['scope_type'], 'label' => $values['scope_label'], 'count' => $count];
        }

        return $rows;
    }

    /**
     * A grouped count over a table's rows of the recent activities, or null when there are no recent activities
     *
     * @param string $table
     * @param string $alias
     * @param list<string> $groupBy Columns qualified by their table's alias
     * @return Select|null
     */
    private function recent(string $table, string $alias, array $groupBy): ?Select
    {
        $firstActivityId = $this->window->firstActivityId();
        if ($firstActivityId === null) {
            return null;
        }

        return $this->resourceConnection->getConnection()->select()
            ->from([$alias => $this->resourceConnection->getTableName($table)], [])
            ->columns(array_merge($groupBy, ['occurrences' => 'COUNT(*)']))
            ->where($alias . '.activity_id >= ?', $firstActivityId)
            ->group($groupBy)
            ->limit(SuggestionProviderInterface::MAX_SUGGESTIONS);
    }

    /**
     * Rows of a grouped count as the named columns' values, all non-blank text, and the count; other rows are left out
     *
     * @param Select $select
     * @param list<string> $columns Column names as the rows carry them
     * @return list<array{0: array<string, string>, 1: int}>
     */
    private function fetch(Select $select, array $columns): array
    {
        $rows = [];
        foreach ($this->resourceConnection->getConnection()->fetchAll($select) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($columns as $column) {
                $values[$column] = is_scalar($row[$column] ?? null) ? trim((string) $row[$column]) : '';
            }
            $count = $row['occurrences'] ?? null;
            if (!in_array('', $values, true) && is_numeric($count)) {
                $rows[] = [$values, (int) $count];
            }
        }

        return $rows;
    }
}
