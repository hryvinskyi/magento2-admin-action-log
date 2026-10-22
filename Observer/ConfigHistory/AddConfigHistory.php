<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Observer\ConfigHistory;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\HistoryPage;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;

/**
 * Adds the settings' history to the Stores › Configuration page before its layout is read
 */
class AddConfigHistory implements ObserverInterface
{
    /**
     * @param HistoryPage $page
     */
    public function __construct(
        private readonly HistoryPage $page
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $actionName = $observer->getEvent()->getData('full_action_name');
        $layout = $observer->getEvent()->getData('layout');
        if (is_string($actionName) && $layout instanceof LayoutInterface) {
            $this->page->addTo($layout, $actionName);
        }
    }
}
