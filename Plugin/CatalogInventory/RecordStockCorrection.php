<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\CatalogInventory;

use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\StockRowChanges;
use Magento\CatalogInventory\Model\ResourceModel\Stock;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Records the quantities a direct correction of products' stock took from or put back into them
 *
 * The stock corrects quantities with one query, past the stock item model, for whoever calls it: the classic
 * inventory does for an order placed, cancelled or refunded. Each product whose stock item row the correction moved is
 * recorded through the stock item part of the tracked entity pool, the way a saved stock item is, for whoever made
 * the correction. Where a product's stock is recorded from another source too, the part as wired leaves those fields
 * out of the stock item's change, so the correction is recorded once, from that source.
 *
 * The change is exact however many requests correct the same products at once. The rows are read once, right after
 * the correction's write, inside a transaction around it and through the stock's own connection, the one that
 * transaction is on, so the write's row locks keep every other write out until they are read; the rows before are
 * told from them and the correction's own quantities and operator, never read separately, so no other write can fall
 * between the two states recorded. When the caller already holds a transaction (placing an order locks the products'
 * stock in one), this one is part of it. Nothing is wrapped or read while stock item changes are not recorded.
 * Reading and recording never fail the correction. A correction that fails rolls back every transaction level opened
 * since it was called, its own inner one included, and fails as it did: with no transaction around, its write is
 * rolled back; inside the caller's, that transaction is left marked as rolled back, for the caller to end.
 */
class RecordStockCorrection
{
    /**
     * @param StockRowChanges $changes
     */
    public function __construct(
        private readonly StockRowChanges $changes
    ) {
    }

    /**
     * Correct the quantities, and record how each product's stock moved
     *
     * @param Stock $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>, mixed, mixed): mixed $proceed
     * @param array<mixed> $items Quantities keyed by product id
     * @param mixed $websiteId
     * @param mixed $operator
     * @return mixed
     * @throws \Throwable What the correction throws
     */
    public function aroundCorrectItemsQty(Stock $subject, callable $proceed, array $items, $websiteId, $operator): mixed
    {
        $quantities = $this->quantities($items);
        $connection = $quantities === [] || !is_numeric($websiteId) || !is_string($operator)
            ? null
            : $this->connection($subject);
        if ($connection === null || !$this->changes->isRecorded()) {
            return $proceed($items, $websiteId, $operator);
        }

        $level = $connection->getTransactionLevel();
        $connection->beginTransaction();
        try {
            $result = $proceed($items, $websiteId, $operator);
        } catch (\Throwable $exception) {
            $this->rollBackTo($connection, $level);
            throw $exception;
        }
        $after = $this->changes->rows(array_keys($quantities), $connection);
        $connection->commit();

        if ($after !== null) {
            $this->changes->recordCorrection($after, $quantities, (int) $websiteId, $operator);
        }

        return $result;
    }

    /**
     * Roll the connection back to the transaction level it had before the correction
     *
     * The correction opens a transaction of its own inside this one and leaves it open when its write fails, so one
     * roll-back would only leave that inner level, and the transaction would stay open for the rest of the request.
     * Every level opened since is rolled back: with no transaction around, the database rolls back; inside the
     * caller's, that transaction is left marked as rolled back, for the caller to end.
     *
     * @param AdapterInterface $connection
     * @param int $level
     * @return void
     */
    private function rollBackTo(AdapterInterface $connection, int $level): void
    {
        while ($connection->getTransactionLevel() > $level) {
            $connection->rollBack();
        }
    }

    /**
     * The quantities of the correction, keyed by the ids of the products it moves
     *
     * @param array<mixed> $items
     * @return array<int,float|int|string>
     */
    private function quantities(array $items): array
    {
        $quantities = [];
        foreach ($items as $productId => $quantity) {
            if (is_int($productId) && $productId > 0 && is_numeric($quantity)) {
                $quantities[$productId] = $quantity;
            }
        }

        return $quantities;
    }

    /**
     * The connection the stock writes through, or null when it has none
     *
     * @param Stock $subject
     * @return AdapterInterface|null
     */
    private function connection(Stock $subject): ?AdapterInterface
    {
        $connection = $subject->getConnection();

        return $connection instanceof AdapterInterface ? $connection : null;
    }
}
