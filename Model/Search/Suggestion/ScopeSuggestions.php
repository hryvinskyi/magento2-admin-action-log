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
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecentChanges;

/**
 * "store": the scopes recent changes applied in (Default Config, a website, a store view), by the name recorded for
 * them, the most frequent first
 *
 * The name is the value, as the scope filter matches it; a store view the journal also suggests is offered once.
 */
class ScopeSuggestions implements SuggestionProviderInterface
{
    /**
     * @param RecentChanges $recentChanges
     * @param SuggestionInterfaceFactory $suggestionFactory
     */
    public function __construct(
        private readonly RecentChanges $recentChanges,
        private readonly SuggestionInterfaceFactory $suggestionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return 'store';
    }

    /**
     * @inheritDoc
     */
    public function suggestions(): array
    {
        $suggestions = [];
        foreach ($this->recentChanges->scopes() as $scope) {
            $suggestions[] = $this->suggestionFactory->create()
                ->withValue($scope['label'])
                ->withHint($this->kindOf($scope['type']))
                ->withCount($scope['count']);
        }

        return $suggestions;
    }

    /**
     * What a scope type is called, or nothing for the scopes whose name says it
     *
     * @param string $type
     * @return string
     */
    private function kindOf(string $type): string
    {
        return match ($type) {
            EntityScopeInterface::TYPE_WEBSITE => (string) __('Website'),
            EntityScopeInterface::TYPE_STORE => (string) __('Store view'),
            default => '',
        };
    }
}
