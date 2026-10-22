<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * Products' current SKUs, in one collection read
 */
class ProductSkus implements RecordKeysInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function keysOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $skus = [];
        foreach ($this->collectionFactory->create()->addIdFilter($ids)->getItems() as $product) {
            $sku = $product->getData('sku');
            $id = $product->getData('entity_id');
            if (is_scalar($sku) && trim((string) $sku) !== '' && is_scalar($id)) {
                $skus[(string) $id] = trim((string) $sku);
            }
        }

        return $skus;
    }
}
