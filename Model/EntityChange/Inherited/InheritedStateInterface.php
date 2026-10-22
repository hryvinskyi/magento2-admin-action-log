<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Inherited;

use Magento\Framework\Model\AbstractModel;

/**
 * The values a record about to be created already had before it existed, for an entity whose records inherit them
 *
 * A setting saved for the first time had a value all along: the one its scope inherited from a wider scope or from
 * the module's defaults. Its creation is compared against that state rather than against nothing, so saving the value
 * the setting already had is no change. Register an implementation in the journal's "inheritedStates" argument under
 * the tracked entity code.
 */
interface InheritedStateInterface
{
    /**
     * The record's data as it was inherited, in the model's own fields; null when nothing is inherited
     *
     * @param AbstractModel $model The model about to be created
     * @return array<string, mixed>|null
     */
    public function stateOf(AbstractModel $model): ?array;
}
