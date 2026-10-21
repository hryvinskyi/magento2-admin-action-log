<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Api\Data;

/**
 * The part of the store a change applies to: e.g. type "store", id 1, label "Default Store View"
 *
 * @api
 */
interface EntityScopeInterface
{
    public const TYPE_DEFAULT = 'default';
    public const TYPE_WEBSITE = 'website';
    public const TYPE_STORE = 'store';
    public const TYPE_GLOBAL = 'global';

    /**
     * One of the TYPE_* constants
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Website or store id; 0 for the default and global scopes
     *
     * @return int
     */
    public function getId(): int;

    /**
     * Name shown to people, as it was when the change happened
     *
     * @return string
     */
    public function getLabel(): string;
}
