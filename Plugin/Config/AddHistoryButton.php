<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Config;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\HistoryButton;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\HistoryPage;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\SectionHistory;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\StoredSetting;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\LocalizedException;

/**
 * Puts a setting's history button right after its control on Stores › Configuration
 *
 * The button joins the markup the control prints after itself, so it sits inside the value column, before the
 * setting's comment and the "Use system value" checkbox, whatever the row around it looks like. Only a setting that
 * stores a value gets one, only once, and only on a page that shows the history (see HistoryPage).
 */
class AddHistoryButton
{
    /**
     * Element data that marks a control already given its button
     */
    private const GIVEN = 'hryvinskyi_config_history_button';

    /**
     * @param HistoryPage $page
     * @param StoredSetting $storedSetting
     * @param SectionHistory $history
     * @param HistoryButton $button
     */
    public function __construct(
        private readonly HistoryPage $page,
        private readonly StoredSetting $storedSetting,
        private readonly SectionHistory $history,
        private readonly HistoryButton $button
    ) {
    }

    /**
     * Append the history button to the markup printed after the setting's control
     *
     * @param Field $subject
     * @param AbstractElement $element
     * @return array<mixed>|null Null: the arguments stay as they are
     */
    public function beforeRender(Field $subject, AbstractElement $element): ?array
    {
        $fieldConfig = $element->getData('field_config');
        $setting = is_array($fieldConfig) ? $this->storedSetting->of($fieldConfig) : null;
        if ($setting === null || $element->getData(self::GIVEN) === true || !$this->isHistoryPage($subject)) {
            return null;
        }

        $label = $element->getData('label');
        $after = $element->getData('after_element_html');
        $element->setData(self::GIVEN, true);
        $element->setData('after_element_html', (is_scalar($after) ? (string) $after : '') . $this->button->html(
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
