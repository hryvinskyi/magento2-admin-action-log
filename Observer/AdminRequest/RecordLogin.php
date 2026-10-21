<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Observer\AdminRequest;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\AccountEventJournal;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\User\Model\User;

/**
 * Records an admin signing in
 */
class RecordLogin implements ObserverInterface
{
    /**
     * @param AccountEventJournal $journal
     */
    public function __construct(
        private readonly AccountEventJournal $journal
    ) {
    }

    /**
     * Record the sign-in
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $user = $observer->getEvent()->getData('user');
        if (!$user instanceof User) {
            return;
        }

        $id = $user->getId();
        $this->journal->loggedIn(is_scalar($id) ? (string) $id : null, (string) $user->getUserName());
    }
}
