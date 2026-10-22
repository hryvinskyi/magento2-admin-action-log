<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Config;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\HistoryButton;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Prints a control's history button right after the control's own markup, outside any label of the control
 *
 * A control of any kind prints its markup through this method, so the button follows text fields, selects and every
 * other control the same way. A control handed no button prints as it did.
 */
class PrintHistoryButton
{
    /**
     * Append the button the control was handed
     *
     * @param AbstractElement $subject
     * @param mixed $result The control's markup; Magento's controls declare no return type
     * @return mixed
     */
    public function afterGetElementHtml(AbstractElement $subject, mixed $result): mixed
    {
        $button = $subject->getData(HistoryButton::ELEMENT_DATA);

        return is_string($button) && is_string($result) ? $result . $button : $result;
    }
}
