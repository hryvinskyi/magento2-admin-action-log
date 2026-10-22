<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\RecordActivity;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * How many changes a record had in the last 30 days, and the newest of them: who made it and when
 *
 * One search per record per request: the edit page's button and its panel both ask, and the second answer comes from
 * memory. The count is the search's own: exact up to its bound, then a lower bound ("10,000+").
 *
 * @phpstan-type Summary array{count: int, atLeast: bool, latestMs: int|null, latestActor: string|null}
 */
class ActivitySummary implements ResetAfterRequestInterface
{
    /**
     * The days the summary covers, ending now
     */
    public const DAYS = 30;

    /**
     * @var array<string, Summary>
     */
    private array $summaries = [];

    /**
     * @param RecordSearch $recordSearch
     */
    public function __construct(
        private readonly RecordSearch $recordSearch
    ) {
    }

    /**
     * The record's changes of the last DAYS days: their count, and the time and actor of the newest
     *
     * @param RecordPage $page The kind of record
     * @param string $id The record's id
     * @return Summary
     */
    public function summaryOf(RecordPage $page, string $id): array
    {
        $key = $page->getSearchKey() . ':' . $page->criterionValue($id);
        if (isset($this->summaries[$key])) {
            return $this->summaries[$key];
        }

        $result = $this->recordSearch->lastDays($page, $id, self::DAYS, 1);
        $latest = $result->getEvents()[0] ?? null;
        $total = $result->getTotal();

        return $this->summaries[$key] = [
            'count' => $total?->getCount() ?? count($result->getEvents()),
            'atLeast' => $total?->isAtLeast() ?? false,
            'latestMs' => $latest?->getOccurredAtMs(),
            'latestActor' => $latest?->getActorName(),
        ];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->summaries = [];
    }
}
