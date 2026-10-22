<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\CatalogInventory;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Magento\CatalogInventory\Model\ResourceModel\Stock;
use Psr\Log\LoggerInterface;

/**
 * Records the quantities a sale, a cancelled sale or a return took from or put back into products' stock
 *
 * The stock corrects quantities with one query, past the stock item save, when a store keeps its stock without
 * sources: an order placed takes its quantities, a cancellation or a credit memo puts them back. The quantities are
 * read before and after the correction, and each product whose quantity moved is recorded as a change of the
 * product, for whoever placed, cancelled or refunded. Reading and recording never fail the correction.
 */
class RecordStockCorrection
{
    private const PRODUCT = 'product';
    private const QTY = 'qty';

    /**
     * @param EntityChangeRecorderInterface $recorder
     * @param EntityChangeInterfaceFactory $changeFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly EntityChangeRecorderInterface $recorder,
        private readonly EntityChangeInterfaceFactory $changeFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Correct the quantities, and record how each product's moved
     *
     * @param Stock $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>, mixed, mixed): mixed $proceed
     * @param array<mixed> $items Quantities keyed by product id
     * @param mixed $websiteId
     * @param mixed $operator
     * @return mixed
     */
    public function aroundCorrectItemsQty(Stock $subject, callable $proceed, array $items, $websiteId, $operator): mixed
    {
        $productIds = array_values(array_filter(array_keys($items), 'is_int'));
        $before = $this->quantities($subject, $productIds, $websiteId);
        $result = $proceed($items, $websiteId, $operator);
        if ($before === null) {
            return $result;
        }

        try {
            $this->recorder->recordAll($this->changes($before, $this->quantities($subject, $productIds, $websiteId)));
        } catch (\Throwable $exception) {
            $this->logger->error('The correction of products\' stock could not be recorded.', [
                'exception' => $exception,
            ]);
        }

        return $result;
    }

    /**
     * The stored quantity of each product, keyed by product id; null when it could not be read
     *
     * @param Stock $stock
     * @param list<int> $productIds
     * @param mixed $websiteId
     * @return array<int,string|null>|null
     */
    private function quantities(Stock $stock, array $productIds, mixed $websiteId): ?array
    {
        if ($productIds === []) {
            return null;
        }

        try {
            $connection = $stock->getConnection();
            if ($connection === false) {
                return null;
            }

            $quantities = [];
            foreach ($connection->fetchPairs(
                $connection->select()
                    ->from($stock->getTable('cataloginventory_stock_item'), ['product_id', self::QTY])
                    ->where('product_id IN (?)', $productIds)
                    ->where('website_id = ?', is_numeric($websiteId) ? (int) $websiteId : 0)
            ) as $productId => $qty) {
                $quantities[(int) $productId] = is_scalar($qty) ? (string) $qty : null;
            }

            return $quantities;
        } catch (\Throwable $exception) {
            $this->logger->error('The stock of products could not be read around a correction.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * A change of each product whose quantity moved
     *
     * @param array<int,string|null> $before
     * @param array<int,string|null>|null $after
     * @return list<EntityChangeInterface>
     */
    private function changes(array $before, ?array $after): array
    {
        $changes = [];
        foreach ($before as $productId => $quantity) {
            $now = $after[$productId] ?? null;
            if ($after !== null && $now !== $quantity) {
                $changes[] = $this->changeFactory->create()
                    ->withEntity(self::PRODUCT, (string) $productId)
                    ->withField(self::QTY, $quantity, $now);
            }
        }

        return $changes;
    }
}
