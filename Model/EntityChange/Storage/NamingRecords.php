<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Storage;

use Magento\Eav\Model\Entity\AbstractEntity;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Records of an EAV entity read only for the fields that name them, many in one query, as stored for all store views
 *
 * A module declares a virtual type of this class with the entity's resource model, its model class and the naming
 * fields ("sku" and "name" for a product). A field kept in the entity's own table comes with the row; any other is
 * joined at the value stored for all store views. Each record is read once per request, a missing one too, so naming
 * the changes of many records costs one query, not a load each. A record read earlier in the request is not read
 * again, even if it was renamed since.
 */
class NamingRecords implements StoredRecordsInterface, ResetAfterRequestInterface
{
    private const ALL_STORE_VIEWS = 0;
    private const STORE_COLUMN = 'store_id';

    /**
     * Records read so far by id; null for an id with no record
     *
     * @var array<string, AbstractModel|null>
     */
    private array $read = [];

    /**
     * @var list<string>
     */
    private readonly array $fields;

    /**
     * @param AbstractEntity $resource
     * @param EntityFactoryInterface $entityFactory
     * @param string $modelClass The entity's model class
     * @param array<string> $fields The fields that name a record
     */
    public function __construct(
        private readonly AbstractEntity $resource,
        private readonly EntityFactoryInterface $entityFactory,
        private readonly string $modelClass,
        array $fields = []
    ) {
        $this->fields = array_values(array_unique($fields));
    }

    /**
     * @inheritDoc
     */
    public function record(string $id): ?AbstractModel
    {
        return $this->records([$id])[$id] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function records(array $ids): array
    {
        $missing = array_values(array_filter(
            array_unique($ids),
            fn (string $id): bool => ctype_digit($id) && !array_key_exists($id, $this->read)
        ));
        if ($missing !== []) {
            $this->read($missing);
        }

        $records = [];
        foreach ($ids as $id) {
            $record = $this->read[$id] ?? null;
            if ($record !== null) {
                $records[$id] = $record;
            }
        }

        return $records;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->read = [];
    }

    /**
     * Read the records with these ids in one query, and remember the ids that have none
     *
     * @param list<string> $ids Numeric ids
     * @return void
     */
    private function read(array $ids): void
    {
        foreach ($ids as $id) {
            $this->read[$id] = null;
        }

        $connection = $this->resource->getConnection();
        $idField = (string) $this->resource->getEntityIdField();
        $linkField = (string) $this->resource->getLinkField();
        $select = $connection->select()
            ->from(['e' => $this->resource->getEntityTable()], [$idField])
            ->where('e.' . $connection->quoteIdentifier($idField) . ' IN (?)', array_map('intval', $ids));
        foreach ($this->fields as $index => $field) {
            $attribute = $this->resource->getAttribute($field);
            if ($attribute === false) {
                continue;
            }

            if ($attribute->isStatic()) {
                $select->columns([$field => 'e.' . $field]);
                continue;
            }

            $alias = 'v' . $index;
            $table = (string) $attribute->getBackendTable();
            $on = [
                sprintf('%1$s.%2$s = e.%2$s', $alias, $connection->quoteIdentifier($linkField)),
                $connection->quoteInto($alias . '.attribute_id = ?', (int) $attribute->getAttributeId()),
            ];
            if ($connection->tableColumnExists($table, self::STORE_COLUMN)) {
                $on[] = $connection->quoteInto($alias . '.' . self::STORE_COLUMN . ' = ?', self::ALL_STORE_VIEWS);
            }
            $select->joinLeft([$alias => $table], implode(' AND ', $on), [$field => $alias . '.value']);
        }

        foreach ($connection->fetchAll($select) as $row) {
            $id = is_array($row) ? ($row[$idField] ?? null) : null;
            if (is_scalar($id) && array_key_exists((string) $id, $this->read)) {
                $this->read[(string) $id] = $this->model($row);
            }
        }
    }

    /**
     * A fresh model holding the row
     *
     * @param array<mixed> $row
     * @return AbstractModel
     * @throws \LogicException When the declared model class is not a model
     */
    private function model(array $row): AbstractModel
    {
        $model = $this->entityFactory->create($this->modelClass);
        if (!$model instanceof AbstractModel) {
            throw new \LogicException(sprintf('%s is not a model class.', $this->modelClass));
        }

        foreach ($row as $field => $value) {
            $model->setData((string) $field, $value);
        }

        return $model;
    }
}
