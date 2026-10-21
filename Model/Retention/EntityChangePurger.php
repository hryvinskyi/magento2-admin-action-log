<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Retention;

use Hryvinskyi\ActivityLog\Api\ActivityPrunerInterface;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\PurgerInterface;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityTypeSettings;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;

/**
 * Keeps each tracked entity's changes as long as its own setting says
 *
 * The entity channel's retention is the longest of them, so the journal's own purge removes what no setting keeps
 * any more, including changes of entities that are no longer tracked.
 */
class EntityChangePurger implements PurgerInterface
{
    private const DAY_MS = 86_400_000;

    /**
     * @param TrackedEntityPool $pool
     * @param EntityTypeSettings $settings
     * @param ActivityPrunerInterface $pruner
     * @param MillisecondClockInterface $clock
     */
    public function __construct(
        private readonly TrackedEntityPool $pool,
        private readonly EntityTypeSettings $settings,
        private readonly ActivityPrunerInterface $pruner,
        private readonly MillisecondClockInterface $clock
    ) {
    }

    /**
     * @inheritDoc
     */
    public function purge(): int
    {
        $now = $this->clock->nowMs();
        $removed = 0;
        foreach ($this->pool->all() as $entity) {
            $days = $this->settings->retentionDaysOf($entity->getCode());
            $removed += $this->pruner->prune(ChannelCode::ENTITY, $now - $days * self::DAY_MS, [$entity->getCode()]);
        }

        return $removed;
    }
}
