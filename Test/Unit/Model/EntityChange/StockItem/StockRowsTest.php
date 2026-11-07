<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\StockItem;

use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\StockRows;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item as StockItemResource;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Stock item rows are read through the connection the write went through when it is named, and through the stock
 * item resource's own connection otherwise
 */
class StockRowsTest extends TestCase
{
    private const ROW = ['item_id' => '7', 'product_id' => '42', 'stock_id' => '1', 'qty' => '98.0000'];

    public function testTheRowsAreReadThroughTheConnectionTheWriteWentThrough(): void
    {
        $writeConnection = $this->connection([self::ROW]);
        $ownConnection = $this->connection([]);
        $ownConnection->expects(self::never())->method('fetchAll');

        $rows = (new StockRows($this->resource($ownConnection)))->read([42], $writeConnection);

        self::assertSame(['42/1' => self::ROW], $rows);
    }

    public function testWithoutAConnectionNamedTheStockItemResourcesOwnIsUsed(): void
    {
        $rows = (new StockRows($this->resource($this->connection([self::ROW]))))->read([42]);

        self::assertSame(['42/1' => self::ROW], $rows);
    }

    public function testAResourceWithoutAConnectionFailsLoudly(): void
    {
        $this->expectException(\RuntimeException::class);

        (new StockRows($this->resource(false)))->read([42]);
    }

    public function testNoProductsReadsNothing(): void
    {
        $connection = $this->connection([]);
        $connection->expects(self::never())->method('fetchAll');

        self::assertSame([], (new StockRows($this->resource($connection)))->read([], $connection));
    }

    /**
     * A connection that answers a stock item select with these rows
     *
     * @param list<array<string, string>> $rows
     * @return AdapterInterface&MockObject
     */
    private function connection(array $rows): AdapterInterface&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);

        return $connection;
    }

    /**
     * The stock item resource, with this connection of its own
     *
     * @param AdapterInterface|false $connection
     * @return StockItemResource
     */
    private function resource(AdapterInterface|false $connection): StockItemResource
    {
        $resource = $this->createMock(StockItemResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('cataloginventory_stock_item');

        return $resource;
    }
}
