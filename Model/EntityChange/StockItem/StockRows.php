<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem;

use Magento\CatalogInventory\Model\ResourceModel\Stock\Item as StockItemResource;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Products' stock item rows as stored, read in one query
 *
 * Each row is keyed by its product and stock ("42/1"), the pair a stock item row is unique for, and holds every
 * column as the database returns it.
 *
 * The rows are read through the connection the write being recorded went through, when the caller names it (a write
 * inside a transaction must be read inside that same transaction), and otherwise through the stock item resource's
 * own connection, the one the stock item rows are written through. Either way the resource gives the table's name.
 */
class StockRows
{
    private const PRODUCT_ID = 'product_id';
    private const STOCK_ID = 'stock_id';

    /**
     * @param StockItemResource $stockItemResource
     */
    public function __construct(
        private readonly StockItemResource $stockItemResource
    ) {
    }

    /**
     * The stored stock item rows of the products, keyed by product id and stock id
     *
     * @param list<int> $productIds
     * @param AdapterInterface|null $connection The connection the write went through; the stock item resource's when
     *     null
     * @return array<string,array<string,mixed>>
     * @throws LocalizedException When the stock item resource has no table
     * @throws \RuntimeException When no connection is given and the stock item resource has none
     */
    public function read(array $productIds, ?AdapterInterface $connection = null): array
    {
        if ($productIds === []) {
            return [];
        }

        $connection ??= $this->ownConnection();
        $rows = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->stockItemResource->getMainTable())
                ->where(self::PRODUCT_ID . ' IN (?)', $productIds)
        ) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $columns = [];
            foreach ($row as $column => $value) {
                $columns[(string) $column] = $value;
            }

            $rows[$this->key($columns)] = $columns;
        }

        return $rows;
    }

    /**
     * The stock item resource's own connection
     *
     * @return AdapterInterface
     * @throws \RuntimeException When the resource has none
     */
    private function ownConnection(): AdapterInterface
    {
        $connection = $this->stockItemResource->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \RuntimeException('The stock item resource has no database connection.');
        }

        return $connection;
    }

    /**
     * The key of a row: its product id and stock id
     *
     * @param array<string,mixed> $row
     * @return string
     */
    private function key(array $row): string
    {
        $productId = $row[self::PRODUCT_ID] ?? '';
        $stockId = $row[self::STOCK_ID] ?? '';

        return sprintf(
            '%s/%s',
            is_scalar($productId) ? (string) $productId : '',
            is_scalar($stockId) ? (string) $stockId : ''
        );
    }
}
