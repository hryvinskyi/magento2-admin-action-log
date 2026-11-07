<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ConfigHistory;

use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * The history button a setting's element holds between the field renderer handing it over and the row printing it
 *
 * An element can be rendered more than once, and its button is printed into the first row rendered after it was
 * handed that has a place for it: the element records that it printed its button, and every later row comes out
 * unchanged. The printer decides where in its markup the button goes; this class decides whether it is printed at all.
 */
class ElementButton
{
    /**
     * The element data holding the button until its row prints it
     */
    public const ELEMENT_DATA = 'hryvinskyi_config_history_button';

    /**
     * The element data recording that the button was printed
     */
    public const PRINTED = 'hryvinskyi_config_history_button_printed';

    /**
     * Whether the element was already handed a button
     *
     * @param AbstractElement $element
     * @return bool
     */
    public function isHanded(AbstractElement $element): bool
    {
        return $element->hasData(self::ELEMENT_DATA);
    }

    /**
     * Hand the element its button, to be printed into the row rendered for it
     *
     * @param AbstractElement $element
     * @param string $buttonHtml
     * @return void
     */
    public function hand(AbstractElement $element, string $buttonHtml): void
    {
        $element->setData(self::ELEMENT_DATA, $buttonHtml);
    }

    /**
     * The markup with the button the element holds where the placement puts it, the first time; else the markup
     *
     * The button counts as printed only when the placement found a place for it, so markup with no place for the
     * button leaves it to the next markup printed for the element.
     *
     * @param AbstractElement $element
     * @param string $html
     * @param callable $place Takes the markup and the button, returns the markup with the button, or null when the
     *     markup has no place for it
     * @phpstan-param callable(string, string): (string|null) $place
     * @return string
     */
    public function printWith(AbstractElement $element, string $html, callable $place): string
    {
        $button = $element->getData(self::ELEMENT_DATA);
        if (!is_string($button) || $element->getData(self::PRINTED) === true) {
            return $html;
        }

        $placed = $place($html, $button);
        if ($placed === null) {
            return $html;
        }

        $element->setData(self::PRINTED, true);

        return $placed;
    }
}
