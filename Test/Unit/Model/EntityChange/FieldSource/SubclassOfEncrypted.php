<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\FieldSource;

use Magento\Config\Model\Config\Backend\Encrypted;

/**
 * A backend model that encrypts the way its parent does, as a payment or shipping module declares one
 */
class SubclassOfEncrypted extends Encrypted
{
}
