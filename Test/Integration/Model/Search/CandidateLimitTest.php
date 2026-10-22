<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model\Search;

use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Test\Integration\Model\Search\Fixture\EntityChanges;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use PHPUnit\Framework\TestCase;

/**
 * Value and text conditions run among the newest candidates only, through the engine's candidate limit
 */
#[DbIsolation(true)]
class CandidateLimitTest extends TestCase
{
    private const CHANGES = 10_010;
    private const CORRELATION = 'op-candidate-limit';

    public function testAValueBeyondTheNewestCandidatesIsNotSearchedAndThePageSaysSo(): void
    {
        $changes = new EntityChanges();
        $changes->clear();
        $this->seed();
        $newest = $this->activityOf((string) self::CHANGES);

        $page = $changes->search([['value', 'aalneedle']]);
        $ids = [];
        foreach ($page->getEvents() as $event) {
            $ids[] = $event->getActivityId();
        }

        self::assertSame([$newest], $ids, 'The five oldest matches sit outside the newest candidates');
        self::assertTrue($page->isNarrowed());
        self::assertFalse($changes->search([['product', '#' . self::CHANGES]])->isNarrowed());
    }

    /**
     * Changes of products 1 to CHANGES, oldest first, one field each; products 1-5 and the newest hold the needle
     *
     * @return void
     */
    private function seed(): void
    {
        $resource = EntityChanges::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $journal = $resource->getTableName('hryvinskyi_activity_log');
        $firstMs = EntityChanges::NOW_MS - self::CHANGES;

        $connection->query(
            "INSERT INTO {$journal} (correlation_id, channel, action, subject_type, subject_id, subject_label,
                actor_type, actor_name, outcome, store_id, occurred_at_ms, created_at)
            SELECT '" . self::CORRELATION . "', 'entity', 'Product saved', 'product', CAST(seq AS CHAR),
                CONCAT('Product #', seq), 'admin', 'Tester', 'success', 0, {$firstMs} + seq,
                FROM_UNIXTIME(FLOOR(({$firstMs} + seq) / 1000))
            FROM seq_1_to_" . self::CHANGES
        );
        $connection->query(
            'INSERT INTO ' . $resource->getTableName(EntityChangeStore::TABLE) . " (activity_id, entity_type,
                entity_id, operation, diff_mode, unchanged_count)
            SELECT entity_id, 'product', subject_id, 'update', 'changed', 0 FROM {$journal}
            WHERE correlation_id = '" . self::CORRELATION . "'"
        );
        $connection->query(
            'INSERT INTO ' . $resource->getTableName(EntityChangeStore::FIELD_TABLE) . " (activity_id, scope_type,
                scope_id, scope_label, field, before_value, after_value, is_changed, is_masked)
            SELECT entity_id, 'default', 0, 'Default Config', 'description', 'plain',
                IF(CAST(subject_id AS UNSIGNED) <= 5 OR CAST(subject_id AS UNSIGNED) = " . self::CHANGES . ",
                    'with aalneedle inside', 'plain text'), 1, 0
            FROM {$journal} WHERE correlation_id = '" . self::CORRELATION . "'"
        );
    }

    /**
     * Activity id of the change of a product
     *
     * @param string $productId
     * @return int
     */
    private function activityOf(string $productId): int
    {
        $resource = EntityChanges::service(ResourceConnection::class);
        $id = $resource->getConnection()->fetchOne(
            $resource->getConnection()->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'), ['entity_id'])
                ->where('correlation_id = ?', self::CORRELATION)
                ->where('subject_id = ?', $productId)
        );

        return is_numeric($id) ? (int) $id : 0;
    }
}
