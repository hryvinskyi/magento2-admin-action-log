<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Observer\EntityChange;

use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeJournal;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Journals a save or delete once its transaction has committed
 */
class RecordCommittedChange implements ObserverInterface
{
    /**
     * @param EntityChangeJournal $journal
     */
    public function __construct(
        private readonly EntityChangeJournal $journal
    ) {
    }

    /**
     * Journal the change of the committed model
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $model = $observer->getEvent()->getData('object');
        if ($model instanceof AbstractModel) {
            $this->journal->committed($model);
        }
    }
}
