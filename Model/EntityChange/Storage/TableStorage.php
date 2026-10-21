<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Storage;

use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * An entity stored as one row of its resource model's main table: its fields are the table's columns
 *
 * The id column is not a field: it names the entity, which the change records as its subject.
 */
class TableStorage implements EntityStorageInterface
{
    /**
     * @var array<string, true>|null
     */
    private ?array $columns = null;

    /**
     * @param AbstractDb $resource
     */
    public function __construct(
        private readonly AbstractDb $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isField(string $field): bool
    {
        return isset($this->columns()[$field]);
    }

    /**
     * @inheritDoc
     */
    public function load(AbstractModel $model, string $id): ?array
    {
        $connection = $this->resource->getConnection();
        if (!$connection) {
            return null;
        }

        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getMainTable())
                ->where($connection->quoteIdentifier($this->resource->getIdFieldName()) . ' = ?', $id)
        );
        if (!is_array($row) || $row === []) {
            return null;
        }

        $data = [];
        foreach ($row as $column => $value) {
            $data[(string) $column] = $value;
        }

        return $data;
    }

    /**
     * The main table columns, read once
     *
     * @return array<string, true>
     */
    private function columns(): array
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $this->columns = [];
        $connection = $this->resource->getConnection();
        if (!$connection) {
            return $this->columns;
        }

        foreach (array_keys($connection->describeTable($this->resource->getMainTable())) as $column) {
            $this->columns[(string) $column] = true;
        }
        unset($this->columns[$this->resource->getIdFieldName()]);

        return $this->columns;
    }
}
