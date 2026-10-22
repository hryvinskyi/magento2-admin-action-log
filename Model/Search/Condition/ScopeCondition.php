<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Condition;

use Hryvinskyi\ActivityLog\Api\Search\SearchConditionInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Magento\Store\Model\StoreManagerInterface;

/**
 * "store: Main Website": changes made in a scope — a store view or a website by code or name, or a scope without an
 * id by the name changes record for it ("Default Config", "All Store Views", "Global"), or any scope by the exact name
 * a change recorded for it ("store: Default Source" for a quantity recorded per source)
 *
 * The journal answers the same key with the store an activity ran in; the search joins both answers, so the key
 * finds a setting saved for a website as well as a request made in a store view. A code or a whole name matches, case
 * aside; a part of a name matches only when nothing has that code or name, so "Default Store View" never also finds
 * "All Store Views". A scope without an id is told apart by its recorded name as well as its type: "Default Config"
 * and "All Store Views" are both the default scope, recorded for settings and for records. A name that is no store
 * view, website or scope without an id also matches the changes that recorded exactly that name for their scope, case
 * aside, whatever the scope's type: another module may record changes in scopes of its own. Several scopes separated
 * by "|" match any of them ("Default Store View | All Store Views").
 */
class ScopeCondition implements SearchConditionInterface
{
    /**
     * What separates several scopes in one value
     */
    public const ALTERNATIVES = '|';

    /**
     * @var list<array{type: string, label: string}>
     */
    private readonly array $fixedScopes;

    /**
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     * @param string $key
     * @param array<string,array{type?:mixed,label?:mixed}> $fixedScopes Scopes without an id, by the name changes
     *     record for them
     * @throws \InvalidArgumentException When a fixed scope has no type or no name
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly StoreManagerInterface $storeManager,
        private readonly string $key = 'store',
        array $fixedScopes = []
    ) {
        $scopes = [];
        foreach ($fixedScopes as $name => $scope) {
            $type = $scope['type'] ?? null;
            $label = $scope['label'] ?? null;
            if (!is_string($type) || $type === '' || !is_string($label) || trim($label) === '') {
                throw new \InvalidArgumentException(sprintf('Fixed scope "%s" needs a type and a name.', $name));
            }

            $scopes[] = ['type' => $type, 'label' => $label];
        }

        $this->fixedScopes = $scopes;
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
        $scopes = [];
        foreach (explode(self::ALTERNATIVES, $value) as $part) {
            $part = trim($part);
            if ($part !== '') {
                array_push($scopes, ...$this->scopes($part));
            }
        }

        if ($scopes === []) {
            return null;
        }

        return $this->resourceConnection->getConnection()->select()
            ->from(
                ['scoped' => $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE)],
                ['activity_id']
            )
            ->where(implode(' OR ', array_unique($scopes)), null, Select::TYPE_CONDITION);
    }

    /**
     * Changes whose recorded scope name is the whole name, case aside, as an SQL condition on a field row
     *
     * The column compares without case by its collation, so the name is compared as it is: a function over the column
     * would be computed for every field row the search reads.
     *
     * @param string $value
     * @return string
     */
    private function recordedName(string $value): string
    {
        return $this->resourceConnection->getConnection()->quoteInto('scoped.scope_label = ?', $value);
    }

    /**
     * The scopes one name stands for, as SQL conditions on a field row
     *
     * Those it names exactly. Else any scope a change recorded under exactly that name (a scope that is no store view
     * or website, such as a source of stock), and the store views and websites whose name contains it.
     *
     * @param string $value
     * @return list<string>
     */
    private function scopes(string $value): array
    {
        $exact = $this->matching(fn (string $code, string $name): bool => $this->same($code, $value)
            || $this->same($name, $value));

        return $exact !== []
            ? $exact
            : [
                $this->recordedName($value),
                ...$this->matching(static fn (string $code, string $name): bool => mb_stripos($name, $value) !== false),
            ];
    }

    /**
     * Conditions for every scope the test accepts by its code and name, as SQL on a field row
     *
     * A scope without an id has no code; its recorded name and the translation of it are both its name.
     *
     * @param \Closure(string,string):bool $accepts
     * @return list<string>
     */
    private function matching(\Closure $accepts): array
    {
        $connection = $this->resourceConnection->getConnection();
        $conditions = [];
        foreach ($this->fixedScopes as $scope) {
            if ($accepts('', $scope['label']) || $accepts('', (string) __($scope['label']))) {
                $conditions[] = '(' . $connection->quoteInto('scoped.scope_type = ?', $scope['type'])
                    . ' AND ' . $connection->quoteInto('scoped.scope_label = ?', $scope['label']) . ')';
            }
        }

        $ids = [EntityScopeInterface::TYPE_WEBSITE => [], EntityScopeInterface::TYPE_STORE => []];
        foreach ($this->storeManager->getWebsites() as $website) {
            if ($accepts((string) $website->getCode(), (string) $website->getName())) {
                $ids[EntityScopeInterface::TYPE_WEBSITE][] = (int) $website->getId();
            }
        }
        foreach ($this->storeManager->getStores() as $store) {
            if ($accepts((string) $store->getCode(), (string) $store->getName())) {
                $ids[EntityScopeInterface::TYPE_STORE][] = (int) $store->getId();
            }
        }
        foreach ($ids as $type => $scopeIds) {
            if ($scopeIds !== []) {
                $conditions[] = '(' . $connection->quoteInto('scoped.scope_type = ?', $type)
                    . ' AND ' . $connection->quoteInto('scoped.scope_id IN (?)', $scopeIds) . ')';
            }
        }

        return $conditions;
    }

    /**
     * Whether two texts are the same, case aside; an empty text is never the same as anything
     *
     * @param string $text
     * @param string $value
     * @return bool
     */
    private function same(string $text, string $value): bool
    {
        return $text !== '' && mb_strtolower($text) === mb_strtolower($value);
    }
}
