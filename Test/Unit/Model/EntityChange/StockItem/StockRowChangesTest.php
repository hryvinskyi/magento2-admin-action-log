<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\StockItem;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Hryvinskyi\AdminActionLog\Model\Data\EntityChange;
use Hryvinskyi\AdminActionLog\Model\Data\EntityScope;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityReadAheadInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\RecordingRules;
use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\CorrectedRows;
use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\StockRowChanges;
use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\StockRows;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueNormalizer;
use Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\Fake\RecordedReadAhead;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Stock item rows written past the stock item model are recorded through the stock item part of the pool: only the
 * rows the write changed, with the fields the part tells, after the read-aheads were told about those products
 */
class StockRowChangesTest extends TestCase
{
    private const ROW_42 = [
        'item_id' => '7', 'product_id' => '42', 'stock_id' => '1', 'qty' => '100.0000', 'min_qty' => '0.0000',
        'low_stock_date' => null,
    ];
    private const ROW_43 = [
        'item_id' => '8', 'product_id' => '43', 'stock_id' => '1', 'qty' => '5.0000', 'min_qty' => '0.0000',
        'low_stock_date' => null,
    ];

    /**
     * @var list<EntityChangeInterface>
     */
    private array $recorded = [];

    /**
     * @var list<string>
     */
    private array $logged = [];

    /**
     * The products whose fields the part was asked for, in order
     *
     * @var list<string>
     */
    private array $toldFields = [];

    /**
     * The connection each read was asked to go through, null for the stock item resource's own
     *
     * @var list<AdapterInterface|null>
     */
    private array $readThrough = [];

    /**
     * How often the rows were read
     *
     * @var int
     */
    private int $reads = 0;

    /**
     * @var RecordedReadAhead
     */
    private RecordedReadAhead $readAhead;

    protected function setUp(): void
    {
        $this->readAhead = new RecordedReadAhead();
    }

    public function testOnlyTheRowsTheWriteChangedAreRecordedWithTheFieldsThePartTells(): void
    {
        $changes = $this->changes([
            ['42/1' => self::ROW_42, '43/1' => self::ROW_43],
            [
                '42/1' => ['qty' => '95.0000', 'low_stock_date' => '2026-11-07 10:00:00'] + self::ROW_42,
                '43/1' => self::ROW_43,
            ],
        ]);

        $before = $changes->rows([42, 43]);
        self::assertNotNull($before);
        $changes->recordSince([42, 43], $before);

        self::assertCount(1, $this->recorded, 'The unchanged product records nothing');
        $change = $this->recorded[0];
        self::assertSame(['product', '42'], [$change->getEntityCode(), $change->getEntityId()]);
        self::assertSame(EntityChangeInterface::OPERATION_UPDATE, $change->getOperation());
        self::assertSame('All Store Views', $change->getScope()->getLabel());
        self::assertSame(['qty' => '100.0000', 'min_qty' => '0.0000'], $change->getBefore());
        self::assertSame(['qty' => '95.0000', 'min_qty' => '0.0000'], $change->getAfter(), 'Only the part\'s fields');
        self::assertSame([['42']], $this->readAhead->announced, 'Once, for the changed products only');
        self::assertSame(2, $this->reads, 'One read before the write, one after');
    }

    public function testAFailedReadAheadStillRecordsTheWholeWriteOneProductAtATime(): void
    {
        $failing = $this->createStub(TrackedEntityReadAheadInterface::class);
        $failing->method('readAhead')->willThrowException(new \RuntimeException('Lost connection to the database'));
        $changes = $this->changes(
            [
                ['42/1' => self::ROW_42, '43/1' => self::ROW_43],
                ['42/1' => ['qty' => '95.0000'] + self::ROW_42, '43/1' => ['qty' => '4.0000'] + self::ROW_43],
            ],
            readAhead: $failing
        );

        $before = $changes->rows([42, 43]);
        self::assertNotNull($before);
        $changes->recordSince([42, 43], $before);

        self::assertSame(
            [['product', '42'], ['product', '43']],
            array_map(
                static fn (EntityChangeInterface $change): array => [$change->getEntityCode(), $change->getEntityId()],
                $this->recorded
            ),
            'Every product of the write is recorded'
        );
        self::assertSame(['42', '42', '43', '43'], $this->toldFields, 'Each product told on its own, both sides');
        self::assertSame([], $this->logged, 'The pool logs the read-ahead\'s failure, the write records as usual');
    }

    public function testACorrectionIsRecordedFromTheRowsItLeftWithTheRowsBeforeToldFromItsQuantities(): void
    {
        $after = [
            '42/1' => ['qty' => '97.0000', 'website_id' => '0'] + self::ROW_42,
            '43/1' => ['website_id' => '0'] + self::ROW_43,
        ];
        $changes = $this->changes([$after]);

        $read = $changes->rows([42, 43]);
        self::assertSame($after, $read);
        $changes->recordCorrection($read, [42 => 3], 0, '-');

        self::assertCount(1, $this->recorded, 'The product the correction does not list records nothing');
        self::assertSame(['qty' => '100.0000', 'min_qty' => '0.0000'], $this->recorded[0]->getBefore());
        self::assertSame(['qty' => '97.0000', 'min_qty' => '0.0000'], $this->recorded[0]->getAfter());
        self::assertSame(1, $this->reads, 'Read once, after the correction');
    }

    public function testRowsAreReadThroughTheConnectionTheCallerNames(): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $changes = $this->changes([[], []]);

        $changes->rows([42], $connection);
        $changes->rows([42]);

