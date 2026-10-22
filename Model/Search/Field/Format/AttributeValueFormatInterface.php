<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Format;

use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;

/**
 * How stored values of one kind of attribute are written for the admin: by its options, as a date, as a decimal
 *
 * Registered in the "formats" argument of AttributeLabels; the first one that supports an attribute writes its values.
 */
interface AttributeValueFormatInterface
{
    /**
     * Whether this writes the values of the attribute
     *
     * @param AbstractAttribute $attribute
     * @return bool
     */
    public function supports(AbstractAttribute $attribute): bool;

    /**
     * The values as the admin sees them, keyed by the stored value; a value it cannot write is left out
     *
     * @param AbstractAttribute $attribute
     * @param list<string> $values
     * @return array<string, string>
     */
    public function format(AbstractAttribute $attribute, array $values): array;
}
