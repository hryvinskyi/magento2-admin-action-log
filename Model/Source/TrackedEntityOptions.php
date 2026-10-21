<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Source;

use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The tracked entities, for the per-entity settings
 */
class TrackedEntityOptions implements OptionSourceInterface
{
    /**
     * @param TrackedEntityPool $pool
     */
    public function __construct(
        private readonly TrackedEntityPool $pool
    ) {
    }

    /**
     * Every tracked entity, by label
     *
     * @return list<array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->pool->all() as $entity) {
            $options[] = ['value' => $entity->getCode(), 'label' => __($entity->getLabel())];
        }

        usort($options, static fn (array $a, array $b): int => strcmp((string) $a['label'], (string) $b['label']));

        return $options;
    }
}
