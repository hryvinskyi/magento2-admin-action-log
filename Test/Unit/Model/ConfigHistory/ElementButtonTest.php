<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\ConfigHistory;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\ElementButton;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\ValueCell;
use Hryvinskyi\AdminActionLog\Plugin\Config\PrintRowHistoryButton;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\CollectionFactory;
use Magento\Framework\Data\Form\Element\Factory;
use Magento\Framework\Escaper;
use Magento\Framework\Math\Random;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * A setting's history button closes the value cell of the first row rendered for its element after it was handed
 * the button, after everything the frontend model printed there, and every later row comes out as it was
 */
class ElementButtonTest extends TestCase
{
    private const BUTTON = '<span class="config-history"><button></button></span>';
    private const CONTROL = '<tr><td class="label"></td><td class="value"><select></select> <select></select>';
    private const ROW = self::CONTROL . '</td><td></td></tr>';
    private const ROW_WITH_BUTTON = self::CONTROL . self::BUTTON . '</td><td></td></tr>';

    public function testTheFirstRowAfterTheHandOverGetsTheButtonAndEveryLaterOneComesOutUnchanged(): void
    {
        $button = new ElementButton();
        $element = $this->element();
        $printer = $this->printer($button);

        self::assertFalse($button->isHanded($element));
        $button->hand($element, self::BUTTON);

        self::assertTrue($button->isHanded($element));
        self::assertSame(self::ROW_WITH_BUTTON, $printer->afterRender($this->field(), self::ROW, $element));
        self::assertSame(self::ROW, $printer->afterRender($this->field(), self::ROW, $element), 'Printed once');
    }

    public function testARowHandedNoButtonRendersAsItDid(): void
    {
        $button = new ElementButton();
        $element = $this->element();
        $printer = $this->printer($button);

        self::assertSame(self::ROW, $printer->afterRender($this->field(), self::ROW, $element));
        $button->hand($element, self::BUTTON);
        self::assertSame(
            self::ROW_WITH_BUTTON,
            $printer->afterRender($this->field(), self::ROW, $element),
            'A row rendered before the hand-over does not use the button up'
        );
    }

    public function testMarkupWithNoPlaceForTheButtonLeavesItToTheNextMarkup(): void
    {
        $button = new ElementButton();
        $element = $this->element();
        $button->hand($element, self::BUTTON);
        $after = static fn (string $html, string $b): string => $html . $b;

        self::assertSame('<input>', $button->printWith($element, '<input>', static fn (): ?string => null));
        self::assertSame('<input>' . self::BUTTON, $button->printWith($element, '<input>', $after));
        self::assertSame(
            '<input>',
            $button->printWith($element, '<input>', $after),
            'A placement is not asked once the button was printed'
        );
    }

    public function testARowWhoseValueCellHoldsATableGetsTheButtonAfterTheTable(): void
    {
        $button = new ElementButton();
        $element = $this->element();
        $button->hand($element, self::BUTTON);
        $printer = $this->printer($button);
        $row = '<tr><td class="label"></td><td class="value"><table><tr><td>a</td></tr></table></td><td></td></tr>';

        self::assertSame(
            '<tr><td class="label"></td><td class="value"><table><tr><td>a</td></tr></table>' . self::BUTTON
            . '</td><td></td></tr>',
            $printer->afterRender($this->field(), $row, $element)
        );
    }

    public function testARowThatIsNotTextIsLeftAsItIs(): void
    {
        $button = new ElementButton();
        $element = $this->element();
        $button->hand($element, self::BUTTON);
        $printer = $this->printer($button);

        self::assertNull($printer->afterRender($this->field(), null, $element));
        self::assertSame(
            self::ROW_WITH_BUTTON,
            $printer->afterRender($this->field(), self::ROW, $element),
            'A row that is not text does not use the button up'
        );
    }

    public function testARowWithNoValueCellRendersAsItDid(): void
    {
        $button = new ElementButton();
        $element = $this->element();
        $button->hand($element, self::BUTTON);
        $row = '<tr><td colspan="4">heading</td></tr>';

        self::assertSame($row, $this->printer($button)->afterRender($this->field(), $row, $element));
    }

    /**
     * The row printer, holding the button through the given service
     *
     * @param ElementButton $button
     * @return PrintRowHistoryButton
     */
    private function printer(ElementButton $button): PrintRowHistoryButton
    {
        return new PrintRowHistoryButton($button, new ValueCell());
    }

    /**
     * The field renderer the row was rendered by
     *
     * @return Field
     */
    private function field(): Field
    {
        return $this->createMock(Field::class);
    }

    /**
     * A form element, built without Magento's container
     *
     * @return AbstractElement
     */
    private function element(): AbstractElement
    {
        return new class (
            $this->createMock(Factory::class),
            $this->createMock(CollectionFactory::class),
            $this->createMock(Escaper::class),
            [],
            $this->createMock(SecureHtmlRenderer::class),
            $this->createMock(Random::class)
        ) extends AbstractElement {
        };
    }
}
