<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Config;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\ElementButton;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\ValueCell;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Prints a setting's history button at the end of the row's value cell, outside any label
 *
 * Every setting gets its button here, once its row is rendered, whatever the frontend model drew in the value cell:
 * Magento's control, a control printed in parts (a frontend model that prints the element more than once), a field
 * widget, a table of rows. Printing it after the whole row is what keeps it after the last part of the control: a
 * printer on the element's own markup would follow the first part a frontend model prints. The button goes last into
 * the value cell (ValueCell): after the frontend model's markup, its tooltip and the setting's comment, before the
 * "Use system value" cell. The value cell closes after every label inside it, and the button is never put into the
 * HTML a field declares after its element, which Magento puts into a label of the control, so the button neither
 * joins the setting's accessible name nor sits inside a label of another control. A row whose button was already
 * printed (its element rendered again), or that has no value cell, renders as it did.
 */
class PrintRowHistoryButton
{
    /**
     * @param ElementButton $button
     * @param ValueCell $valueCell
     */
    public function __construct(
        private readonly ElementButton $button,
        private readonly ValueCell $valueCell
    ) {
    }

    /**
     * Put the button the element was handed at the end of the row's value cell, unless it was printed already
     *
     * @param Field $subject
     * @param mixed $result The row's markup; the field renderer declares no return type
     * @param AbstractElement $element
     * @return mixed
     */
    public function afterRender(Field $subject, mixed $result, AbstractElement $element): mixed
    {
        return is_string($result)
            ? $this->button->printWith($element, $result, $this->valueCell->append(...))
            : $result;
    }
}
