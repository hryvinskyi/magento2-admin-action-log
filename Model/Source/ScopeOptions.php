<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The scopes a change can be made in, for the search's scope filter: the default scope, then each website and store
 * view by name
 *
 * An option's value is the name the search matches, so picking it gives the same filter as typing it.
 */
class ScopeOptions implements OptionSourceInterface
{
    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The default scope, then websites, then store views
     *
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $defaultScope = (string) __('Default Config');
        $options = [['value' => $defaultScope, 'label' => $defaultScope]];
        foreach ($this->storeManager->getWebsites() as $website) {
            $name = (string) $website->getName();
            $options[] = ['value' => $name, 'label' => $name];
        }
        foreach ($this->storeManager->getStores() as $store) {
            $name = (string) $store->getName();
            $options[] = ['value' => $name, 'label' => $name];
        }

        return $options;
    }
}
