<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\EntityStorageInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Reads the comparable fields out of an entity state
 */
interface FieldSourceInterface
{
    /**
     * The comparable fields of the state, keyed by field name
     *
     * @param EntityStorageInterface $storage
     * @param AbstractModel $model
     * @param array<string,mixed> $data
     * @return array<string, mixed>
     */
    public function read(EntityStorageInterface $storage, AbstractModel $model, array $data): array;

    /**
     * Whether the field holds a secret by the nature of the entity, whatever its name
     *
     * @param AbstractModel $model
     * @param string $field
     * @return bool
     */
    public function isSecret(AbstractModel $model, string $field): bool;
}
