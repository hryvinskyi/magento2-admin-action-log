<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Scope;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Builds scopes named after the store view or website they point to
 */
class ScopeNames
{
    /**
     * @param StoreManagerInterface $storeManager
     * @param EntityScopeInterfaceFactory $scopeFactory
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly EntityScopeInterfaceFactory $scopeFactory
    ) {
    }

    /**
     * A store view scope, or the default scope under the given name when the store view no longer exists
     *
     * @param int $storeId
     * @param string $defaultLabel
     * @return EntityScopeInterface
     */
    public function store(int $storeId, string $defaultLabel): EntityScopeInterface
    {
        try {
            $name = (string) $this->storeManager->getStore($storeId)->getName();
        } catch (LocalizedException) {
            return $this->fixed(EntityScopeInterface::TYPE_DEFAULT, $defaultLabel);
        }

        return $this->create(EntityScopeInterface::TYPE_STORE, $storeId, $name === '' ? '#' . $storeId : $name);
    }

    /**
     * A website scope, or the default scope under the given name when the website no longer exists
     *
     * @param int $websiteId
     * @param string $defaultLabel
     * @return EntityScopeInterface
     */
    public function website(int $websiteId, string $defaultLabel): EntityScopeInterface
    {
        try {
            $name = (string) $this->storeManager->getWebsite($websiteId)->getName();
        } catch (LocalizedException) {
            return $this->fixed(EntityScopeInterface::TYPE_DEFAULT, $defaultLabel);
        }

        return $this->create(EntityScopeInterface::TYPE_WEBSITE, $websiteId, $name === '' ? '#' . $websiteId : $name);
    }

    /**
     * A scope without an id: the default or the global one
     *
     * @param string $type
     * @param string $label
     * @return EntityScopeInterface
     */
    public function fixed(string $type, string $label): EntityScopeInterface
    {
        return $this->create($type, 0, $label);
    }

    /**
     * A scope through its factory
     *
     * @param string $type
     * @param int $id
     * @param string $label
     * @return EntityScopeInterface
     */
    private function create(string $type, int $id, string $label): EntityScopeInterface
    {
        return $this->scopeFactory->create(['type' => $type, 'id' => $id, 'label' => $label]);
    }
}
