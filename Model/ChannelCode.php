<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model;

/**
 * Codes of the channels this module records; the channels themselves are declared in di.xml
 */
class ChannelCode
{
    public const ADMIN = 'admin';
    public const ENTITY = 'entity';
}
