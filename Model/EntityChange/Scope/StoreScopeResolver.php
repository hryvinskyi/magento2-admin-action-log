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
 * The store view an entity is edited in ("store_id"); store 0 or none is the default scope
 */
class StoreScopeResolver implements ScopeResolverInterface
{
    /**
     * @param ScopeNames $scopeNames
     * @param string $storeField
     * @param string $defaultLabel
     */
    public function __construct(
        private readonly ScopeNames $scopeNames,
        private readonly string $storeField = 'store_id',
        private readonly string $defaultLabel = 'All Store Views'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(AbstractModel $model): EntityScopeInterface
    {
        $storeId = $model->getData($this->storeField);

        return is_numeric($storeId) && (int) $storeId > 0
            ? $this->scopeNames->store((int) $storeId, $this->defaultLabel)
            : $this->scopeNames->fixed(EntityScopeInterface::TYPE_DEFAULT, $this->defaultLabel);
    }
}
