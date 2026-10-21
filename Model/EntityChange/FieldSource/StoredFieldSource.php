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
 * The keys of the data that the entity's storage knows as fields
 */
class StoredFieldSource implements FieldSourceInterface
{
    /**
     * @inheritDoc
     */
    public function read(EntityStorageInterface $storage, AbstractModel $model, array $data): array
    {
        $fields = [];
        foreach ($data as $field => $value) {
            if ($field !== '' && $storage->isField($field)) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    /**
     * @inheritDoc
     */
    public function isSecret(AbstractModel $model, string $field): bool
    {
        return false;
    }
}
