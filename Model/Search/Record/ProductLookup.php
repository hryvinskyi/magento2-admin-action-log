<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\DB\Helper as DbHelper;

/**
 * Products by SKU, typed in full, or by part of their name
 */
class ProductLookup implements RecordLookupInterface
{
    /**
     * @param CollectionFactory $collectionFactory
     * @param DbHelper $dbHelper
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly DbHelper $dbHelper
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exact(string $text): array
    {
        return $this->strings(
            $this->collectionFactory->create()->addAttributeToFilter('sku', ['eq' => $text])->getAllIds()
        );
    }

    /**
     * @inheritDoc
     */
    public function named(string $text, int $limit): array
    {
        return $this->strings(
            $this->collectionFactory->create()
                ->addAttributeToFilter(
                    'name',
                    ['like' => $this->dbHelper->escapeLikeValue($text, ['position' => 'any'])]
                )
                ->getAllIds($limit)
        );
    }

    /**
     * Ids as text
     *
     * @param array<mixed> $ids
     * @return list<string>
     */
    private function strings(array $ids): array
    {
        return array_values(array_map('strval', array_filter($ids, 'is_scalar')));
    }
}
