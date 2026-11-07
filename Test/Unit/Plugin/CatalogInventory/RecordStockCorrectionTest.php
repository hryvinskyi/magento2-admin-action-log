<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Plugin\CatalogInventory;

use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\StockRowChanges;
use Hryvinskyi\AdminActionLog\Plugin\CatalogInventory\RecordStockCorrection;
use Magento\CatalogInventory\Model\ResourceModel\Stock;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A stock correction is recorded from the stock item rows of the products it moves, read once right after its write
 * and inside a transaction around it, so no other write can fall between the write and the read
 */
class RecordStockCorrectionTest extends TestCase
{
    private const AFTER = [
        '42/1' => ['product_id' => '42', 'stock_id' => '1', 'website_id' => '0', 'qty' => '98.0000'],
    ];

    /**
     * The stock rows read and recorded around the correction
     *
     * @var StockRowChanges&MockObject
     */
    private StockRowChanges $changes;

    /**
     * What happened, in order: the step and what it was given
     *
     * @var list<list<mixed>>
     */
    private array $calls = [];

    /**
     * The transaction level of the stock's connection
     *
     * @var int
     */
    private int $level = 0;

    /**
     * The stock's connection, once the stock is built
     *
     * @var (AdapterInterface&MockObject)|null
     */
    private ?AdapterInterface $connection = null;

    protected function setUp(): void
    {
        $this->changes = $this->createMock(StockRowChanges::class);
        $this->changes->method('isRecorded')->willReturn(true);
    }

    public function testTheRowsAreReadRightAfterTheWriteInsideItsTransactionAndRecordedOnceItIsCommitted(): void
    {
        $this->changes->method('rows')->willReturnCallback(function (array $productIds, mixed $connection): array {
            self::assertSame($this->connection, $connection, 'Read through the stock\'s own connection');
            $this->calls[] = ['read', $productIds];

            return self::AFTER;
        });
        $this->changes->method('recordCorrection')->willReturnCallback(
            function (array $after, array $quantities, int $websiteId, string $operator): void {
                self::assertSame(self::AFTER, $after, 'Recorded from the rows read after the write');
                $this->calls[] = ['record', $quantities, $websiteId, $operator];
            }
        );

        $result = $this->correct([42 => 2, 43 => 1.5], 'corrected');

        self::assertSame('corrected', $result, 'The correction\'s own result');
        self::assertSame(
            [
                ['begin'],
                ['correct', 0, '-'],
                ['read', [42, 43]],
                ['commit'],
                ['record', [42 => 2, 43 => 1.5], 0, '-'],
            ],
            $this->calls
        );
    }

    public function testNothingIsRecordedWhenTheRowsAfterAreNotRead(): void
    {
        $this->changes->method('rows')->willReturn(null);
        $this->changes->expects(self::never())->method('recordCorrection');

        self::assertNull($this->correct([42 => 2], null));
        self::assertSame([['begin'], ['correct', 0, '-'], ['commit']], $this->calls, 'The correction still commits');
    }

    public function testOnlyKeysThatCanBeProductIdsWithAQuantityAreRead(): void
    {
        $this->changes->expects(self::once())->method('rows')->with([42])->willReturn(null);

        $this->correct([42 => 2, 0 => 1, -3 => 1, 'sku' => 1, 44 => 'many'], null);
    }

    public function testACorrectionOfNoProductIsNeitherWrappedNorRead(): void
    {
        $this->changes->expects(self::never())->method('rows');

        $this->correct(['sku' => 1], null);

        self::assertSame([['correct', 0, '-']], $this->calls);
    }

    public function testWhileStockIsNotRecordedTheCorrectionIsNeitherWrappedNorRead(): void
    {
        $changes = $this->createMock(StockRowChanges::class);
        $changes->method('isRecorded')->willReturn(false);
        $changes->expects(self::never())->method('rows');
        $this->changes = $changes;

        $this->correct([42 => 2], null);

        self::assertSame([['correct', 0, '-']], $this->calls);
    }

    public function testAFailedCorrectionRollsBackRecordsNothingAndStillFails(): void
    {
        $this->changes->expects(self::never())->method('rows');
        $this->changes->expects(self::never())->method('recordCorrection');

        $failure = null;
        try {
            $this->plugin()->aroundCorrectItemsQty(
                $this->stock(),
                $this->failingCorrection(...),
                [42 => 2],
                0,
                '-'
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure, 'The correction\'s failure is thrown');
        self::assertSame('The correction failed.', $failure->getMessage());
        self::assertSame([['begin'], ['rollBack']], $this->calls);
    }

    public function testACorrectionThatFailsInsideItsOwnTransactionIsRolledBackToTheLevelBeforeIt(): void
    {
        $this->changes->expects(self::never())->method('recordCorrection');
        $this->level = 1;
        $failure = null;

        try {
            $this->plugin()->aroundCorrectItemsQty(
                $this->stock(),
                $this->failingInnerTransaction(...),
                [42 => 2],
                0,
                '-'
            );
        } catch (\RuntimeException $exception) {
            $failure = $exception;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure, 'The correction\'s failure is thrown');
        self::assertSame(1, $this->level, 'Back to the caller\'s transaction, its own inner one left too');
        self::assertSame([['begin'], ['begin'], ['rollBack'], ['rollBack']], $this->calls);
    }

    /**
     * A correction that opens its own transaction and fails inside it, leaving it open
     *
     * @return never
     * @throws \RuntimeException Always
     */
    private function failingInnerTransaction(): never
    {
        self::assertNotNull($this->connection);
        $this->connection->beginTransaction();

        throw new \RuntimeException('The correction failed inside its own transaction.');
    }

    /**
     * A correction whose write fails
     *
     * @return never
     * @throws \RuntimeException Always
     */
    private function failingCorrection(): never
    {
        throw new \RuntimeException('The correction failed.');
    }

    /**
     * Correct these quantities through the plugin; what the correction returned
     *
     * @param array<mixed> $items
     * @param mixed $result What the correction itself returns
     * @return mixed
     */
    private function correct(array $items, mixed $result): mixed
    {
        return $this->plugin()->aroundCorrectItemsQty(
            $this->stock(),
            function (array $items, mixed $websiteId, mixed $operator) use ($result): mixed {
                $this->calls[] = ['correct', $websiteId, $operator];

                return $result;
            },
            $items,
            0,
            '-'
        );
    }

    /**
     * The stock resource, writing through a connection that tells its transaction's steps
     *
     * @return Stock
     */
    private function stock(): Stock
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $connection = $this->connection;
        $connection->method('getTransactionLevel')->willReturnCallback(fn (): int => $this->level);
        $connection->method('beginTransaction')->willReturnCallback(function () use ($connection): AdapterInterface {
            $this->calls[] = ['begin'];
            $this->level++;

            return $connection;
        });
        foreach (['commit', 'rollBack'] as $step) {
            $connection->method($step)->willReturnCallback(function () use ($connection, $step): AdapterInterface {
                $this->calls[] = [$step];
                $this->level--;

                return $connection;
            });
        }

        $stock = $this->createMock(Stock::class);
        $stock->method('getConnection')->willReturn($connection);

        return $stock;
    }

    /**
     * @return RecordStockCorrection
     */
    private function plugin(): RecordStockCorrection
    {
        return new RecordStockCorrection($this->changes);
    }
}
