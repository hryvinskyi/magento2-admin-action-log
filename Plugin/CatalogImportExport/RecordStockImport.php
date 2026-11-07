<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\CatalogImportExport;

use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\StockRowChanges;
use Magento\CatalogImportExport\Model\StockItemImporterInterface;

/**
 * Records the stock a product import writes, as changes of the products
 *
 * The import writes the stock item rows of a whole batch of products with one query, past the stock item model, so
 * no saved stock item reports the change. The rows of the batch's products are read before and after that query, and
 * each product whose row changed is recorded through the stock item part of the tracked entity pool, the way a saved
 * stock item is. Where a product's stock is recorded from another source too, the part as wired leaves those fields
 * out of the stock item's change, so the import records them once, from that source.
 *
 * Only the rows of the products in the batch are read, and only around the import's own write. What other plugins of
 * the importer write after it is not part of that change: where they derive a composite product's stock status from
 * the imported children, they save the parent's stock item through the stock item repository, and that save is
 * recorded the way any saved stock item is, by the model's own save. A parent inside the batch can therefore show two
 * changes of its stock status, the imported one and then the derived one. Reading and recording never fail the import.
 */
class RecordStockImport
{
    private const PRODUCT_ID = 'product_id';

    /**
     * @param StockRowChanges $changes
     */
    public function __construct(
        private readonly StockRowChanges $changes
    ) {
    }

    /**
     * Import the stock, and record how each product's changed
     *
     * @param StockItemImporterInterface $subject
     * @param callable $proceed
     * @phpstan-param callable(array<mixed>): mixed $proceed
     * @param array<mixed> $stockData The stock rows of the batch, keyed by sku
     * @return mixed
     */
    public function aroundImport(StockItemImporterInterface $subject, callable $proceed, array $stockData): mixed
    {
        $productIds = $this->productIds($stockData);
        $before = $this->changes->rows($productIds);
        $result = $proceed($stockData);
        if ($before !== null) {
            $this->changes->recordSince($productIds, $before);
        }

        return $result;
    }

    /**
     * The ids of the products the batch writes stock for
     *
     * @param array<mixed> $stockData
     * @return list<int>
     */
    private function productIds(array $stockData): array
    {
        $ids = [];
        foreach ($stockData as $row) {
            $id = is_array($row) ? ($row[self::PRODUCT_ID] ?? null) : null;
            if (is_numeric($id) && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }
}
