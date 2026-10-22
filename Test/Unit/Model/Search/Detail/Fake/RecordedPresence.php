<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Detail\Fake;

use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordPresenceInterface;

/**
 * Records of which a fixed set of ids exists, remembering every question asked
 */
class RecordedPresence implements RecordPresenceInterface
{
    /**
     * The ids of each question, in order
     *
     * @var list<list<string>>
     */
    public array $calls = [];

    /**
     * @param list<string> $existing
     */
    public function __construct(
        private readonly array $existing
    ) {
    }

    /**
     * @inheritDoc
     */
    public function existing(array $ids): array
    {
        $this->calls[] = $ids;

        return array_values(array_intersect($ids, $this->existing));
    }
}
