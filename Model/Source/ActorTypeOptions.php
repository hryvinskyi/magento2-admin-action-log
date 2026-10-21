<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Source;

use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Kinds of actor, for choosing whose entity changes are recorded
 */
class ActorTypeOptions implements OptionSourceInterface
{
    /**
     * Every kind of actor
     *
     * @return list<array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        $labels = [
            ActorType::Admin->value => __('Admin users'),
            ActorType::Integration->value => __('Integrations'),
            ActorType::Customer->value => __('Customers'),
            ActorType::Guest->value => __('Guests'),
            ActorType::Cli->value => __('Command line'),
            ActorType::Cron->value => __('Cron jobs'),
            ActorType::Anonymous->value => __('Unidentified callers'),
        ];

        $options = [];
        foreach (ActorType::cases() as $type) {
            $options[] = ['value' => $type->value, 'label' => $labels[$type->value]];
        }

        return $options;
    }
}
