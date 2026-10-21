<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

/**
 * What happened to an entity
 */
enum ChangeOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';

    /**
     * The verb that ends an activity's action: "Product saved"
     *
     * @return string
     */
    public function verb(): string
    {
        return match ($this) {
            self::Create => 'created',
            self::Update => 'saved',
            self::Delete => 'deleted',
        };
    }
}
