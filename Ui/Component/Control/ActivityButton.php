<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Ui\Component\Control;

use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivityAccess;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivitySummary;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecordPage;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\UiComponent\Control\ButtonProviderInterface;

/**
 * The "Activity" button of a record's edit page: it opens the record's activity panel and carries the number of the
 * record's changes in the last 30 days, which the panel's script shows as a badge
 *
 * There is no button on a new record, nor for an admin who may not read the activity log; neither case reads the
 * count. Declared in a form's ui_component XML through a virtual type per kind of record.
 */
class ActivityButton implements ButtonProviderInterface
{
    /**
     * Where the button stands among the page's actions: after the page's own secondary actions, before saving
     */
    private const SORT_ORDER = 25;

    /**
     * @param ActivityAccess $access
     * @param RequestInterface $request
     * @param ActivitySummary $summary
     * @param RecordPage $page The kind of record the form edits
     */
    public function __construct(
        private readonly ActivityAccess $access,
        private readonly RequestInterface $request,
        private readonly ActivitySummary $summary,
        private readonly RecordPage $page
    ) {
    }

    /**
     * @inheritDoc
     *
     * @return array<string, mixed>
     */
    public function getButtonData(): array
    {
        if (!$this->access->isAllowed()) {
            return [];
        }

        $id = $this->page->recordIdIn($this->request);
        if ($id === null) {
            return [];
        }

        $summary = $this->summary->summaryOf($this->page, $id);
        $count = $summary['count'] . ($summary['atLeast'] ? '+' : '');

        return [
            'label' => __('Activity'),
            'title' => __('Activity: %1 changes in the last %2 days', $count, ActivitySummary::DAYS),
            'class' => 'record-activity-button',
            'id' => 'record-activity-button',
            'data_attribute' => [
                'role' => 'record-activity-open',
                'count' => $summary['count'],
                'count-at-least' => $summary['atLeast'] ? 'true' : 'false',
            ],
            'on_click' => '',
            'sort_order' => self::SORT_ORDER,
            'aclResource' => ActivityAccess::RESOURCE,
        ];
    }
}
