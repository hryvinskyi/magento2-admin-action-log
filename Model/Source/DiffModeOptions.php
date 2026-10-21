<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Source;

use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * How much of an entity a change keeps, for the per-entity settings
 */
class DiffModeOptions implements OptionSourceInterface
{
    /**
     * Every way of recording an entity
     *
     * @return list<array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => DiffMode::Changed->value, 'label' => __('Changed fields only')],
            ['value' => DiffMode::Snapshot->value, 'label' => __('Full snapshot')],
            ['value' => DiffMode::Off->value, 'label' => __('Not recorded')],
        ];
    }
}