        self::assertSame([$connection, null], $this->readThrough);
    }

    public function testACorrectionWithAnUnknownOperatorRecordsNothing(): void
    {
        $changes = $this->changes([]);

        $changes->recordCorrection(['42/1' => ['website_id' => '0'] + self::ROW_42], [42 => 3], 0, '*');

        self::assertSame([], $this->recorded);
    }

    public function testWhetherStockIsRecordedFollowsTheRules(): void
    {
        self::assertTrue($this->changes([])->isRecorded());
        self::assertFalse($this->changes([], records: false)->isRecorded());
    }

    public function testARowWrittenForTheFirstTimeIsSetFromNothing(): void
    {
        $changes = $this->changes([[], ['42/1' => self::ROW_42]]);

        $before = $changes->rows([42]);
        self::assertSame([], $before);
        $changes->recordSince([42], $before);

        self::assertCount(1, $this->recorded);
        self::assertSame(EntityChangeInterface::OPERATION_UPDATE, $this->recorded[0]->getOperation());
        self::assertSame(['qty' => null, 'min_qty' => null], $this->recorded[0]->getBefore());
        self::assertSame(['qty' => '100.0000', 'min_qty' => '0.0000'], $this->recorded[0]->getAfter());
    }

    public function testNothingIsReadWhileTheProductsChangesAreNotRecorded(): void
    {
        $changes = $this->changes([[], []], records: false);

        self::assertNull($changes->rows([42]));
        $changes->recordSince([42], []);

        self::assertSame(0, $this->reads);
        self::assertSame([], $this->recorded);
    }

    public function testAWriteOfNoProductReadsNothing(): void
    {
        self::assertNull($this->changes([[], []])->rows([]));
        self::assertSame(0, $this->reads);
    }

    public function testRowsThatCannotBeReadAreLoggedAndRecordNothing(): void
    {
        $changes = $this->changes([new \RuntimeException('gone away')]);

        self::assertNull($changes->rows([42]));
        self::assertSame(['The stock of products could not be read.'], $this->logged);
    }

    public function testAFailureToRecordIsLoggedAndNeverReachesTheWrite(): void
    {
        $changes = $this->changes([['42/1' => self::ROW_42], new \RuntimeException('gone away')]);

        $before = $changes->rows([42]);
        self::assertNotNull($before);
        $changes->recordSince([42], $before);

        self::assertSame(['The change of products\' stock could not be recorded.'], $this->logged);
    }

    /**
     * The service under test, reading these results in turn
     *
     * @param list<array<string, array<string, mixed>>|\Throwable> $reads
     * @param bool $records Whether the product's changes are recorded now
     * @param TrackedEntityReadAheadInterface|null $readAhead The product's read-ahead; the recording one when null
     * @return StockRowChanges
     */
    private function changes(
        array $reads,
        bool $records = true,
        ?TrackedEntityReadAheadInterface $readAhead = null
    ): StockRowChanges {
        $rows = $this->createMock(StockRows::class);
        $read = function (array $ids, ?AdapterInterface $connection = null) use (&$reads): array {
            $this->reads++;
            $this->readThrough[] = $connection;
            $next = array_shift($reads) ?? [];
            if ($next instanceof \Throwable) {
                throw $next;
            }

            return $next;
        };
        $rows->method('read')->willReturnCallback($read);

        $rules = $this->createMock(RecordingRules::class);
        $rules->method('isOn')->willReturn(true);
        $rules->method('records')->willReturn($records);

        $stockItems = $this->createMock(StockItemInterfaceFactory::class);
        $stockItems->method('create')->willReturnCallback(
            fn (): StockItem => $this->createPartialMock(StockItem::class, [])
        );

        $changeFactory = $this->createMock(EntityChangeInterfaceFactory::class);
        $changeFactory->method('create')->willReturnCallback(
            static fn (): EntityChangeInterface => new EntityChange(new EntityScope())
        );

        $recorder = $this->createMock(EntityChangeRecorderInterface::class);
        $recorder->method('recordAll')->willReturnCallback(function (array $changes): void {
            /** @var list<EntityChangeInterface> $changes */
            array_push($this->recorded, ...$changes);
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string|\Stringable $message): void {
            $this->logged[] = (string) $message;
        });

        $product = $this->createStub(TrackedEntityInterface::class);
        $product->method('getCode')->willReturn('product');

        return new StockRowChanges(
            new TrackedEntityPool(
                $this->createStub(SanitizerInterface::class),
                $this->createStub(LoggerInterface::class),
                ['product' => $product],
                ['product_stock_item' => $this->part()],
                [],
                ['product' => ['stock' => $readAhead ?? $this->readAhead]]
            ),
            $rules,
            $rows,
            new CorrectedRows(),
            $stockItems,
            new ValueNormalizer(),
            $changeFactory,
            $recorder,
            $logger
        );
    }

    /**
     * A stock item part comparing the quantity and the out-of-stock threshold
     *
     * @return TrackedEntityPartInterface
     */
    private function part(): TrackedEntityPartInterface
    {
        $part = $this->createStub(TrackedEntityPartInterface::class);
        $part->method('getCode')->willReturn('product');
        $part->method('getModelClass')->willReturn(StockItem::class);
        $part->method('supports')->willReturnCallback(
            static fn (AbstractModel $model): bool => $model instanceof StockItem
        );
        $part->method('fields')->willReturnCallback(
            function (AbstractModel $model, array $data): array {
                $productId = $model->getData('product_id');
                $this->toldFields[] = is_string($productId) ? $productId : '';

                return array_intersect_key($data, ['qty' => true, 'min_qty' => true]);
            }
        );
        $part->method('scope')->willReturn(new EntityScope('default', 0, 'All Store Views'));
        $part->method('entityId')->willReturnCallback(static function (AbstractModel $model): ?string {
            $id = $model->getData('product_id');

            return is_string($id) ? $id : null;
        });

        return $part;
    }
}
