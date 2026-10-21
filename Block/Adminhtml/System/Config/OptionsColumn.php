<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Block\Adminhtml\System\Config;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\View\Element\Context;
use Magento\Framework\View\Element\Html\Select;

/**
 * A select cell of a settings table, filled from an option source
 */
class OptionsColumn extends Select
{
    /**
     * @param Context $context
     * @param OptionSourceInterface $optionSource
     * @param array<string,mixed> $data
     */
    public function __construct(
        Context $context,
        private readonly OptionSourceInterface $optionSource,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Name of the input, set by the table for each row template
     *
     * @param string $value
     * @return $this
     */
    public function setInputName(string $value): self
    {
        $this->setData('name', $value);

        return $this;
    }

    /**
     * Id of the input, set by the table for each row template
     *
     * @param string $value
     * @return $this
     */
    public function setInputId(string $value): self
    {
        $this->setData('id', $value);

        return $this;
    }

    /**
     * @inheritDoc
     */
    protected function _toHtml(): string
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->optionSource->toOptionArray());
        }

        return parent::_toHtml();
    }
}
