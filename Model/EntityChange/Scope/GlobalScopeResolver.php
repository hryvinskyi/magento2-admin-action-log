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
 * For entities that are the same in every store view
 */
class GlobalScopeResolver implements ScopeResolverInterface
{
    /**
     * @param ScopeNames $scopeNames
     * @param string $label
     */
    public function __construct(
        private readonly ScopeNames $scopeNames,
        private readonly string $label = 'Global'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(AbstractModel $model): EntityScopeInterface
    {
        return $this->scopeNames->fixed(EntityScopeInterface::TYPE_GLOBAL, $this->label);
    }
}
