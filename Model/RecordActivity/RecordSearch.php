<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\RecordActivity;

use Hryvinskyi\ActivityLog\Api\Data\Search\SearchCriterionInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchPageInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchRequestInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\ActivitySearchInterface;
use Hryvinskyi\ActivityLog\Model\Search\RecentDays;

/**
 * One record's changes of the last N days, through the same search the activity log page runs
 *
 * The record is named by its exact form and only the channels that record changes are searched, as the panel on the
 * edit page does, so a count taken here equals the number of results the panel lists for the same days. "The last N
 * days" runs from the store's midnight N−1 days ago to now, the rule the browser's period presets follow.
 */
class RecordSearch
{
    /**
     * @param ActivitySearchInterface $search
     * @param SearchRequestInterfaceFactory $requestFactory
     * @param SearchCriterionInterfaceFactory $criterionFactory
     * @param RecentDays $recentDays
     * @param MillisecondClockInterface $clock
     */
    public function __construct(
        private readonly ActivitySearchInterface $search,
        private readonly SearchRequestInterfaceFactory $requestFactory,
        private readonly SearchCriterionInterfaceFactory $criterionFactory,
        private readonly RecentDays $recentDays,
        private readonly MillisecondClockInterface $clock
    ) {
    }

    /**
     * The first page of the record's changes of the last N days, newest first, with their count
     *
     * @param RecordPage $page The kind of record
     * @param string $id The record's id
     * @param int $days One or more
     * @param int $limit Changes wanted on the page, 1 to the search's maximum
     * @return SearchPageInterface
     * @throws \InvalidArgumentException When the days or the limit are out of range
     */
    public function lastDays(RecordPage $page, string $id, int $days, int $limit): SearchPageInterface
    {
        $nowMs = $this->clock->nowMs();

        return $this->search->search(
            $this->requestFactory->create()
                ->withRange($this->recentDays->startMs($days, $nowMs), $nowMs)
                ->withCriteria([
                    $this->criterionFactory->create()->withTerm($page->getSearchKey(), $page->criterionValue($id)),
                ])
                ->withChangesOnly(true)
                ->withLimit($limit)
        );
    }
}
