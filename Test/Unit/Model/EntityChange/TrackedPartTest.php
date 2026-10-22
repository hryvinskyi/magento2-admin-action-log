<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource\FieldSourceInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource\StoredFieldSource;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Scope\ScopeResolverInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\EntityStorageInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\StoredRecordsInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntity;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedPart;
use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;

/**
 * A model keeping part of an entity's data is recorded as that entity: its code, label, id and name
 */
class TrackedPartTest extends TestCase
{
    public function testTheChangesAreTheEntitysUnderItsCodeLabelAndId(): void
    {
        $part = $this->part($this->records([]));

        self::assertSame('product', $part->getCode());
        self::assertSame('Product', $part->getLabel());
        self::assertSame('42', $part->entityId($this->model(['item_id' => 7, 'product_id' => 42])));
        self::assertNull($part->entityId($this->model(['item_id' => 7])), 'A part naming no entity has no id');
    }

    public function testThePartIsNamedAsItsEntityIs(): void
    {
        $product = $this->model(['entity_id' => 42, 'sku' => '24-MB01', 'name' => 'Joust Duffle Bag']);
        $part = $this->part($this->records(['42' => $product]));

        self::assertSame(
            'Product 24-MB01 · Joust Duffle Bag',
            $part->describe($this->model(['item_id' => 7, 'product_id' => 42]))
        );
    }

    public function testAnEntityNoLongerStoredIsNamedByItsId(): void
    {
        $part = $this->part($this->records([]));

        self::assertSame('Product #42', $part->describe($this->model(['item_id' => 7, 'product_id' => 42])));
        self::assertSame('Product', $part->describe($this->model(['item_id' => 7])));
    }

    public function testOnlyThePartsOwnModelIsSupportedAndItsDeclaredFieldsCompared(): void
    {
        $part = $this->part($this->records([]));

        self::assertTrue($part->supports($this->stockItem([])));
        self::assertFalse($part->supports($this->model([])), 'The entity\'s own model is not its part');
        self::assertSame(
            ['qty' => '95', 'is_in_stock' => '0'],
            $part->fields(
                $this->stockItem([]),
                ['qty' => '95', 'is_in_stock' => '0', 'low_stock_date' => '2026-10-22 10:00:00', 'item_id' => 7]
            )
        );
    }

    public function testAPartSavedForTheFirstTimeHadNoValuesRatherThanNoEntity(): void
    {
        $part = $this->part($this->records([]));

        self::assertSame([], $part->stateBefore($this->stockItem([])), 'A new part changes an entity that exists');
        self::assertSame(
            ['item_id' => '7', 'qty' => '100.0000', 'is_in_stock' => '1', 'low_stock_date' => null],
            $part->stateBefore($this->stockItem(['item_id' => 7])),
            'A stored part is compared with its stored row, not the original data the model kept'
        );
    }

    public function testThePartIsKeptByItsModelsClass(): void
    {
        self::assertSame(StockItem::class, $this->part($this->records([]))->getModelClass());
    }

    public function testAPartReadingItsStoredStateIsComparedAfterItsChangeWithWhatIsStoredToo(): void
    {
        $part = $this->part($this->records([]));

        self::assertSame(
            ['item_id' => '7', 'qty' => '100.0000', 'is_in_stock' => '1', 'low_stock_date' => null],
            $part->stateAfter($this->stockItem(['item_id' => 7, 'qty' => 95])),
            'The model holds 95, which a rolled-back transaction never wrote'
        );
        self::assertSame([], $part->stateAfter($this->stockItem(['item_id' => 8, 'qty' => 5])), 'No row, no state');
    }

    public function testAPartReadingItsModelIsComparedAfterItsChangeWithTheModelsData(): void
    {
        $part = new TrackedPart($this->product(), $this->stockItemModel(false), $this->records([]), 'product_id');

        self::assertSame(
            ['item_id' => 7, 'qty' => 95],
            $part->stateAfter($this->stockItem(['item_id' => 7, 'qty' => 95]))
        );
    }

    public function testAPartMustNameTheFieldHoldingItsEntitysId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrackedPart($this->product(), $this->stockItemModel(), $this->records([]), ' ');
    }

    /**
     * @param StoredRecordsInterface $records
     * @return TrackedPart
     */
    private function part(StoredRecordsInterface $records): TrackedPart
    {
        return new TrackedPart($this->product(), $this->stockItemModel(), $records, 'product_id');
    }

    /**
     * @return TrackedEntity
     */
    private function product(): TrackedEntity
    {
        return new TrackedEntity(
            $this->createStub(EntityStorageInterface::class),
            $this->createStub(ScopeResolverInterface::class),
            $this->createStub(FieldSourceInterface::class),
            $this->createStub(SanitizerInterface::class),
            'product',
            'Product',
            AbstractModel::class,
            labelPattern: '%1$s %2$s · %3$s',
            labelFields: ['sku', 'name']
        );
    }

    /**
     * How the stock item is read: its stored row, its declared fields, a scope
     *
     * @param bool $readsStoredState
     * @return TrackedEntity
     */
    private function stockItemModel(bool $readsStoredState = true): TrackedEntity
    {
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('isField')->willReturnCallback(static fn (string $field): bool => $field !== 'item_id');
        $storage->method('load')->willReturnCallback(
            static fn (AbstractModel $model, string $id): ?array => $id === '7'
                ? ['item_id' => '7', 'qty' => '100.0000', 'is_in_stock' => '1', 'low_stock_date' => null]
                : null
        );
        $scopes = $this->createStub(ScopeResolverInterface::class);
        $scopes->method('resolve')->willReturn($this->createStub(EntityScopeInterface::class));

        return new TrackedEntity(
            $storage,
            $scopes,
            new StoredFieldSource(),
            $this->createStub(SanitizerInterface::class),
            'product_stock_item',
            'Stock Item',
            StockItem::class,
            onlyFields: ['qty', 'is_in_stock'],
            readsStoredState: $readsStoredState
        );
    }

    /**
     * @param array<int|string, AbstractModel> $byId
     * @return StoredRecordsInterface
     */
    private function records(array $byId): StoredRecordsInterface
    {
        $records = $this->createStub(StoredRecordsInterface::class);
        $records->method('record')->willReturnCallback(static fn (string $id): ?AbstractModel => $byId[$id] ?? null);
        $records->method('records')->willReturnCallback(static function (array $ids) use ($byId): array {
            /** @var list<string> $ids */
            return array_intersect_key($byId, array_flip($ids));
        });

        return $records;
    }

    /**
     * @param array<string, mixed> $data
     * @return AbstractModel
     */
    private function model(array $data): AbstractModel
    {
        $model = $this->getMockBuilder(AbstractModel::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $model->setData($data);

        return $model;
    }

    /**
     * @param array<string, mixed> $data
     * @return StockItem
     */
    private function stockItem(array $data): StockItem
    {
        $model = $this->getMockBuilder(StockItem::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $model->setIdFieldName('item_id');
        $model->setData($data);
        $model->setOrigData('qty', '1.0000');

        return $model;
    }
}
