<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model\Search\Fixture;

use Hryvinskyi\ActivityLog\Api\Data\Search\SearchCriterionInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchPageInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchRequestInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Search\ActivitySearchInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;

/**
 * Entity changes written straight into the journal and the change tables, and searches through the real engine
 *
 * Changes are recorded only once a save's transaction commits, which never happens inside a test's transaction, so
 * the rows are written here rather than through saves.
 *
 * @phpstan-type Field array{
 *     field: string,
 *     before?: string|null,
 *     after?: string|null,
 *     changed?: bool,
 *     masked?: bool,
 *     automatic?: bool
 * }
 */
class EntityChanges
{
    public const NOW_MS = 1_792_500_000_000;
    private const DAY_MS = 86_400_000;
    private const JOURNAL_TABLE = 'hryvinskyi_activity_log';

    /**
     * Ms of the change written next; each change is a millisecond older than the one before
     *
     * @var int
     */
    private int $nextMs = self::NOW_MS;

    /**
     * Empty the journal, and with it every change; inside a test's transaction the rows come back afterwards
     *
     * @return void
     */
    public function clear(): void
    {
        $resource = self::service(ResourceConnection::class);
        $resource->getConnection()->delete($resource->getTableName(self::JOURNAL_TABLE));
    }

    /**
     * Write one entity change and return its activity id
     *
     * @param string $entityType
     * @param string|null $entityId
     * @param list<Field> $fields
     * @param array{0: string, 1: int, 2: string} $scope Type, id and name of the scope
     * @param array<string, mixed> $journal Journal columns to set
     * @param ChangeOperation $operation
     * @param int $unchangedCount Fields compared without a row of their own
     * @return int
     */
    public function change(
        string $entityType,
        ?string $entityId,
        array $fields,
        array $scope = ['default', 0, 'Default Config'],
        array $journal = [],
        ChangeOperation $operation = ChangeOperation::Update,
        int $unchangedCount = 0
    ): int {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $table = $resource->getTableName(self::JOURNAL_TABLE);
        $ms = $this->nextMs--;
        $connection->insert($table, $journal + [
            'correlation_id' => 'op-entity-search',
            'channel' => 'entity',
            'action' => 'Entity saved',
            'subject_type' => $entityType,
            'subject_id' => $entityId,
            'subject_label' => ucfirst($entityType) . ' #' . $entityId,
            'actor_type' => 'admin',
            'actor_name' => 'Tester',
            'outcome' => 'success',
            'store_id' => 0,
            'occurred_at_ms' => $ms,
            'created_at' => gmdate('Y-m-d H:i:s', intdiv($ms, 1000)),
        ]);
        $id = $connection->fetchOne($connection->select()->from($table, ['MAX(entity_id)']));
        $activityId = is_numeric($id) ? (int) $id : 0;

        self::service(EntityChangeStore::class)->save(
            $activityId,
            $entityType,
            $entityId,
            $operation,
            DiffMode::Changed,
            $unchangedCount,
            self::service(EntityScopeInterfaceFactory::class)->create(
                ['type' => $scope[0], 'id' => $scope[1], 'label' => $scope[2]]
            ),
            array_map(static fn (array $field): array => [
                'field' => $field['field'],
                'before' => $field['before'] ?? null,
                'after' => $field['after'] ?? null,
                'changed' => $field['changed'] ?? true,
                'masked' => $field['masked'] ?? false,
                'automatic' => $field['automatic'] ?? false,
            ], $fields)
        );

        return $activityId;
    }

    /**
     * Ids of the activities a search over the day around NOW_MS finds, newest first
     *
     * @param list<array{0: string, 1: string}> $chips
     * @return list<int>
     */
    public function find(array $chips): array
    {
        $ids = [];
        foreach ($this->search($chips)->getEvents() as $event) {
            $ids[] = $event->getActivityId();
        }

        return $ids;
    }

    /**
     * A search over the day around NOW_MS through the engine the container builds
     *
     * @param list<array{0: string, 1: string}> $chips
     * @param int $limit
     * @return SearchPageInterface
     */
    public function search(array $chips, int $limit = 200): SearchPageInterface
    {
        $criteria = [];
        foreach ($chips as [$key, $value]) {
            $criteria[] = self::service(SearchCriterionInterfaceFactory::class)->create()->withTerm($key, $value);
        }

        return self::service(ActivitySearchInterface::class)->search(
            self::service(SearchRequestInterfaceFactory::class)->create()
                ->withRange(self::NOW_MS - self::DAY_MS, self::NOW_MS + self::DAY_MS)
                ->withCriteria($criteria)
                ->withChangesOnly(false)
                ->withLimit($limit)
        );
    }

    /**
     * A service from the real container, typed
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     * @throws \LogicException When the container answers with something else
     */
    public static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        if (!$service instanceof $class) {
            throw new \LogicException(sprintf('The container has no %s.', $class));
        }

        return $service;
    }
}
