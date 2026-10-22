<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

/**
 * Finds records by what people know of them, the most precise answer first
 *
 * A natural key typed in full wins, and so does a number when the kind is searchable by id; only when neither names
 * a record are names searched, and then at most NAME_LIMIT records, so a short word cannot turn into thousands of
 * ids. A record found by id need not exist any more: its history outlives it. For a kind people name by a number
 * that is its natural key (an order's increment id), the exact form "#<n>" also names the record whose key is
 * exactly n.
 */
class RecordResolver implements RecordResolverInterface
{
    /**
     * Most records a part of a name may stand for
     */
    public const NAME_LIMIT = 50;

    /**
     * @param RecordLookupInterface $lookup
     * @param bool $searchableById Whether a number typed alone is also the record's id
     * @param bool $exactKeyIsAnId Whether the exact form also names the record whose natural key it is
     */
    public function __construct(
        private readonly RecordLookupInterface $lookup,
        private readonly bool $searchableById = false,
        private readonly bool $exactKeyIsAnId = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function idsFor(string $text): array
    {
        $ids = $this->lookup->exact($text);
        if ($this->searchableById && preg_match('/^\d{1,18}$/D', $text) === 1) {
            $ids[] = (string) (int) $text;
        }

        if ($ids === []) {
            $ids = $this->lookup->named($text, self::NAME_LIMIT);
        }

        return array_values(array_unique($ids));
    }

    /**
     * @inheritDoc
     */
    public function idsOf(string $id): array
    {
        if (!$this->exactKeyIsAnId) {
            return [$id];
        }

        return array_values(array_unique([$id, ...$this->lookup->exact($id)]));
    }
}
