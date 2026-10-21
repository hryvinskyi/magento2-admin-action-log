<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Entities registered through the "entities" argument, asked in sortOrder
 */
class TrackedEntityPool
{
    /**
     * @var array<string, TrackedEntityInterface>
     */
    private array $byCode = [];

    /**
     * @param array<string,TrackedEntityInterface> $entities
     */
    public function __construct(array $entities = [])
    {
        foreach ($entities as $entity) {
            $this->byCode[$entity->getCode()] = $entity;
        }
    }

    /**
     * The first entity kind the model belongs to, or null when its changes are not tracked
     *
     * @param AbstractModel $model
     * @return TrackedEntityInterface|null
     */
    public function match(AbstractModel $model): ?TrackedEntityInterface
    {
        foreach ($this->byCode as $entity) {
            if ($entity->supports($model)) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * The entity kind with the code, or null
     *
     * @param string $code
     * @return TrackedEntityInterface|null
     */
    public function get(string $code): ?TrackedEntityInterface
    {
        return $this->byCode[$code] ?? null;
    }

    /**
     * Every entity kind
     *
     * @return list<TrackedEntityInterface>
     */
    public function all(): array
    {
        return array_values($this->byCode);
    }
}
