<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Performance;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Recording the changes of many products costs a fixed number of reads, whatever their number: the products are named
 * from one batched read of their SKU and name, never a load each
 *
 * The products are written straight into the catalog's tables, as an import leaves them, inside the test's
 * transaction; the recorder writes at once, so the records are seen and rolled back with it.
 */
#[DbIsolation(true)]
class RecordedChangesQueryTest extends TestCase
{
    private const SKU_PREFIX = 'aal-bulk-';

    public function testRecordingAHundredProductChangesReadsAsMuchAsRecordingTen(): void
    {
        $few = $this->products(10, 0);
        $many = $this->products(100, 10);
        $this->recorder()->recordAll($this->changes($this->products(1, 110)));

        $readsForFew = $this->selectsOf(fn () => $this->recorder()->recordAll($this->changes($few)));
        $readsForMany = $this->selectsOf(fn () => $this->recorder()->recordAll($this->changes($many)));

        self::assertSame(100, $this->countChanges($many), 'Every change is recorded');
        self::assertLessThanOrEqual(
            count($readsForFew),
            count($readsForMany),
            "Ten times the products, no more reads:\n" . implode("\n", $readsForMany)
        );
        self::assertLessThan(20, count($readsForMany), implode("\n", $readsForMany));
    }

    public function testEachProductIsNamedByItsSkuAndName(): void
    {
        $ids = $this->products(3, 200);

        $this->recorder()->recordAll($this->changes($ids));

        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $labels = $connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'), ['subject_label'])
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', 'product')
                ->where('subject_id IN (?)', array_values($ids))
                ->order('entity_id ASC')
        );
        self::assertSame([
            'Product aal-bulk-200 · Aal Bulk 200 · quantity',
            'Product aal-bulk-201 · Aal Bulk 201 · quantity',
            'Product aal-bulk-202 · Aal Bulk 202 · quantity',
        ], $labels);
    }

    /**
     * Products written straight into the catalog's tables, each with a name; their ids by SKU
     *
     * @param int $count
     * @param int $first Number of the first product
     * @return array<string, string>
     */
    private function products(int $count, int $first): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $nameId = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', 'name')
                ->where('entity_type_id = ?', 4)
        );
        self::assertIsNumeric($nameId);

        $ids = [];
        for ($number = $first; $number < $first + $count; $number++) {
            $sku = self::SKU_PREFIX . $number;
            $connection->insert($resource->getTableName('catalog_product_entity'), [
                'attribute_set_id' => 4,
                'type_id' => 'simple',
                'sku' => $sku,
            ]);
            $id = $connection->fetchOne(
                $connection->select()
                    ->from($resource->getTableName('catalog_product_entity'), ['entity_id'])
                    ->where('sku = ?', $sku)
            );
            self::assertIsNumeric($id);
            $connection->insert($resource->getTableName('catalog_product_entity_varchar'), [
                'attribute_id' => (int) $nameId,
                'store_id' => 0,
                'entity_id' => (int) $id,
                'value' => 'Aal Bulk ' . $number,
            ]);
            $ids[$sku] = (string) $id;
        }

        return $ids;
    }

    /**
     * A quantity change in a source for each product
     *
     * @param array<string, string> $ids
     * @return list<EntityChangeInterface>
     */
    private function changes(array $ids): array
    {
        $changes = [];
        foreach ($ids as $id) {
            $changes[] = self::service(EntityChangeInterfaceFactory::class)->create()
                ->withEntity('product', $id)
                ->withScope(EntityScopeInterface::TYPE_GLOBAL, 0, 'Back Room')
                ->withField('quantity', '100.0000', '95.0000');
        }

        return $changes;
    }

    /**
     * How many changes of these products were recorded
     *
     * @param array<string, string> $ids
     * @return int
     */
    private function countChanges(array $ids): int
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $count = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'), ['COUNT(*)'])
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', 'product')
                ->where('subject_id IN (?)', array_values($ids))
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The SELECT statements a call sends, recorded by the connection's profiler
     *
     * @param callable(): mixed $call
     * @return list<string>
     */
    private function selectsOf(callable $call): array
    {
        $connection = self::service(ResourceConnection::class)->getConnection();
        self::assertInstanceOf(\Zend_Db_Adapter_Abstract::class, $connection);
        $profiler = $connection->getProfiler();
        self::assertInstanceOf(\Zend_Db_Profiler::class, $profiler);
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $call();
        } finally {
            $profiler->setEnabled(false);
        }

        $queries = [];
        foreach ($profiler->getQueryProfiles(\Zend_Db_Profiler::SELECT) ?: [] as $profile) {
            if ($profile instanceof \Zend_Db_Profiler_Query) {
                $queries[] = $profile->getQuery();
            }
        }
        $profiler->clear();

        return $queries;
    }

    private function recorder(): EntityChangeRecorderInterface
    {
        return self::service(EntityChangeRecorderInterface::class);
    }

    /**
     * A service from the real container, typed
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
