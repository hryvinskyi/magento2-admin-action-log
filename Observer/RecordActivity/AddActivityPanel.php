<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Observer\RecordActivity;

use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivityAccess;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecordPage;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;

/**
 * Adds a record's activity panel to its edit page, with the timeline's assets, before the page's layout is read
 *
 * Only on the pages in "pages" (keyed by their full action name), only for an existing record, and only for an admin
 * who may read the activity log: anyone else gets none of the panel's markup, config, scripts or styles.
 */
class AddActivityPanel implements ObserverInterface
{
    /**
     * @var array<string, RecordPage>
     */
    private array $pages = [];

    /**
     * @param ActivityAccess $access
     * @param RequestInterface $request
     * @param array<string,RecordPage> $pages Keyed by the full action name of the edit page
     */
    public function __construct(
        private readonly ActivityAccess $access,
        private readonly RequestInterface $request,
        array $pages = []
    ) {
        foreach ($pages as $actionName => $page) {
            $this->add((string) $actionName, $page);
        }
    }

    /**
     * @inheritDoc
     */
    public function execute(Observer $observer): void
    {
        $actionName = $observer->getEvent()->getData('full_action_name');
        $layout = $observer->getEvent()->getData('layout');
        $page = is_string($actionName) ? $this->pages[$actionName] ?? null : null;
        if ($page === null || !$layout instanceof LayoutInterface || $page->recordIdIn($this->request) === null) {
            return;
        }

        if ($this->access->isAllowed()) {
            $layout->getUpdate()->addHandle($page->getLayoutHandle());
        }
    }

    /**
     * Register an edit page; anything else in the argument fails here, at construction
     *
     * @param string $actionName
     * @param RecordPage $page
     * @return void
     */
    private function add(string $actionName, RecordPage $page): void
    {
        $this->pages[$actionName] = $page;
    }
}
