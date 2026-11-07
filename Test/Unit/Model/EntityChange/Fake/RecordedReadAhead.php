<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\Fake;

use Hryvinskyi\AdminActionLog\Api\TrackedEntityReadAheadInterface;

/**
 * A read-ahead that remembers each batch of entities it was told about
 */
class RecordedReadAhead implements TrackedEntityReadAheadInterface
{
    /**
     * @var list<list<string>>
     */
    public array $announced = [];

    /**
     * @inheritDoc
     */
    public function readAhead(array $entityIds): void
    {
        $this->announced[] = $entityIds;
    }
}
