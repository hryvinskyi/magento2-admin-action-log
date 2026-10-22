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
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Entity states taken before a save or delete, held until its transaction commits
 *
 * A rolled-back transaction never commits, so its state is simply never taken out: the change leaves no record.
 *
 * A state taken of a part of an entity holds the part too, which tells the id of the entity the change is recorded
 * under.
 *
 * @phpstan-type Pending array{
 *     entity: TrackedEntityInterface,
 *     part: TrackedEntityPartInterface|null,
 *     operation: ChangeOperation,
 *     before: array<string, string|null>
 * }
 */
class ChangeBuffer implements ResetAfterRequestInterface
{
    /**
     * @var array<int, Pending>
     */
    private array $pending = [];

    /**
     * Hold the state of the model until its transaction commits
     *
     * @param AbstractModel $model
     * @param TrackedEntityInterface $entity The entity whose change it is
     * @param TrackedEntityPartInterface|null $part The part of the entity the model is, if it is one
     * @param ChangeOperation $operation
     * @param array<string,string|null> $before
     * @return void
     */
    public function put(
        AbstractModel $model,
        TrackedEntityInterface $entity,
        ?TrackedEntityPartInterface $part,
        ChangeOperation $operation,
        array $before
    ): void {
        $this->pending[spl_object_id($model)] = [
            'entity' => $entity,
            'part' => $part,
            'operation' => $operation,
            'before' => $before,
        ];
    }

    /**
     * Drop what is held for the model
     *
     * @param AbstractModel $model
     * @return void
     */
    public function forget(AbstractModel $model): void
    {
        unset($this->pending[spl_object_id($model)]);
    }

    /**
     * Take out what is held for the model, or null
     *
     * @param AbstractModel $model
     * @return Pending|null
     */
    public function take(AbstractModel $model): ?array
    {
        $id = spl_object_id($model);
        $pending = $this->pending[$id] ?? null;
        unset($this->pending[$id]);

        return $pending;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->pending = [];
    }
}
