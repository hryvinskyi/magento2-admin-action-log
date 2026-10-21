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
use Magento\Store\Model\ScopeInterface;

/**
 * The scope a configuration value is saved for: default, a website or a store view
 */
class ConfigScopeResolver implements ScopeResolverInterface
{
    /**
     * @param ScopeNames $scopeNames
     * @param string $defaultLabel
     */
    public function __construct(
        private readonly ScopeNames $scopeNames,
        private readonly string $defaultLabel = 'Default Config'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(AbstractModel $model): EntityScopeInterface
    {
        $scope = $model->getData('scope');
        $scopeId = $model->getData('scope_id');
        $id = is_numeric($scopeId) ? (int) $scopeId : 0;

        return match (true) {
            $scope === ScopeInterface::SCOPE_WEBSITES && $id > 0
                => $this->scopeNames->website($id, $this->defaultLabel),
            $scope === ScopeInterface::SCOPE_STORES && $id > 0 => $this->scopeNames->store($id, $this->defaultLabel),
            default => $this->scopeNames->fixed(EntityScopeInterface::TYPE_DEFAULT, $this->defaultLabel),
        };
    }
}
