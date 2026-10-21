<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Scope;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Tells which part of the store a change of an entity applies to
 */
interface ScopeResolverInterface
{
    /**
     * The scope the model is saved in
     *
     * @param AbstractModel $model
     * @return EntityScopeInterface
     */
    public function resolve(AbstractModel $model): EntityScopeInterface;
}
