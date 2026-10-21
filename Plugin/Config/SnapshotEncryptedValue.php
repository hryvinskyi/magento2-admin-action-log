<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Config;

use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeJournal;
use Magento\Config\Model\Config\Backend\Encrypted;

/**
 * An encrypted setting prepares its own save without the usual "before save" event, so its state is taken here
 */
class SnapshotEncryptedValue
{
    /**
     * @param EntityChangeJournal $journal
     */
    public function __construct(
        private readonly EntityChangeJournal $journal
    ) {
    }

    /**
     * Take the state of the encrypted setting
     *
     * @param Encrypted $subject
     * @return void
     */
    public function beforeBeforeSave(Encrypted $subject): void
    {
        $this->journal->beforeSave($subject);
    }
}
