<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Storage;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldInputTypesInterface;
use Magento\Eav\Model\Entity\AbstractEntity;
use Magento\Framework\Data\Collection\EntityFactoryInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * An entity stored as EAV attributes: its fields are its attributes
 *
 * The stored state is loaded into a fresh model in the store the change is made in, so nothing loading triggers
 * (observers adding stock data, extension attributes) reaches the model being saved. A field's input type is its
 * attribute's frontend input.
 */
class EavStorage implements EntityStorageInterface, FieldInputTypesInterface
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
    public function inputTypeOf(string $field): ?string
    {
        $attribute = $this->resource->getAttribute($field);
        $inputType = $attribute === false ? null : $attribute->getFrontendInput();

        return is_string($inputType) && $inputType !== '' ? $inputType : null;
    }

    /**
     * @inheritDoc
     */
    public function load(AbstractModel $model, string $id): ?array
    {
        $storeId = $model->getData(self::STORE_FIELD);
        $stored = $this->stored($id, is_numeric($storeId) ? (int) $storeId : null);
        $values = $stored?->getData();
        if (!is_array($values)) {
            return null;
        }

        $data = [];
        foreach ($values as $field => $value) {
            $data[(string) $field] = $value;
        }

        return $data;
    }

    /**
     * The record loaded into a fresh model, or null when it is not stored
     *
     * It is read in the store view given, or in the current one.
     *
     * @param string $id
     * @param int|null $storeId
     * @return AbstractModel|null
     * @throws \LogicException When the declared model class is not a model
     */
    private function stored(string $id, ?int $storeId): ?AbstractModel
    {
        $stored = $this->entityFactory->create($this->modelClass);
        if (!$stored instanceof AbstractModel) {
            throw new \LogicException(sprintf('%s is not a model class.', $this->modelClass));
        }

        if ($storeId !== null) {
            $stored->setData(self::STORE_FIELD, $storeId);
        }

        if (!ctype_digit($id)) {
            return null;
        }

        $this->resource->load($stored, (int) $id);

        return $stored->getId() === null ? null : $stored;
    }
}
