<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field\Value;

use Hryvinskyi\AdminActionLog\Model\Search\Field\OptionLabels;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Names from an option source, the list the admin's forms choose from; read once and kept for the rest of the request
 *
 * Declared as a virtual type with the option source (Model\Search\Field\Value\StockStatusNames: In Stock / Out of
 * Stock).
 *
 * @api
 * @since 1.1.0
 */
class OptionSourceNames implements ValueNamesInterface, ResetAfterRequestInterface
{
    /**
     * Option labels by value; null until first asked
     *
     * @var array<string, string>|null
     */
    private ?array $labels = null;

    /**
     * @param OptionSourceInterface $source
     * @param OptionLabels $optionLabels
     */
    public function __construct(
        private readonly OptionSourceInterface $source,
        private readonly OptionLabels $optionLabels
    ) {
    }

    /**
     * @inheritDoc
     */
    public function namesOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $this->labels ??= $this->optionLabels->byValue($this->source->toOptionArray());

        return array_intersect_key($this->labels, array_flip($ids));
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->labels = null;
    }
}
