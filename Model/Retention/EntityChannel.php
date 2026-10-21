<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Retention;

use Hryvinskyi\ActivityLog\Api\ChannelInterface;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityTypeSettings;

/**
 * The entity change channel; it is kept as long as the longest-kept entity, each entity's own purge removes earlier
 */
class EntityChannel implements ChannelInterface
{
    /**
     * @param EntityTypeSettings $settings
     * @param string $label
     * @param string $chartColor
     * @param string $badgeBackground
     * @param string $badgeForeground
     */
    public function __construct(
        private readonly EntityTypeSettings $settings,
        private readonly string $label = 'Entity change',
        private readonly string $chartColor = '#DDAA33',
        private readonly string $badgeBackground = '#F9EDCB',
        private readonly string $badgeForeground = '#6E5200'
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return ChannelCode::ENTITY;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritDoc
     */
    public function getChartColor(): string
    {
        return $this->chartColor;
    }

    /**
     * @inheritDoc
     */
    public function getBadgeBackground(): string
    {
        return $this->badgeBackground;
    }

    /**
     * @inheritDoc
     */
    public function getBadgeForeground(): string
    {
        return $this->badgeForeground;
    }

    /**
     * @inheritDoc
     */
    public function getRetentionDays(): ?int
    {
        return $this->settings->longestRetentionDays();
    }
}
