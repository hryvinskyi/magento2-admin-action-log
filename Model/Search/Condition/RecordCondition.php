<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Condition;

use Hryvinskyi\ActivityLog\Api\Search\SearchConditionInterface;
use Hryvinskyi\ActivityLog\Api\Search\SubjectLookupInterface;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordResolverInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;

/**
 * "product: 24-MB01", "order: #12": the changes of the records a person names, by the entity kinds the key covers
 *
 * "#<id>" names one record by its id and nothing else, so "#1245" never finds record 12450 nor a name containing 1245;
 * for an order it is also the order whose number is exactly that ("#100000001"). Any other value is resolved by each
 * kind's resolver (a SKU, an identifier, a name) into ids, matched on the change table's (kind, id) index. When the
 * value names no existing record — a deleted one, a name since changed — the records are taken from the names the
 * journal recorded for them: up to the journal's limit of records whose recorded subject name contains the value, with
 * all their changes, so history outlives the record. That lookup runs once per search; the search itself stays on the
 * index.
 */
class RecordCondition implements SearchConditionInterface
{
    private const EXACT_PATTERN = '/^#\s*(\S.{0,63})$/uD';

    /**
     * @var array<string, RecordResolverInterface>
     */
    private readonly array $resolvers;

    /**
     * @param ResourceConnection $resourceConnection
     * @param SubjectLookupInterface $subjectLookup
     * @param string $key
     * @param array<string,RecordResolverInterface> $entities Resolver of each tracked entity code the key covers
     * @throws \InvalidArgumentException When the key covers no entity kind
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly SubjectLookupInterface $subjectLookup,
        private readonly string $key,
        array $entities
    ) {
        $resolvers = [];
        foreach ($entities as $code => $resolver) {
            $resolvers[(string) $code] = $this->resolver($resolver);
        }

        if ($resolvers === []) {
            throw new \InvalidArgumentException(sprintf('Search key "%s" covers no entity kind.', $key));
        }

        $this->resolvers = $resolvers;
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
        if (preg_match(self::EXACT_PATTERN, $value, $exact) === 1) {
            $id = trim($exact[1]);
            $ids = array_map(static fn (RecordResolverInterface $r): array => $r->idsOf($id), $this->resolvers);

            return $this->changesOf($ids);
        }

        $ids = array_map(static fn (RecordResolverInterface $r): array => $r->idsFor($value), $this->resolvers);

        return $this->changesOf($ids) ?? $this->changesOf($this->recordedAs($value));
    }

    /**
     * Changes of the records with these ids, kind by kind; null when there are none
     *
     * @param array<string,list<string>> $idsByCode
     * @return Select|null
     */
    private function changesOf(array $idsByCode): ?Select
    {
        $connection = $this->resourceConnection->getConnection();
        $records = [];
        foreach ($idsByCode as $code => $ids) {
            if ($ids !== []) {
                $records[] = '(' . $connection->quoteInto('recorded.entity_type = ?', $code)
                    . ' AND ' . $connection->quoteInto('recorded.entity_id IN (?)', $ids) . ')';
            }
        }

        if ($records === []) {
            return null;
        }

        return $connection->select()
            ->from(
                ['recorded' => $this->resourceConnection->getTableName(EntityChangeStore::TABLE)],
                ['activity_id']
            )
            ->where(implode(' OR ', $records), null, Select::TYPE_CONDITION);
    }

    /**
     * Ids, kind by kind, of the records whose recorded subject name contains the value
     *
     * @param string $value
     * @return array<string,list<string>>
     */
    private function recordedAs(string $value): array
    {
        $ids = [];
        foreach ($this->subjectLookup->subjectsLike(array_keys($this->resolvers), $value) as $subject) {
            $ids[$subject['type']][] = $subject['id'];
        }

        return $ids;
    }

    /**
     * A resolver from the declaration; anything else fails here, at construction
     *
     * @param RecordResolverInterface $resolver
     * @return RecordResolverInterface
     */
    private function resolver(RecordResolverInterface $resolver): RecordResolverInterface
    {
        return $resolver;
    }
}
