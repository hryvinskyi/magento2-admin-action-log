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

/**
 * Records a failed sign-in with the user name that was tried and why it was refused
 */
class RecordFailedLogin implements ObserverInterface
{
    /**
     * @param AccountEventJournal $journal
     */
    public function __construct(
        private readonly AccountEventJournal $journal
    ) {
    }

    /**
     * Record the failed sign-in
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $username = $event->getData('user_name');
        $exception = $event->getData('exception');

        $this->journal->loginFailed(
            is_scalar($username) ? (string) $username : '',
            $exception instanceof \Throwable ? $exception->getMessage() : null
        );
    }
}
