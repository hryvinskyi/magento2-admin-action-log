<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\RecordActivity;

use Magento\Framework\AuthorizationInterface;

/**
 * Whether the signed-in admin may read the activity log, and so see a record's activity on its edit page
 *
 * Asked before anything about a record's activity is read: an admin without the permission gets no count, no panel,
 * no field hints and none of their assets.
 */
class ActivityAccess
{
    /**
     * The activity log's permission to read activities
     */
    public const RESOURCE = 'Hryvinskyi_ActivityLog::view';

    /**
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly AuthorizationInterface $authorization
    ) {
    }

    /**
     * Whether the admin may read activities
     *
     * @return bool
     */
    public function isAllowed(): bool
    {
        return $this->authorization->isAllowed(self::RESOURCE);
    }
}
