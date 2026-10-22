<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

use Magento\Customer\Model\ResourceModel\Customer as CustomerResource;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Helper as DbHelper;
use Magento\Framework\DB\Select;

/**
 * Customers by email, typed in full, or by part of their full name ("Jane Doe")
 *
 * Email, first and last name are columns of the customer entity table itself, so no attribute join is needed.
 */
class CustomerLookup implements RecordLookupInterface
{
    /**
     * @param CustomerResource $resource
     * @param DbHelper $dbHelper
     */
    public function __construct(
        private readonly CustomerResource $resource,
        private readonly DbHelper $dbHelper
    ) {
    }

    /**
     * @inheritDoc
     */
    public function exact(string $text): array
    {
        return $this->ids($this->select()->where('email = ?', $text));
    }

    /**
     * @inheritDoc
     */
    public function named(string $text, int $limit): array
    {
        return $this->ids(
            $this->select()
                ->where(
                    $this->connection()->quoteInto(
                        'CONCAT_WS(\' \', firstname, lastname) LIKE ?',
                        $this->dbHelper->escapeLikeValue($text, ['position' => 'any'])
                    ),
                    null,
                    Select::TYPE_CONDITION
                )
                ->limit($limit)
        );
    }

    /**
     * The ids a query selects, as text
     *
     * @param Select $select
     * @return list<string>
     */
    private function ids(Select $select): array
    {
        $ids = [];
        foreach ($this->connection()->fetchCol($select) as $id) {
            if (is_scalar($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * Customer ids, unfiltered
     *
     * @return Select
     */
    private function select(): Select
    {
        return $this->connection()->select()->from(
            $this->resource->getEntityTable(),
            [$this->resource->getEntityIdField()]
        );
    }

    /**
     * The customer connection
     *
     * @return AdapterInterface
     */
    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
