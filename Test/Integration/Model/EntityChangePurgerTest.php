<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model;

use Hryvinskyi\ActivityLog\Api\ChannelPoolInterface;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\AdminActionLog\Model\Retention\EntityChangePurger;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Per-entity retention against the real journal
 */
#[DbIsolation(true)]
class EntityChangePurgerTest extends TestCase
{
    private const NOW_MS = 1792500000000;
    private const DAY_MS = 86_400_000;

    #[Config('hryvinskyi_admin_action_log/entity/default_retention_days', '90')]
    #[Config(
        'hryvinskyi_admin_action_log/entity/types',
        '{"_1":{"entity_type":"config_value","diff_mode":"changed","retention_days":"160"},'
        . '"_2":{"entity_type":"product","diff_mode":"snapshot","retention_days":"30"}}'
    )]
    public function testEachEntityKeepsItsChangesAsLongAsItsOwnSettingSays(): void
    {
        $productOld = $this->insert('product', 31);
        $productRecent = $this->insert('product', 29);
        $blockOld = $this->insert('cms_block', 91);
        $blockRecent = $this->insert('cms_block', 89);
        $configKept = $this->insert('config_value', 150);
        $configOld = $this->insert('config_value', 161);
        $notTracked = $this->insert('retired_entity', 150);

        $clock = $this->createStub(MillisecondClockInterface::class);
        $clock->method('nowMs')->willReturn(self::NOW_MS);
        $purger = Bootstrap::getObjectManager()->create(EntityChangePurger::class, ['clock' => $clock]);
        self::assertInstanceOf(EntityChangePurger::class, $purger);

        self::assertSame(3, $purger->purge());
        self::assertSame(
            [$productRecent, $blockRecent, $configKept, $notTracked],
            $this->remaining(
                [$productOld, $productRecent, $blockOld, $blockRecent, $configKept, $configOld, $notTracked]
            )
        );

        $channelPool = Bootstrap::getObjectManager()->get(ChannelPoolInterface::class);
        self::assertInstanceOf(ChannelPoolInterface::class, $channelPool);
        $channel = $channelPool->get('entity');
        self::assertNotNull($channel);
        self::assertSame(
            160,
            $channel->getRetentionDays(),
            'The journal keeps the channel as long as the longest-kept entity; it then removes the untracked ones.'
        );
    }

    /**
     * @param string $subjectType
     * @param int $daysAgo
     * @return int
     */
    private function insert(string $subjectType, int $daysAgo): int
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $connection = $resource->getConnection();
        $table = $resource->getTableName('hryvinskyi_activity_log');
        $occurredAtMs = self::NOW_MS - $daysAgo * self::DAY_MS;
        $correlationId = bin2hex(random_bytes(8));
        $connection->insert($table, [
            'correlation_id' => $correlationId,
            'channel' => 'entity',
            'action' => 'Saved',
            'subject_type' => $subjectType,
            'subject_label' => $subjectType,
            'actor_type' => 'admin',
            'actor_name' => 'jane.doe',
            'outcome' => 'success',
            'occurred_at_ms' => $occurredAtMs,
            'created_at' => gmdate('Y-m-d H:i:s', intdiv($occurredAtMs, 1000)),
        ]);
        $id = $connection->fetchOne(
            $connection->select()->from($table, ['entity_id'])->where('correlation_id = ?', $correlationId)
        );
        self::assertIsNumeric($id);

        return (int) $id;
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function remaining(array $ids): array
    {
        $resource = Bootstrap::getObjectManager()->get(ResourceConnection::class);
        self::assertInstanceOf(ResourceConnection::class, $resource);
        $connection = $resource->getConnection();
        $found = [];
        foreach ($connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'), ['entity_id'])
                ->where('entity_id IN (?)', $ids)
        ) as $id) {
            if (is_numeric($id)) {
                $found[] = (int) $id;
            }
        }

        return array_values(array_filter($ids, static fn (int $id): bool => in_array($id, $found, true)));
    }
}
