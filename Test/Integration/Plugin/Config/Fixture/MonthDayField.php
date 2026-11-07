<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Plugin\Config\Fixture;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * A setting drawn as a month select and a day select: one select element printed twice, with other options and
 * another value each time, the way a "start of the year" setting is drawn
 */
class MonthDayField extends Field
{
    /**
     * The element printed once as the month and once as the day
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $value = $element->getData('value');
        $name = $element->getData('name');
        $values = explode(',', is_string($value) ? $value : '');
        $element->setData('name', (is_string($name) ? $name : '') . '[]');

        $month = $element->setData('values', ['01' => '01', '02' => '02'])
            ->setData('value', $values[0])
            ->getElementHtml();
        $day = $element->setData('values', ['01' => '01', '15' => '15'])
            ->setData('value', $values[1] ?? null)
            ->getElementHtml();

        return $month . ' ' . $day;
    }
}
