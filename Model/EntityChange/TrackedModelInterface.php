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
 * How the models of one class are read: their fields, their state before and after a change, and the class itself
 *
 * What a part of an entity reads its own model through.
 */
interface TrackedModelInterface extends TrackedEntityInterface
{
    /**
     * The class of the models read
     *
     * @return string
     */
    public function getModelClass(): string;

    /**
     * The model's data once its change committed
     *
     * @param AbstractModel $model
     * @return array<string, mixed>
     */
    public function stateAfter(AbstractModel $model): array;
}
