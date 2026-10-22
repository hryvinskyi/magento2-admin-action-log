<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Api;

/**
 * Which fields of one kind of tracked entity hold secrets, told from the field alone, without a record
 *
 * Register an implementation in the "secretFields" argument of the tracked entity pool under the entity's code. The
 * pool asks it for every change of that entity, made by saving a model or recorded without one, so a field is masked
 * the same way whichever path recorded it. Field names that read as secrets ("password", "api_key") are masked
 * whatever an implementation says; it adds the fields whose names do not tell, such as a setting the configuration
 * keeps encrypted.
 *
 * @api
 * @since 1.1.0
 */
interface SecretFieldsInterface
{
    /**
     * Whether the field's values are secret and must be masked
     *
     * @param string $field
     * @return bool
     */
    public function isSecretField(string $field): bool;
}
