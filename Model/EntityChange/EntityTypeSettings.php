<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Model\Config;

/**
 * How each kind of entity is recorded and how long its changes are kept
 *
 * An entity without a row in the settings records changed fields only and keeps them the default number of days. A
 * row with an unknown mode or no valid number of days falls back to those defaults for the value it lacks.
 */
class EntityTypeSettings
{
    /**
     * @param Config $config
     * @param TrackedEntityPool $pool
     */
    public function __construct(
        private readonly Config $config,
        private readonly TrackedEntityPool $pool
    ) {
    }

    /**
     * What a change of the entity keeps
     *
     * @param string $code Tracked entity code
     * @return DiffMode
     */
    public function modeOf(string $code): DiffMode
    {
        $row = $this->rows()[$code] ?? null;

        return $row === null ? DiffMode::Changed : (DiffMode::tryFrom($row['diff_mode']) ?? DiffMode::Changed);
    }

    /**
     * Days the changes of the entity are kept
     *
     * @param string $code Tracked entity code
     * @return int
     */
    public function retentionDaysOf(string $code): int
    {
        $days = $this->rows()[$code]['retention_days'] ?? '';

        return ctype_digit($days) && (int) $days > 0 ? (int) $days : $this->config->getDefaultEntityRetentionDays();
    }

    /**
     * The longest any entity's changes are kept, which is also how long a change of an entity no longer tracked is
     *
     * @return int
     */
    public function longestRetentionDays(): int
    {
        $longest = $this->config->getDefaultEntityRetentionDays();
        foreach ($this->pool->all() as $entity) {
            $longest = max($longest, $this->retentionDaysOf($entity->getCode()));
        }

        return $longest;
    }

    /**
     * Rows by entity code; a later row for the same entity wins
     *
     * @return array<string, array{entity_type: string, diff_mode: string, retention_days: string}>
     */
    private function rows(): array
    {
        $rows = [];
        foreach ($this->config->getEntityTypeRows() as $row) {
            if ($row['entity_type'] !== '') {
                $rows[$row['entity_type']] = $row;
            }
        }

        return $rows;
    }
}
