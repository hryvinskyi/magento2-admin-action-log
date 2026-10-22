<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use Magento\Framework\DB\Helper as DbHelper;

/**
 * Categories by part of their name; a category has no natural key besides its id
 */
class CategoryLookup implements RecordLookupInterface
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
        return [];
    }

    /**
     * @inheritDoc
     */
    public function named(string $text, int $limit): array
    {
        $ids = $this->collectionFactory->create()
            ->addAttributeToFilter('name', ['like' => $this->dbHelper->escapeLikeValue($text, ['position' => 'any'])])
            ->getAllIds($limit);

        return array_values(array_map('strval', array_filter($ids, 'is_scalar')));
    }
}
