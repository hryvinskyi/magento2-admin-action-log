<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\StoredRecordsInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * A part of a tracked entity described in di.xml
 *
 * A module declares a virtual type of this class with:
 * - "entity": the tracked entity the part belongs to, whose code and label the changes are recorded under;
 * - "storedAs": how the part's own model is read (which model class, which fields, where it is stored, its scope, its
 *   state after a change), a virtual type of TrackedEntity whose own code and label are never shown;
 * - "records": the entity's records holding the fields that name it, to name the entity the way its own changes name
 *   it without loading it whole;
 * - "entityIdField": the field of the part's model holding the entity's id.
 *
 * An entity whose record cannot be read any more is named by its label and id ("Product #42").
 */
class TrackedPart implements TrackedEntityPartInterface
{
    /**
     * @param TrackedEntityInterface $entity
     * @param TrackedModelInterface $storedAs
     * @param StoredRecordsInterface $records
     * @param string $entityIdField
     * @throws \InvalidArgumentException When no field holds the entity's id
     */
    public function __construct(
        private readonly TrackedEntityInterface $entity,
        private readonly TrackedModelInterface $storedAs,
        private readonly StoredRecordsInterface $records,
        private readonly string $entityIdField
    ) {
        if (trim($entityIdField) === '') {
            throw new \InvalidArgumentException(
                sprintf('A part of the tracked entity "%s" needs the field holding its id.', $entity->getCode())
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->entity->getCode();
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->entity->getLabel();
    }

    /**
     * @inheritDoc
     */
    public function getModelClass(): string
    {
        return $this->storedAs->getModelClass();
    }

    /**
     * @inheritDoc
     */
    public function stateAfter(AbstractModel $model): array
    {
        return $this->storedAs->stateAfter($model);
    }

    /**
     * @inheritDoc
     */
    public function supports(AbstractModel $model): bool
    {
        return $this->storedAs->supports($model);
    }

    /**
     * @inheritDoc
     */
    public function stateBefore(AbstractModel $model): ?array
    {
        return $this->storedAs->stateBefore($model) ?? [];
    }

    /**
     * @inheritDoc
     */
    public function fields(AbstractModel $model, array $data): array
    {
        return $this->storedAs->fields($model, $data);
    }

    /**
     * @inheritDoc
     */
    public function scope(AbstractModel $model): EntityScopeInterface
    {
        return $this->storedAs->scope($model);
    }

    /**
     * @inheritDoc
     */
    public function isSecretField(AbstractModel $model, string $field): bool
    {
        return $this->storedAs->isSecretField($model, $field);
    }

    /**
     * @inheritDoc
     */
    public function describe(AbstractModel $model): string
    {
        $id = $this->entityId($model);
        if ($id === null) {
            return $this->entity->getLabel();
        }

        $record = $this->records->record($id);

        return $record === null
            ? sprintf('%s #%s', $this->entity->getLabel(), $id)
            : $this->entity->describe($record);
    }

    /**
     * @inheritDoc
     */
    public function entityId(AbstractModel $model): ?string
    {
        $id = $model->getData($this->entityIdField);

        return is_scalar($id) && trim((string) $id) !== '' ? trim((string) $id) : null;
    }
}
