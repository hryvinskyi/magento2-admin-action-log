<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Record;

/**
 * Which records of one kind still exist, asked for a whole page of changes at once
 */
interface RecordPresenceInterface
{
    /**
     * The ids among those given that still name a record, as text
     *
     * @param list<string> $ids
     * @return list<string>
     */
    public function existing(array $ids): array;
}
