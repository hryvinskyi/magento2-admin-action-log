<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model;

use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeJournal;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Observer\EntityChange\RecordCommittedChange;
use Hryvinskyi\AdminActionLog\Observer\EntityChange\SnapshotBeforeDelete;
use Hryvinskyi\AdminActionLog\Observer\EntityChange\SnapshotBeforeSave;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Api\StockItemRepositoryInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock as StockResource;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item as StockItemResource;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\Framework\Registry;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager as TestObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * A product's stock item saved through the catalog inventory services is recorded as a change of the product
 *
 * Changes are journaled after their transaction commits, so these tests commit for real and remove what they wrote.
 *
 * The tests are about the stock item part this module ships. Another module that records stock from another source
 * may replace that part in the tracked entity pool and record changes of its own for the same saves, so each test
 * puts this module's part back in the pool for its duration, and looks only at the changes that carry a field of the
 * stock item (or create the product).
 *
 * @phpstan-import-type ChangeDetail from EntityChangeStore
 */
#[DbIsolation(false)]
class StockItemChangeTest extends TestCase
{
    private const SKU = 'aal-stock-tote';
    private const OWN_PART = 'Hryvinskyi\AdminActionLog\Model\EntityChange\ProductStockItemPart';

    /**
     * The fields the stock item part compares
     */
    private const STOCK_FIELDS = [
        'qty', 'is_in_stock', 'manage_stock', 'use_config_manage_stock', 'backorders', 'use_config_backorders',
        'min_qty', 'use_config_min_qty', 'min_sale_qty', 'use_config_min_sale_qty', 'max_sale_qty',
        'use_config_max_sale_qty', 'notify_stock_qty', 'use_config_notify_stock_qty', 'enable_qty_increments',
        'use_config_enable_qty_inc', 'qty_increments', 'use_config_qty_increments', 'is_qty_decimal',
        'is_decimal_divided',
    ];

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

