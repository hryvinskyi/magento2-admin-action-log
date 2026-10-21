<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Storage;

use Magento\Framework\Model\AbstractModel;

/**
 * Where a tracked entity is stored: which keys of its data are real fields, and how to read it as stored
 *
 * Model data also carries flags and helpers that are not fields ("is_changed_categories", "form_key"); only the
 * keys the storage knows are compared.
 */
interface EntityStorageInterface
{
    /**
     * Whether a key of the model's data is a stored field
     *
     * @param string $field
     * @return bool
     */
    public function isField(string $field): bool;

    /**
     * The entity's data as stored, read without touching the model being saved; null when it is not stored
     *
     * @param AbstractModel $model The model being saved, for its scope (e.g. the store it is edited in)
     * @param string $id
     * @return array<string, mixed>|null
     */
    public function load(AbstractModel $model, string $id): ?array;
}
