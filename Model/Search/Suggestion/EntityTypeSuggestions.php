<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Suggestion;

use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Search\SuggestionProviderInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecentChanges;

/**
 * "entity": every tracked kind of record, by code, under its label, with its number of recent changes
 *
 * Kinds without recent changes are offered too, with a count of 0, after those with changes: the search's range may
 * reach further back than the suggestions do.
 */
class EntityTypeSuggestions implements SuggestionProviderInterface
{
    /**
     * @param TrackedEntityPool $trackedEntityPool
     * @param RecentChanges $recentChanges
     * @param SuggestionInterfaceFactory $suggestionFactory
     */
    public function __construct(
        private readonly TrackedEntityPool $trackedEntityPool,
        private readonly RecentChanges $recentChanges,
        private readonly SuggestionInterfaceFactory $suggestionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'entity';
    }

    /**
     * @inheritDoc
     */
    public function suggestions(): array
    {
        $counts = $this->recentChanges->entityTypes();
        $kinds = [];
        foreach ($this->trackedEntityPool->all() as $entity) {
            $kinds[] = [
                'code' => $entity->getCode(),
                'label' => (string) __($entity->getLabel()),
                'count' => $counts[$entity->getCode()] ?? 0,
            ];
        }
        usort(
            $kinds,
            static fn (array $a, array $b): int => [$b['count'], $a['label']] <=> [$a['count'], $b['label']]
        );

        $suggestions = [];
        foreach ($kinds as $kind) {
            $suggestions[] = $this->suggestionFactory->create()
                ->withValue($kind['code'], $kind['label'])
                ->withCount($kind['count']);
        }

        return $suggestions;
    }
}
