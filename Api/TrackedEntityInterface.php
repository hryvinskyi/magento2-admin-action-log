<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Api;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * A kind of entity whose changes are journaled
 *
 * Register an implementation in the "entities" argument of the tracked entity pool under its code. Most entities need
 * no class of their own: declare a virtual type of Model\EntityChange\TrackedEntity with the model class, its
 * resource model and a label.
 *
 * @api
 */
interface TrackedEntityInterface
{
    /**
     * Stable code, stored as the subject type of every change: lowercase letters, digits, underscore
     *
     * @return string
     */
    public function getCode(): string;

    /**
     * Name of one entity of this kind, shown to people: "Product"
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Whether the model is an entity of this kind
     *
     * @param AbstractModel $model
     * @return bool
     */
    public function supports(AbstractModel $model): bool;

    /**
     * The entity's data as stored before the change, or null when the entity is new
     *
     * @param AbstractModel $model
     * @return array<string, mixed>|null
     */
    public function stateBefore(AbstractModel $model): ?array;

    /**
     * The fields of an entity state worth comparing, keyed by field name
     *
     * @param AbstractModel $model
     * @param array<string,mixed> $data The model's data, now or as it was before the change
     * @return array<string, mixed>
     */
    public function fields(AbstractModel $model, array $data): array;

    /**
     * Where in the store the change applies
     *
     * @param AbstractModel $model
     * @return EntityScopeInterface
     */
    public function scope(AbstractModel $model): EntityScopeInterface;

    /**
     * Whether the values of a field are secret and must be masked
     *
     * @param AbstractModel $model
     * @param string $field
     * @return bool
     */
    public function isSecretField(AbstractModel $model, string $field): bool;

    /**
     * Text naming the entity: "Product #1245", "Config value web/secure/base_url"
     *
     * @param AbstractModel $model
     * @return string
     */
    public function describe(AbstractModel $model): string;
}
