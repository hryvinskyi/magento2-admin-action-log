<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller\Fixture;

use Hryvinskyi\ActivityLog\Api\Data\Search\SearchPageInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchRequestInterface;
use Hryvinskyi\ActivityLog\Api\Search\ActivitySearchInterface;

/**
 * The real search, counting the searches asked of it
 */
class CountingActivitySearch implements ActivitySearchInterface
{
    /**
     * @var int
     */
    private int $searches = 0;

    /**
     * @param ActivitySearchInterface $search
     */
    public function __construct(
        private readonly ActivitySearchInterface $search
    ) {
    }

    /**
     * @inheritDoc
     */
    public function search(SearchRequestInterface $request): SearchPageInterface
    {
        $this->searches++;

        return $this->search->search($request);
    }

    /**
     * Searches asked so far
     *
     * @return int
     */
    public function searches(): int
    {
        return $this->searches;
    }
}
