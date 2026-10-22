<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Recorded changes of settings, counted by config path, read from this module's own change tables
 *
 * A setting's change is one field row named by the setting's path, whatever the scope. The counts of a set of paths
 * come from one grouped query over the field index (field, activity_id), joined to the change rows by their primary
 * key to keep only changes of settings; no value is read. Activity ids grow as activities are written, so the
 * highest id of a path is its newest change and the lowest id of any change is the oldest change the log still keeps.
 */
class ConfigChanges
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param string $entityType Tracked entity code of a setting's value
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly string $entityType = 'config_value'
    ) {
    }

    /**
     * Number of recorded changes of each path that has any, in every scope, with the activity id of the newest
     *
     * @param list<string> $paths
     * @return array<string, array{changes: int, latestActivityId: int}> Keyed by path; paths never changed are absent
     */
    public function byPath(array $paths): array
    {
        $paths = array_values(array_unique(array_filter($paths, static fn (string $path): bool => $path !== '')));
        if ($paths === []) {
            return [];
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['setting' => $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE)],
                ['path' => 'setting.field', 'changes' => 'COUNT(*)', 'latest' => 'MAX(setting.activity_id)']
            )
            ->join(
                ['recorded' => $this->resourceConnection->getTableName(EntityChangeStore::TABLE)],
                'recorded.activity_id = setting.activity_id',
                []
            )
            ->where('setting.field IN (?)', $paths)
            ->where('setting.is_changed = ?', 1)
            ->where('recorded.entity_type = ?', $this->entityType)
            ->group('setting.field');

        $counts = [];
        foreach ($connection->fetchAll($select) as $row) {
            if (is_array($row)
                && is_string($row['path'] ?? null)
                && is_numeric($row['changes'] ?? null)
                && is_numeric($row['latest'] ?? null)
            ) {
                $counts[$row['path']] = [
                    'changes' => (int) $row['changes'],
                    'latestActivityId' => (int) $row['latest'],
                ];
            }
        }

        return $counts;
    }

    /**
     * Activity id of the oldest change the log keeps, of any kind of record, or null when it keeps none
     *
     * @return int|null
     */
    public function oldestActivityId(): ?int
    {
        $connection = $this->resourceConnection->getConnection();
        $id = $connection->fetchOne(
            $connection->select()->from(
                $this->resourceConnection->getTableName(EntityChangeStore::TABLE),
                ['MIN(activity_id)']
            )
        );

        return is_numeric($id) ? (int) $id : null;
    }
}
