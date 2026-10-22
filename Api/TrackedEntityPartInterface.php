<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Api;

use Magento\Framework\Model\AbstractModel;

/**
 * A model that keeps part of a tracked entity's data in a store of its own, such as a product's stock item
 *
 * Its changes are recorded as changes of the entity it belongs to. getCode() and getLabel() are that entity's, the
 * change is recorded under that entity's id, and describe() names that entity. The entity's settings (mode,
 * retention) govern it. A part saved for the first time changes an entity that already exists: stateBefore() returns
 * an empty state for it, never null, so its values are recorded as set from nothing. A part's own deletion is not
 * recorded: a part goes with its entity, whose deletion is.
 *
 * Register an implementation in the "parts" argument of the tracked entity pool. The entity it belongs to must be
 * registered in the pool's "entities" argument, or the pool refuses it. An entity has at most one part per model
 * class: a second part of the same entity kept by the same class is refused when the pool is built, so a part meant
 * to replace another one never ends up recording beside it. To record the same data from another source instead,
 * replace the pool item (same item name) with an implementation that narrows supports() or fields().
 *
 * @api
 * @since 1.1.0
 */
interface TrackedEntityPartInterface extends TrackedEntityInterface
{
    /**
     * The class of the model keeping the part: "Magento\CatalogInventory\Model\Stock\Item"
     *
     * @return string
     */
    public function getModelClass(): string;

    /**
     * The part's data once its change committed, as fields() reads it
     *
     * The model's own data, or what is stored for a part whose model may not match its row: a save rolled back by
     * another transaction leaves the model holding values that were never written, so a change compared against what
     * is stored collapses to no change.
     *
     * @param AbstractModel $model
     * @return array<string, mixed>
     */
    public function stateAfter(AbstractModel $model): array;

    /**
     * Id of the entity the model is part of, or null when it names none
     *
     * @param AbstractModel $model
     * @return string|null
     */
    public function entityId(AbstractModel $model): ?string;
}
