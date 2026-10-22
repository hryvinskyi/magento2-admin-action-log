<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;

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

    /**
     * What a search result's sentence says the change did to its record
     *
     * @return Verb
     */
    public function sentenceVerb(): Verb
    {
        return match ($this) {
            self::Create => Verb::Created,
            self::Update => Verb::Changed,
            self::Delete => Verb::Deleted,
        };
    }
}
