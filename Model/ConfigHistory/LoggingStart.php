<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ConfigHistory;

use Hryvinskyi\ActivityLog\Api\ActivityRepositoryInterface;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\ConfigChanges;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Since when the log knows of changes: the time of the oldest change of any record it still keeps
 *
 * "Never changed" holds from that moment on. Once retention has removed the first changes, it is the oldest change
 * kept, not the day recording began. Read once per request.
 */
class LoggingStart implements ResetAfterRequestInterface
{
    /**
     * Whether the time was read in this request
     *
     * @var bool
     */
    private bool $read = false;

    /**
     * @var int|null
     */
    private ?int $ms = null;

    /**
     * @param ConfigChanges $changes
     * @param ActivityRepositoryInterface $activities
     */
    public function __construct(
        private readonly ConfigChanges $changes,
        private readonly ActivityRepositoryInterface $activities
    ) {
    }

    /**
     * Time of the oldest change kept, ms since the epoch, or null when the log keeps no change
     *
     * @return int|null
     */
    public function ms(): ?int
    {
        if (!$this->read) {
            $this->ms = $this->oldest();
            $this->read = true;
        }

        return $this->ms;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->read = false;
        $this->ms = null;
    }

    /**
     * Time of the oldest change kept, read from the journal by its activity id
     *
     * @return int|null
     */
    private function oldest(): ?int
    {
        $activityId = $this->changes->oldestActivityId();
        if ($activityId === null) {
            return null;
        }

        try {
            return $this->activities->get($activityId)->getOccurredAtMs();
        } catch (NoSuchEntityException) {
            return null;
        }
    }
}
