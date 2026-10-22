<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ConfigHistory;

use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivityAccess;
use Magento\Framework\View\LayoutInterface;

/**
 * The page that shows each setting's history: the Stores › Configuration edit page, for an admin who may read the
 * activity log
 *
 * The page gets the history's layout handle (the popover's config, templates and script, the timeline's assets)
 * before its layout is read; a setting gets its history button only on a page that has the handle, so a button
 * never appears without the script that opens it, and nothing appears for an admin without the permission.
 */
class HistoryPage
{
    /**
     * Layout handle of the history's markup and assets
     */
    public const HANDLE = 'hryvinskyi_adminactionlog_config_history';

    /**
     * @param ActivityAccess $access
     * @param string $actionName Full action name of the configuration edit page
     */
    public function __construct(
        private readonly ActivityAccess $access,
        private readonly string $actionName = 'adminhtml_system_config_edit'
    ) {
    }

    /**
     * Add the history to a page about to be laid out, when it is the configuration page and the admin may see it
     *
     * @param LayoutInterface $layout
     * @param string $actionName The page's full action name
     * @return void
     */
    public function addTo(LayoutInterface $layout, string $actionName): void
    {
        if ($actionName === $this->actionName && $this->access->isAllowed()) {
            $layout->getUpdate()->addHandle(self::HANDLE);
        }
    }

    /**
     * Whether a page shows the history
     *
     * @param LayoutInterface $layout
     * @return bool
     */
    public function isShownOn(LayoutInterface $layout): bool
    {
        return in_array(self::HANDLE, $layout->getUpdate()->getHandles(), true) && $this->access->isAllowed();
    }
}
