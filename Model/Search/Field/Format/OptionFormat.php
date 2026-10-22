<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Format;

use Hryvinskyi\AdminActionLog\Model\Search\Field\OptionLabels;
use Magento\Eav\Model\Entity\Attribute\AbstractAttribute;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The value of an attribute with options, named by its option's admin label ("2" → "Disabled"; each of a
 * multiselect's comma-separated option ids in turn)
 *
 * An attribute's options are read once, from the options its source offers the admin, and kept for the rest of the
 * request.
 */
class OptionFormat implements AttributeValueFormatInterface, ResetAfterRequestInterface
{
    private const MULTISELECT = 'multiselect';

    /**
     * Option labels by value of each attribute read so far, keyed by attribute id
     *
     * @var array<string, array<string, string>>
     */
    private array $options = [];

    /**
     * @param OptionLabels $optionLabels
     */
    public function __construct(
        private readonly OptionLabels $optionLabels
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(AbstractAttribute $attribute): bool
    {
        return $attribute->usesSource();
    }

    /**
     * @inheritDoc
     */
    public function format(AbstractAttribute $attribute, array $values): array
    {
        $id = $attribute->getAttributeId();
        $key = is_scalar($id) ? (string) $id : (string) $attribute->getAttributeCode();
        $this->options[$key] ??= $this->optionLabels->byValue($attribute->getSource()->getAllOptions());
        $several = $attribute->getFrontendInput() === self::MULTISELECT;

        $shown = [];
        foreach ($values as $value) {
            $name = $this->optionLabels->name($this->options[$key], $value, $several);
            if ($name !== null) {
                $shown[$value] = $name;
            }
        }

        return $shown;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->options = [];
    }
}
