<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Config;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\ElementButton;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\HistoryButton;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\HistoryPage;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\SectionHistory;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\StoredSetting;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;

/**
 * Gives a setting on Stores › Configuration its history button, which closes the setting's value cell
 *
 * The button is handed to the setting's element (ElementButton) before the row is rendered, and goes last into the
 * row's value cell once it is (PrintRowHistoryButton): after the whole control however the frontend model prints it
 * (once, in parts such as a month and a day select, or as a widget or a table of its own), after its tooltip and
 * comment, before the "Use system value" cell. Its look and its place on screen come from the stylesheet, so where it
 * is in the markup decides only the tab order, which then runs through the whole control before the button, and that
 * it is in no label. It is never put into the HTML a field declares after its element, which Magento and the field
 * widgets put into a label of the control: a screen reader would read the button's name as part of the setting's,
 * and a button inside a label of another control is invalid HTML. Only a setting that stores a value gets one, only
 * once, and only on a page that shows the history (see HistoryPage).
 */
class AddHistoryButton
{
    /**
     * @param HistoryPage $page
     * @param StoredSetting $storedSetting
     * @param SectionHistory $history
     * @param HistoryButton $button
     * @param ElementButton $elementButton
     */
    public function __construct(
        private readonly HistoryPage $page,
        private readonly StoredSetting $storedSetting,
        private readonly SectionHistory $history,
        private readonly HistoryButton $button,
        private readonly ElementButton $elementButton
    ) {
    }

    /**
     * Hand the setting's element its history button
     *
     * @param Field $subject
     * @param AbstractElement $element
     * @return array<mixed>|null Null: the arguments stay as they are
     */
    public function beforeRender(Field $subject, AbstractElement $element): ?array
    {
        $fieldConfig = $element->getData('field_config');
        $setting = is_array($fieldConfig) ? $this->storedSetting->of($fieldConfig) : null;
        if ($setting === null
            || $this->elementButton->isHanded($element)
            || !$this->isHistoryPage($subject)
        ) {
            return null;
        }

        $label = $element->getData('label');
        $this->elementButton->hand($element, $this->button->html(
            $setting['path'],
            trim(strip_tags(is_scalar($label) || $label instanceof \Stringable ? (string) $label : '')),
            $this->history->of($setting['section'], $setting['path'])
        ));

        return null;
    }

    /**
     * Whether the field is rendered on a page that shows the history
     *
     * @param Field $subject
     * @return bool
     */
    private function isHistoryPage(Field $subject): bool
    {
        try {
            return $this->page->isShownOn($subject->getLayout());
        } catch (LocalizedException) {
            return false;
        }
    }
}
