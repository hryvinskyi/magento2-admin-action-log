<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model;

use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeRecorder;
use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\StockRowChanges;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Plugin\CatalogImportExport\RecordStockImport;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogImportExport\Model\StockItemImporter;
use Magento\CatalogInventory\Model\ResourceModel\Stock\ItemFactory as StockItemResourceFactory;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\Registry;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager as TestObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The stock a product import writes with one query is recorded as a change of each product, through the stock item
 * part this module ships: the store keeps its stock without sources
 *
 * The store these tests run in may keep its stock in sources, with another module replacing the stock item part, so
 * each test puts this module's own part back in the tracked entity pool and has the container build the import plugin
 * with it, around the importer's own write. The journal is written outside any test transaction, so the tests remove
 * what they recorded.
 *
 * @phpstan-import-type ChangeDetail from EntityChangeStore
 */
#[DbIsolation(false)]
class StockImportChangeTest extends TestCase
{
    private const SKU = 'aal-import-tote';
    private const OWN_PART = 'Hryvinskyi\AdminActionLog\Model\EntityChange\ProductStockItemPart';

    /**
     * The pool's parts as installed, put back after each test
     *
     * @var mixed
     */
    private mixed $installedParts = null;

    /**
     * @var string
     */
    private string $productId = '';

    /**
     * The newest journal id before the import
     *
     * @var int
     */
    private int $since = 0;

    protected function setUp(): void
    {
        $arguments = self::service(ConfigInterface::class)->getArguments(TrackedEntityPool::class);
        $this->installedParts = $arguments['parts'] ?? [];
        $this->useParts(['product_stock_item' => ['instance' => self::OWN_PART]]);

        $product = self::service(ProductInterfaceFactory::class)->create();
        self::assertInstanceOf(Product::class, $product);
        $product->setSku(self::SKU)
            ->setName('Aal Import Tote')
            ->setAttributeSetId(4)
            ->setTypeId('simple')
            ->setPrice(10)
            ->setStatus(1)
            ->setVisibility(4)
            ->setData('stock_data', ['qty' => 100, 'is_in_stock' => 1, 'manage_stock' => 1]);
        $this->productId = (string) self::service(ProductRepositoryInterface::class)->save($product)->getId();
        self::service(StockRegistryStorage::class)->clean();
        $this->since = $this->latestJournalId();
    }

