<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

/**
 * How much of an entity a change keeps
 */
enum DiffMode: string
{
    /**
     * The fields whose value changed
     */
    case Changed = 'changed';

    /**
     * Every compared field, changed or not
     */
    case Snapshot = 'snapshot';

    /**
     * Nothing: changes of the entity are not recorded
     */
    case Off = 'off';
}
