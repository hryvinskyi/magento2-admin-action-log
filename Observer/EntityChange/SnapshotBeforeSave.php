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
 * Takes the state of a tracked entity before it is saved
 */
class SnapshotBeforeSave implements ObserverInterface
{
    /**
     * @param EntityChangeJournal $journal
     */
    public function __construct(
        private readonly EntityChangeJournal $journal
    ) {
    }

    /**
     * Take the state of the saved model
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $model = $observer->getEvent()->getData('object');
        if ($model instanceof AbstractModel) {
            $this->journal->beforeSave($model);
        }
    }
}
