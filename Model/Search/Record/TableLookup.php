<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Helper as DbHelper;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Records of a flat table, through the resource model that owns it
 *
 * "exactColumns" hold natural keys compared whole (an identifier, an order number); "nameColumns" are joined by spaces
 * and searched for the text anywhere (a title, a rule name). Either may be empty when the kind has no such column.
 */
class TableLookup implements RecordLookupInterface
{
    /**
     * @var list<string>
     */
    private readonly array $exactColumns;

    /**
     * @var list<string>
     */
    private readonly array $nameColumns;

    /**
     * @param AbstractDb $resource
     * @param DbHelper $dbHelper
     * @param array<string> $exactColumns
     * @param array<string> $nameColumns
     */
    public function __construct(
        private readonly AbstractDb $resource,
        private readonly DbHelper $dbHelper,
        array $exactColumns = [],
        array $nameColumns = []
    ) {
        $this->exactColumns = array_values($exactColumns);
        $this->nameColumns = array_values($nameColumns);
    }

    /**
     * @inheritDoc
     */
    public function exact(string $text): array
    {
        if ($this->exactColumns === []) {
            return [];
        }

        $connection = $this->connection();
        $matches = array_map(
            static fn (string $column): string => $connection->quoteInto(
                $connection->quoteIdentifier($column) . ' = ?',
                $text
            ),
            $this->exactColumns
        );

        return $this->ids($this->select()->where(implode(' OR ', $matches), null, Select::TYPE_CONDITION));
    }

    /**
     * @inheritDoc
     */
    public function named(string $text, int $limit): array
    {
        if ($this->nameColumns === []) {
            return [];
        }

        $connection = $this->connection();
        $name = 'CONCAT_WS(\' \', ' . implode(', ', array_map(
            static fn (string $column): string => $connection->quoteIdentifier($column),
            $this->nameColumns
        )) . ')';

        return $this->ids(
            $this->select()
                ->where(
                    $connection->quoteInto(
                        $name . ' LIKE ?',
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
     * The table's ids, unfiltered
     *
     * @return Select
     */
    private function select(): Select
    {
        return $this->connection()->select()->from(
            $this->resource->getMainTable(),
            [$this->resource->getIdFieldName()]
        );
    }

    /**
     * The table's connection
     *
     * @return AdapterInterface
     * @throws \LogicException When the resource model has none
     */
    private function connection(): AdapterInterface
    {
        $connection = $this->resource->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \LogicException(sprintf('%s has no database connection.', $this->resource::class));
        }

        return $connection;
    }
}
