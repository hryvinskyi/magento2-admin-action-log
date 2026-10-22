<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\ActorContextInterface;
use Hryvinskyi\ActivityLog\Api\ConfigInterface as ActivityLogConfig;
use Hryvinskyi\AdminActionLog\Model\Config;

/**
 * Whether a change of an entity is recorded at all, the same for changes of saved models and changes recorded
 * through the recorder
 *
 * Changes are recorded while entity changes and the journal are both switched on, for an entity whose mode is not
 * Off, made by an actor whose type is chosen in the settings.
 */
class RecordingRules
{
    /**
     * @param Config $config
     * @param ActivityLogConfig $activityLogConfig
     * @param EntityTypeSettings $settings
     * @param ActorContextInterface $actorContext
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActivityLogConfig $activityLogConfig,
        private readonly EntityTypeSettings $settings,
        private readonly ActorContextInterface $actorContext
    ) {
    }

    /**
     * Whether entity changes are recorded at all: cheap enough to ask on every save
     *
     * @return bool
     */
    public function isOn(): bool
    {
        return $this->config->isEntityEnabled() && $this->activityLogConfig->isEnabled();
    }

    /**
     * Whether a change of the entity made now, by the current actor, is recorded
     *
     * @param string $entityCode Tracked entity code
     * @return bool
     */
    public function records(string $entityCode): bool
    {
        return $this->isOn()
            && $this->settings->modeOf($entityCode) !== DiffMode::Off
            && in_array($this->actorContext->current()->getType(), $this->config->getEntityActorTypes(), true);
    }
}