    protected function tearDown(): void
    {
        $registry = self::service(Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        try {
            self::service(ProductRepositoryInterface::class)->deleteById(self::SKU);
        } catch (NoSuchEntityException) {
            // Already gone: nothing to remove
        } finally {
            $registry->unregister('isSecureArea');
        }

        self::service(StockRegistryStorage::class)->clean();
        $this->useParts($this->installedParts);
        $resource = self::service(ResourceConnection::class);
        $resource->getConnection()->delete($resource->getTableName('hryvinskyi_activity_log'), [
            'channel = ?' => 'entity',
            'subject_type = ?' => 'product',
            'subject_id = ?' => $this->productId,
        ]);
    }

    public function testAnImportedQuantityAndThresholdAreOneChangeOfTheProduct(): void
    {
        $this->import([
            'qty' => 95,
            'min_qty' => 2,
            'use_config_min_qty' => 0,
            'low_stock_date' => '2026-11-07 10:00:00',
        ]);

        $changes = $this->changes();
        self::assertCount(1, $changes, 'One change of the product for the whole row');
        self::assertSame('Product saved', $changes[0]['action']);
        self::assertSame(['product', $this->productId], [
            $changes[0]['detail']['entityType'],
            $changes[0]['detail']['entityId'],
        ]);
        self::assertStringStartsWith('Product aal-import-tote · Aal Import Tote', $changes[0]['subject']);
        self::assertSame(
            [
                'qty' => ['100.0000', '95.0000'],
                'min_qty' => ['0.0000', '2.0000'],
                'use_config_min_qty' => ['1', '0'],
            ],
            $this->fields($changes[0]['detail']),
            'The fields the stock item part compares, never what Magento derives (the low stock date)'
        );
    }

    public function testAnImportThatStoresWhatIsAlreadyStoredRecordsNothing(): void
    {
        $this->import([]);

        self::assertSame([], $this->changes());
    }

    public function testAnImportThatFailsRecordsNothing(): void
    {
        try {
            $this->import(['qty' => 95, 'no_such_column' => 1]);
            self::fail('The importer accepted a column its table does not have.');
        } catch (CouldNotSaveException) {
            self::assertSame([], $this->changes());
        }
    }

    /**
     * Import the product's stock row with these values through the plugin the container builds
     *
     * @param array<string, mixed> $values
     * @return void
     */
    private function import(array $values): void
    {
        $plugin = Bootstrap::getObjectManager()->create(RecordStockImport::class);
        self::assertInstanceOf(RecordStockImport::class, $plugin);
        $importer = new StockItemImporter(
            self::service(StockItemResourceFactory::class),
            self::service(LoggerInterface::class)
        );

        $plugin->aroundImport(
            $importer,
            static function (array $stockData) use ($importer): mixed {
                $importer->import($stockData);

                return null;
            },
            [self::SKU => ['sku' => self::SKU] + $values + $this->storedRow()]
        );
    }

    /**
     * The product's stock item row as stored, without its own id, as the import builds it
     *
     * @return array<string, mixed>
     */
    private function storedRow(): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('cataloginventory_stock_item'))
                ->where('product_id = ?', $this->productId)
        );
        self::assertIsArray($row, 'The product has no stock item row.');
        unset($row['item_id']);

        $stored = [];
        foreach ($row as $column => $value) {
            $stored[(string) $column] = $value;
        }

        return $stored;
    }

    /**
     * Give the tracked entity pool these parts, and forget the services built with the pool they had
     *
     * @param mixed $parts The "parts" argument as the container's configuration holds it
     * @return void
     */
    private function useParts(mixed $parts): void
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(TestObjectManager::class, $objectManager);
        $objectManager->configure([TrackedEntityPool::class => ['arguments' => ['parts' => $parts]]]);
        foreach ([
            TrackedEntityPool::class,
            StockRowChanges::class,
            EntityChangeRecorder::class,
            EntityChangeRecorderInterface::class,
        ] as $built) {
            $objectManager->removeSharedInstance($built);
        }
    }

    /**
     * The newest journal id, to tell later records from earlier ones
     *
     * @return int
     */
    private function latestJournalId(): int
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()->from($resource->getTableName('hryvinskyi_activity_log'), ['MAX(entity_id)'])
        );

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * The product's changes recorded since the test began, oldest first
     *
     * @return list<array{action: string, subject: string, detail: ChangeDetail}>
     */
    private function changes(): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $changes = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', 'product')
                ->where('subject_id = ?', $this->productId)
                ->where('entity_id > ?', $this->since)
                ->order('entity_id ASC')
        ) as $row) {
            self::assertIsArray($row);
            self::assertIsNumeric($row['entity_id'] ?? null);
            self::assertIsString($row['action'] ?? null);
            self::assertIsString($row['subject_label'] ?? null);
            $detail = self::service(EntityChangeStore::class)->find((int) $row['entity_id']);
            self::assertNotNull($detail, 'A change without its field detail.');
            $changes[] = ['action' => $row['action'], 'subject' => $row['subject_label'], 'detail' => $detail];
        }

        return $changes;
    }

    /**
     * A change's fields by name: before and after
     *
     * @param ChangeDetail $detail
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    private function fields(array $detail): array
    {
        $fields = [];
        foreach ($detail['fields'] as $field) {
            $fields[$field['field']] = [$field['before'], $field['after']];
        }

        return $fields;
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
