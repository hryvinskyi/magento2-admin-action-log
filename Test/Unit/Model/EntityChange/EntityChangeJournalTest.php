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
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeBuffer;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeWriter;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeJournal;
use Hryvinskyi\AdminActionLog\Model\EntityChange\RecordingRules;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueNormalizer;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A saved model's change, taken before the save and journaled at commit, as the writer receives it
 */
class EntityChangeJournalTest extends TestCase
{
    /**
     * What the writer was given
     *
     * @var list<array{
     *     code: string,
     *     id: string|null,
     *     operation: ChangeOperation,
     *     before: array<string, string|null>,
     *     after: array<string, string|null>
     * }>
     */
    private array $written = [];

    public function testAPartReportingNoStateBeforeChangesItsEntityFromNothingAndNeverCreatesIt(): void
    {
        $journal = $this->journal($this->part(null, ['qty' => '5', 'is_in_stock' => '1']));
        $stockItem = $this->model(StockItem::class);

        $journal->beforeSave($stockItem);
        $journal->committed($stockItem);

        self::assertCount(1, $this->written);
        self::assertSame(ChangeOperation::Update, $this->written[0]['operation'], 'The product exists already');
        self::assertSame(['product', '42'], [$this->written[0]['code'], $this->written[0]['id']]);
        self::assertSame([], $this->written[0]['before'], 'Set from nothing');
    }

    public function testAPartsStateAfterIsTheOneThePartTells(): void
    {
        $journal = $this->journal($this->part(['qty' => '100'], ['qty' => '100']));
        $stockItem = $this->model(StockItem::class);
        $stockItem->setData('qty', '95');

        $journal->beforeSave($stockItem);
        $journal->committed($stockItem);

        self::assertSame(['qty' => '100'], $this->written[0]['before']);
        self::assertSame(
            ['qty' => '100'],
            $this->written[0]['after'],
            'The part read the row as stored, not the 95 its model holds'
        );
    }

    public function testAnEntityWithoutAStateBeforeIsCreated(): void
    {
        $journal = $this->journal($this->part(null, []));
        $product = $this->model(Product::class);
        $product->setData('sku', '24-MB01');

        $journal->beforeSave($product);
        $journal->committed($product);

        self::assertSame(ChangeOperation::Create, $this->written[0]['operation']);
        self::assertSame(['sku' => '24-MB01'], $this->written[0]['after']);
    }

    /**
     * The journal over a pool holding a product and the given part of it
     *
     * @param TrackedEntityPartInterface $part
     * @return EntityChangeJournal
     */
    private function journal(TrackedEntityPartInterface $part): EntityChangeJournal
    {
        $product = $this->createStub(TrackedEntityInterface::class);
        $product->method('getCode')->willReturn('product');
        $product->method('supports')->willReturnCallback(
            static fn (AbstractModel $model): bool => $model instanceof Product
        );
        $product->method('stateBefore')->willReturn(null);
        $product->method('fields')->willReturnCallback(
            static fn (AbstractModel $model, array $data): array => array_intersect_key($data, ['sku' => true])
        );
        $product->method('scope')->willReturn($this->createStub(EntityScopeInterface::class));

        $rules = $this->createStub(RecordingRules::class);
        $rules->method('isOn')->willReturn(true);
        $rules->method('records')->willReturn(true);

        $writer = $this->createMock(ChangeWriter::class);
        $writer->method('write')->willReturnCallback(
            function (
                TrackedEntityInterface $entity,
                ?string $entityId,
                ChangeOperation $operation,
                array $before,
                array $after
            ): void {
                /** @var array<string, string|null> $before */
                /** @var array<string, string|null> $after */
                $this->written[] = [
                    'code' => $entity->getCode(),
                    'id' => $entityId,
                    'operation' => $operation,
                    'before' => $before,
                    'after' => $after,
                ];
            }
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        return new EntityChangeJournal(
            $rules,
            new TrackedEntityPool(
                $this->createStub(SanitizerInterface::class),
                ['product' => $product],
                ['product_stock_item' => $part]
            ),
            new ChangeBuffer(),
            new ValueNormalizer(),
            $writer,
            $logger
        );
    }

    /**
     * A product's stock item part with the states it reports
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed> $after
     * @return TrackedEntityPartInterface
     */
    private function part(?array $before, array $after): TrackedEntityPartInterface
    {
        $part = $this->createStub(TrackedEntityPartInterface::class);
        $part->method('getCode')->willReturn('product');
        $part->method('getModelClass')->willReturn(StockItem::class);
        $part->method('supports')->willReturnCallback(
            static fn (AbstractModel $model): bool => $model instanceof StockItem
        );
        $part->method('stateBefore')->willReturn($before);
        $part->method('stateAfter')->willReturn($after);
        $part->method('fields')->willReturnCallback(static fn (AbstractModel $model, array $data): array => $data);
        $part->method('entityId')->willReturn('42');
        $part->method('scope')->willReturn($this->createStub(EntityScopeInterface::class));

        return $part;
    }

    /**
     * A model of the class, with no behaviour of its own beyond holding data
     *
     * @template T of AbstractModel
     * @param class-string<T> $class
     * @return T
     */
    private function model(string $class): AbstractModel
    {
        return $this->getMockBuilder($class)->disableOriginalConstructor()->onlyMethods([])->getMock();
    }
}
