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

/**
 * An entity stored as EAV attributes: its fields are its attributes
 *
 * The stored state is loaded into a fresh model in the store the change is made in, so nothing loading triggers
 * (observers adding stock data, extension attributes) reaches the model being saved.
 */
class EavStorage implements EntityStorageInterface
{
    private const STORE_FIELD = 'store_id';

    /**
     * @param AbstractEntity $resource
     * @param EntityFactoryInterface $entityFactory
     * @param string $modelClass The entity's model class
     */
    public function __construct(
        private readonly AbstractEntity $resource,
        private readonly EntityFactoryInterface $entityFactory,
        private readonly string $modelClass
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isField(string $field): bool
    {
        return $this->resource->getAttribute($field) !== false;
    }

    /**
     * @inheritDoc
     */
    public function load(AbstractModel $model, string $id): ?array
    {
        $stored = $this->entityFactory->create($this->modelClass);
        if (!$stored instanceof AbstractModel) {
            throw new \LogicException(sprintf('%s is not a model class.', $this->modelClass));
        }

        $storeId = $model->getData(self::STORE_FIELD);
        if (is_numeric($storeId)) {
            $stored->setData(self::STORE_FIELD, (int) $storeId);
        }

        if (!ctype_digit($id)) {
            return null;
        }

        $this->resource->load($stored, (int) $id);
        $values = $stored->getData();
        if ($stored->getId() === null || !is_array($values)) {
            return null;
        }

        $data = [];
        foreach ($values as $field => $value) {
            $data[(string) $field] = $value;
        }

        return $data;
    }
}
