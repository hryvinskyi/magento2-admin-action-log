<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

/**
 * Field codes as they are stored with a change, found by the label an admin sees for them
 *
 * Labels are compared without regard to case. A code returned need not occur in any change.
 */
interface FieldLabelSourceInterface
{
    /**
     * Codes of the fields whose label is the text, whole ("Enable Product" → status)
     *
     * @param string $label
     * @return list<string>
     */
    public function codesLabelled(string $label): array;

    /**
     * Codes of the fields whose label contains the text
     *
     * @param string $text
     * @return list<string>
     */
    public function codesLabelledWith(string $text): array;
}
