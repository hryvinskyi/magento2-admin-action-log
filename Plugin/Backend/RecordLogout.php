<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Backend;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\AccountEventJournal;
use Magento\Backend\Model\Auth;
use Magento\User\Model\User;

/**
 * Records a sign-out while the admin is still known
 */
class RecordLogout
{
    /**
     * @param AccountEventJournal $journal
     */
    public function __construct(
        private readonly AccountEventJournal $journal
    ) {
    }

    /**
     * Record the sign-out of the admin about to leave
     *
     * @param Auth $subject
     * @return void
     */
    public function beforeLogout(Auth $subject): void
    {
        $user = $subject->getUser();
        if (!$user instanceof User) {
            return;
        }

        $id = $user->getId();
        $this->journal->loggingOut(is_scalar($id) ? (string) $id : null, (string) $user->getUserName());
    }
}
