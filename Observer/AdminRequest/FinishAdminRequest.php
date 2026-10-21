<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Observer\AdminRequest;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\AdminRequestJournal;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Records the captured admin request once its response (status, redirect) is ready to be sent
 */
class FinishAdminRequest implements ObserverInterface
{
    /**
     * @param AdminRequestJournal $journal
     */
    public function __construct(
        private readonly AdminRequestJournal $journal
    ) {
    }

    /**
     * Record the captured request with its response
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $response = $observer->getEvent()->getData('response');
        $this->journal->finish($response instanceof ResponseInterface ? $response : null);
    }
}