    protected function setUp(): void
    {
        $arguments = self::service(ConfigInterface::class)->getArguments(TrackedEntityPool::class);
        $this->installedParts = $arguments['parts'] ?? [];
        $this->useParts(['product_stock_item' => ['instance' => self::OWN_PART]]);

        $product = self::service(ProductInterfaceFactory::class)->create();
        self::assertInstanceOf(Product::class, $product);
        $product->setSku(self::SKU)
            ->setName('Aal Stock Tote')
            ->setAttributeSetId(4)
            ->setTypeId('simple')
            ->setPrice(10)
            ->setStatus(1)
            ->setVisibility(4)
            ->setData('stock_data', ['qty' => 100, 'is_in_stock' => 1, 'manage_stock' => 1]);
        $this->productId = (string) self::service(ProductRepositoryInterface::class)->save($product)->getId();
        self::service(StockRegistryStorage::class)->clean();
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
            'subject_label LIKE ?' => 'Product ' . self::SKU . '%',
        ]);
    }

    public function testANewProductsStockIsRecordedAsAChangeOfTheProductNotAsASecondCreation(): void
    {
        $actions = $this->actions();
        self::assertSame(1, $actions['Product created'] ?? 0, 'The product is created once');
        self::assertSame(1, $actions['Product saved'] ?? 0, 'Its stock is set on the product that now exists');

        $stock = $this->fieldsByName($this->latestChange('Product saved')['detail']);
        self::assertArrayHasKey('qty', $stock);
        self::assertNull($stock['qty']['before'], 'Set from nothing');
        self::assertEquals(100, (float) $stock['qty']['after']);
        self::assertSame(ChangeOperation::Update, $this->latestChange('Product saved')['detail']['operation']);
        self::assertSame('1', $stock['is_in_stock']['after'] ?? null);
        self::assertArrayNotHasKey('low_stock_date', $stock, 'What Magento derives is not recorded');
        self::assertArrayNotHasKey('product_id', $stock, 'The product id names the subject; it is not a field');
    }

    public function testAQuantitySavedThroughTheRepositoryIsAChangeOfTheProduct(): void
    {
        $item = $this->stockItem();
        $item->setQty(95);
        self::service(StockItemRepositoryInterface::class)->save($item);

        $change = $this->latestChange();
        self::assertSame('Product saved', $change['action']);
        self::assertSame('product', $change['detail']['entityType']);
        self::assertSame($this->productId, $change['detail']['entityId'], 'Recorded under the product\'s id');
        self::assertStringStartsWith('Product aal-stock-tote · Aal Stock Tote · qty', $change['subject']);

        $fields = $this->fieldsByName($change['detail']);
        self::assertSame(['qty'], array_keys($fields), 'Only what changed');
        self::assertSame('100.0000', $fields['qty']['before'] ?? null);
        self::assertEquals(95, (float) ($fields['qty']['after'] ?? 0));
    }

    public function testAQuantityASaleTakesWithOneQueryIsAChangeOfTheProduct(): void
    {
        self::service(StockResource::class)->correctItemsQty([(int) $this->productId => 2], 0, '-');

        $change = $this->latestChange();
        self::assertSame(
            ['product', $this->productId],
            [$change['detail']['entityType'], $change['detail']['entityId']]
        );
        $fields = $this->fieldsByName($change['detail']);
        self::assertSame(['qty'], array_keys($fields));
        self::assertSame(['100.0000', '98.0000'], [$fields['qty']['before'] ?? null, $fields['qty']['after'] ?? null]);
    }

    public function testGoingOutOfStockIsRecordedAsTheStockStatus(): void
    {
        $item = $this->stockItem();
        $item->setQty(0);
        $item->setIsInStock(false);
        self::service(StockItemRepositoryInterface::class)->save($item);

        $fields = $this->fieldsByName($this->latestChange()['detail']);
        $status = $fields['is_in_stock'] ?? [];
        self::assertSame(['1', '0'], [$status['before'] ?? null, $status['after'] ?? null]);
        self::assertArrayHasKey('qty', $fields);
    }

    public function testTheServiceTheProductFormAndTheRestApiSaveStockThroughIsRecorded(): void
    {
        $item = self::service(StockItemInterfaceFactory::class)->create();
        $item->setQty(7);
        $item->setIsInStock(true);
        self::service(StockRegistryInterface::class)->updateStockItemBySku(self::SKU, $item);

        $change = $this->latestChange();
        self::assertSame($this->productId, $change['detail']['entityId']);
        self::assertEquals(7, (float) ($this->fieldsByName($change['detail'])['qty']['after'] ?? 0));
    }

    public function testAStockSaveThatChangesNothingRecordsNothing(): void
    {
        $before = $this->actions();

        self::service(StockItemRepositoryInterface::class)->save($this->stockItem());

        self::assertSame($before, $this->actions());
    }

    public function testASaveAnOuterTransactionRolledBackRecordsNothingWhenALaterCommitFiresIt(): void
    {
        $before = $this->actions();
        $connection = self::service(ResourceConnection::class)->getConnection();
        $item = $this->stockItem();
        $item->setQty(42);

        $connection->beginTransaction();
        try {
            self::service(StockItemRepositoryInterface::class)->save($item);
        } finally {
            $connection->rollBack();
        }

        $resource = self::service(StockItemResource::class);
        $resource->beginTransaction();
        $resource->commit();

        self::assertEquals(100, (float) $this->stockItem()->getQty(), 'The rolled-back quantity was never stored');
        self::assertSame(
            $before,
            $this->actions(),
            'The commit callback the rollback left behind fires on the next commit and finds nothing changed'
        );
    }

    public function testDeletingAStockItemDoesNotDeleteTheProduct(): void
    {
        $before = $this->actions();

        self::service(StockItemRepositoryInterface::class)->delete($this->stockItem());

        self::assertSame($before, $this->actions(), 'A part goes with its product; deleting it alone records nothing');
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
            EntityChangeJournal::class,
            EntityChangeJournal::class . '\\Proxy',
            SnapshotBeforeSave::class,
            SnapshotBeforeDelete::class,
            RecordCommittedChange::class,
        ] as $built) {
            $objectManager->removeSharedInstance($built);
        }
    }

    /**
     * Changes of the product made through its stock item, or its creation, as a condition on a journal row
     *
     * @param string $alias The journal's alias
     * @return string
     */
    private function throughTheStockItem(string $alias): string
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $fields = $connection->select()
            ->from(['f' => $resource->getTableName(EntityChangeStore::FIELD_TABLE)], ['activity_id'])
            ->where('f.field IN (?)', self::STOCK_FIELDS);

        return sprintf(
            '(%s OR %s.entity_id IN (%s))',
            $connection->quoteInto($alias . '.action = ?', 'Product created'),
            $alias,
            $fields
        );
    }

    /**
     * The product's stock item as stored, read past the request's cache
     *
     * @return StockItemInterface
     */
    private function stockItem(): StockItemInterface
    {
        self::service(StockRegistryStorage::class)->clean();

        return self::service(StockRegistryInterface::class)->getStockItemBySku(self::SKU);
    }

    /**
     * How many changes of the product were recorded, by action
     *
     * @return array<string, int>
     */
    private function actions(): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $counts = [];
        foreach ($connection->fetchPairs(
            $connection->select()
                ->from(['j' => $resource->getTableName('hryvinskyi_activity_log')], ['action', 'COUNT(*)'])
                ->where('j.channel = ?', 'entity')
                ->where('j.subject_type = ?', 'product')
                ->where('j.subject_id = ?', $this->productId)
                ->where($this->throughTheStockItem('j'))
                ->group('j.action')
        ) as $action => $count) {
            $counts[(string) $action] = is_numeric($count) ? (int) $count : 0;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @param string|null $action
     * @return array{action: string, subject: string, detail: ChangeDetail}
     */
    private function latestChange(?string $action = null): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $select = $connection->select()
            ->from(['j' => $resource->getTableName('hryvinskyi_activity_log')])
            ->where('j.channel = ?', 'entity')
            ->where('j.subject_type = ?', 'product')
            ->where('j.subject_id = ?', $this->productId)
            ->where($this->throughTheStockItem('j'))
            ->order('j.entity_id DESC')
            ->limit(1);
        if ($action !== null) {
            $select->where('j.action = ?', $action);
        }

        $row = $connection->fetchRow($select);
        self::assertIsArray($row, 'The change was not journaled.');
        self::assertIsNumeric($row['entity_id'] ?? null);
        self::assertIsString($row['action'] ?? null);
        self::assertIsString($row['subject_label'] ?? null);
        $detail = self::service(EntityChangeStore::class)->find((int) $row['entity_id']);
        self::assertNotNull($detail, 'The change has no field detail.');

        return ['action' => $row['action'], 'subject' => $row['subject_label'], 'detail' => $detail];
    }

    /**
     * @param ChangeDetail $detail
     * @return array<string, array{before: string|null, after: string|null}>
     */
    private function fieldsByName(array $detail): array
    {
        $fields = [];
        foreach ($detail['fields'] as $field) {
            $fields[$field['field']] = ['before' => $field['before'], 'after' => $field['after']];
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
