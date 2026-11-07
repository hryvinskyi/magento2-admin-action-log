<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Plugin\Config\Fixture;

use Hryvinskyi\ConfigurationFields\Block\Adminhtml\System\Config\Form\Field\AbstractWidgetField;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * A field widget whose markup is the element's own control, so the element's markup is printed both by the element
 * and inside the widget's label wrapper
 */
class ElementHtmlWidget extends AbstractWidgetField
{
    /**
     * The element's own control as the widget
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function widgetHtml(AbstractElement $element): string
    {
        return $element->getElementHtml();
    }
}
