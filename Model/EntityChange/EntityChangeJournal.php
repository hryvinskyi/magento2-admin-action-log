<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

/**
 * Records entity changes of saved models: the state before is taken when a save or delete starts, the change is
 * journaled once its transaction commits
 *
 * A change in which no field changed is not recorded. Secret fields are masked before anything is stored, by the
 * pool's rule (TrackedEntityPool::isSecretField()). Recording never breaks the save: a failure to record is logged and
 * the save goes on. Whether a change is recorded at all (RecordingRules), which fields are secret and how it is
 * written (ChangeWriter) are shared with the recorder of changes made without a model.
 *
 * A model that keeps part of an entity's data (a product's stock item) is recorded as a change of that entity, under
 * the entity's id. Saving such a part for the first time is a change of the entity, never its creation, whatever
 * state the part reports for before (none at all reads as an empty one), and deleting it records nothing. A part's
 * state after the change is the part's to tell: one whose model may hold values its row never got reads them from
 * storage, so a save rolled back by an outer transaction, whose commit callback still fires on a later commit,
 * collapses to no change.
 */
class EntityChangeJournal
{
    private const ID_MAX_LENGTH = 64;

    /**
     * @param RecordingRules $rules
     * @param TrackedEntityPool $pool
     * @param ChangeBuffer $buffer
     * @param ValueNormalizer $normalizer
     * @param ChangeWriter $writer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly RecordingRules $rules,
        private readonly TrackedEntityPool $pool,
        private readonly ChangeBuffer $buffer,
        private readonly ValueNormalizer $normalizer,
        private readonly ChangeWriter $writer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Take the state of an entity before it is saved
     *
     * @param AbstractModel $model
     * @return void
     */
    public function beforeSave(AbstractModel $model): void
    {
        $this->snapshot($model, false);
    }

    /**
     * Take the state of an entity before it is deleted
     *
     * @param AbstractModel $model
     * @return void
     */
    public function beforeDelete(AbstractModel $model): void
    {
        $this->snapshot($model, true);
    }

    /**
     * Journal the change of an entity whose transaction just committed
     *
     * @param AbstractModel $model
     * @return void
     */
    public function committed(AbstractModel $model): void
    {
        $pending = $this->buffer->take($model);
        if ($pending === null) {
            return;
        }

        $entity = $pending['entity'];
        if ($pending['operation'] !== ChangeOperation::Delete && !$model->isSaveAllowed()) {
            return;
        }

        try {
            $this->record(
                $entity,
                $model,
                $pending['part'],
                $pending['operation'],
                $pending['before']
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('The change of %s could not be recorded.', $entity->getCode()),
                ['exception' => $exception]
            );
        }
    }

    /**
     * Take the state of the model, never failing the save
     *
     * @param AbstractModel $model
     * @param bool $deleting
     * @return void
     */
    private function snapshot(AbstractModel $model, bool $deleting): void
    {
        $this->buffer->forget($model);
        try {
            $this->takeState($model, $deleting);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'The state of an entity before its change could not be read.',
                ['exception' => $exception]
            );
        }
    }

    /**
     * Hold the state of a tracked model whose changes are recorded
     *
     * @param AbstractModel $model
     * @param bool $deleting
     * @return void
     */
    private function takeState(AbstractModel $model, bool $deleting): void
    {
        if (!$this->rules->isOn()) {
            return;
        }

        $part = $deleting ? null : $this->pool->matchPart($model);
        $entity = $part ?? $this->pool->match($model);
        if ($entity === null || !$this->rules->records($entity->getCode())) {
            return;
        }

        $state = $entity->stateBefore($model);
        if ($deleting && $state === null) {
            return;
        }

        $operation = match (true) {
            $deleting => ChangeOperation::Delete,
            $state === null && $part === null => ChangeOperation::Create,
            default => ChangeOperation::Update,
        };
        $this->buffer->put(
            $model,
            $entity,
            $part,
            $operation,
            $state === null ? [] : $this->comparable($entity, $model, $state)
        );
    }

    /**
     * Journal the change with its fields
     *
     * @param TrackedEntityInterface $entity
     * @param AbstractModel $model
     * @param TrackedEntityPartInterface|null $part The part of the entity the model is, if it is one
     * @param ChangeOperation $operation
     * @param array<string,string|null> $before
     * @return void
     */
    private function record(
        TrackedEntityInterface $entity,
        AbstractModel $model,
        ?TrackedEntityPartInterface $part,
        ChangeOperation $operation,
        array $before
    ): void {
        $this->writer->write(
            $entity,
            $this->entityId($model, $part),
            $operation,
            $before,
            $operation === ChangeOperation::Delete
                ? []
                : $this->comparable($entity, $model, $this->after($model, $part)),
            static fn (): string => $entity->describe($model),
            $entity->scope($model),
            fn (string $field): bool => $this->pool->isSecretField($entity, $field, $model)
        );
    }

    /**
     * The entity's fields as comparable text
     *
     * @param TrackedEntityInterface $entity
     * @param AbstractModel $model
     * @param array<string,mixed> $data
     * @return array<string, string|null>
     */
    private function comparable(TrackedEntityInterface $entity, AbstractModel $model, array $data): array
    {
        $fields = [];
        foreach ($entity->fields($model, $data) as $field => $value) {
            if ($this->normalizer->isComparable($value)) {
                $fields[$field] = $this->normalizer->normalize($value);
            }
        }

        return $fields;
    }

    /**
     * The entity's data after the change: the part's to tell for a part, else the model's own
     *
     * @param AbstractModel $model
     * @param TrackedEntityPartInterface|null $part
     * @return array<string, mixed>
     */
    private function after(AbstractModel $model, ?TrackedEntityPartInterface $part): array
    {
        return $part === null ? $this->dataOf($model) : $part->stateAfter($model);
    }

    /**
     * The model data keyed by field name
     *
     * @param AbstractModel $model
     * @return array<string, mixed>
     */
    private function dataOf(AbstractModel $model): array
    {
        $data = [];
        $values = $model->getData();
        foreach (is_array($values) ? $values : [] as $field => $value) {
            $data[(string) $field] = $value;
        }

        return $data;
    }

    /**
     * The id the change is recorded under, as a subject id, or null when there is none
     *
     * It is the entity's id when the model is a part of it, else the model's own.
     *
     * @param AbstractModel $model
     * @param TrackedEntityPartInterface|null $part
     * @return string|null
     */
    private function entityId(AbstractModel $model, ?TrackedEntityPartInterface $part): ?string
    {
        $id = $part === null ? $model->getId() : $part->entityId($model);

        return is_scalar($id) && (string) $id !== '' ? mb_substr((string) $id, 0, self::ID_MAX_LENGTH) : null;
    }
}
