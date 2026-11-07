<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Plugin\Config;

use Hryvinskyi\AdminActionLog\Block\Adminhtml\System\Config\EntityTypeSettings;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\HistoryPage;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\LoggingStart;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\SectionHistory;
use Hryvinskyi\AdminActionLog\Test\Integration\Plugin\Config\Fixture\ElementHtmlWidget;
use Hryvinskyi\AdminActionLog\Test\Integration\Plugin\Config\Fixture\MonthDayField;
use Hryvinskyi\ConfigurationFields\Block\Adminhtml\System\Config\Form\Field\AbstractWidgetField;
use Hryvinskyi\ConfigurationFields\Block\Adminhtml\System\Config\Form\Field\CodeMirror\Editor;
use Hryvinskyi\ConfigurationFields\Block\Adminhtml\System\Config\Form\Field\CronEditor;
use Hryvinskyi\ConfigurationFields\Block\Adminhtml\System\Config\Form\Field\TagList;
use Hryvinskyi\ConfigurationFields\Block\Adminhtml\System\Config\Form\Field\UiSelect;
use Hryvinskyi\ConfigurationFields\Model\Field\AddonLabelWrapper;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Catalog\Block\Adminhtml\Form\Renderer\Config\DateFieldsOrder;
use Magento\Catalog\Block\Adminhtml\Form\Renderer\Config\YearRange;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Factory as ElementFactory;
use Magento\Framework\Data\FormFactory;
use Magento\Framework\View\LayoutInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Request as TestRequest;
use Magento\TestFramework\Response as TestResponse;
use Magento\TestFramework\TestCase\AbstractBackendController;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Where a setting's history button lands on Stores › Configuration, for every way a setting's control is printed:
 * Magento's controls that wrap the HTML after them in a label (a text field, a select), the one that prints it bare
 * (a multiselect), frontend models that print the element in parts (a year range, a date fields order, a month and a
 * day), the field widgets that draw their own markup between the HTML before and after the element (a tag list, a
 * cron editor, a code editor, a category select), and a frontend model that prints no control at all (a table of
 * rows). Each control gets exactly one button, closing its value cell after the whole control and outside every
 * label, so the Tab key reaches it after every part of the control and a screen reader never reads the button as part
 * of the setting's name; an element rendered twice, or a widget that also prints the element's own control, still
 * gets one.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class HistoryButtonPlacementTest extends AbstractBackendController
{
    private const BUTTON = '//button[@data-role="config-history-open"]';
    private const SECTION = 'hryvinskyi_admin_action_log';
    private const GROUP = self::SECTION . '/zz_group';
    private const PATH = self::GROUP . '/zz_field';
    private const AFTER = 'zz-after';
    private const VALUE_CELL = '//td[contains(concat(" ", @class, " "), " value ")]';
    private const CLOSES_VALUE_CELL = self::VALUE_CELL . '/*[last()][@class="config-history"]' . self::BUTTON;

    /**
     * The page the inherited access tests open: this module's own section, which needs the configuration
     *
     * @var string
     */
    protected $resource = 'Magento_Config::config';

    /**
     * @var string
     */
    protected $uri = 'backend/admin/system_config/edit/section/hryvinskyi_admin_action_log/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->service(SectionHistory::class)->_resetState();
        $this->service(LoggingStart::class)->_resetState();
    }

    public function testEachSettingOfTheSectionPageGetsOneButtonInItsValueColumnOutsideEveryLabel(): void
    {
        $xpath = $this->dom($this->page());

        foreach ([
            'select' => self::SECTION . '/admin/enabled',
            'tag list' => self::SECTION . '/admin/ignored_routes',
            'text' => self::SECTION . '/admin/retention_days',
            'multiselect' => self::SECTION . '/entity/actor_types',
            'table of rows' => self::SECTION . '/entity/types',
        ] as $kind => $path) {
            $button = self::BUTTON . '[@data-path="' . $path . '"]';
            self::assertSame(1, $this->nodes($xpath, $button), 'The ' . $kind . ' setting has one button');
            self::assertSame(
                1,
                $this->nodes($xpath, '//td[contains(concat(" ", @class, " "), " value ")]' . $button),
                'The ' . $kind . ' setting\'s button is in its value column'
            );
            self::assertSame(0, $this->nodes($xpath, '//label' . $button), 'The ' . $kind . ' button is in no label');
        }
    }

    /**
     * @param string $blockType
     * @param string $elementType
     * @param array<string, mixed> $data
     * @return void
     */
    #[DataProvider('controls')]
    public function testEveryKindOfControlGetsOneButtonAfterItsMarkupOutsideEveryLabel(
        string $blockType,
        string $elementType,
        array $data
    ): void {
        $html = $this->render($this->block($blockType), $this->element($elementType, $data));
        $xpath = $this->rows($html);

        self::assertSame(1, $this->nodes($xpath, self::BUTTON . '[@data-path="' . self::PATH . '"]'));
        self::assertSame(0, $this->nodes($xpath, '//label' . self::BUTTON), 'The button is in no label');
        self::assertSame(
            1,
            $this->nodes($xpath, '//td[contains(concat(" ", @class, " "), " value ")]' . self::BUTTON),
            'The button is in the setting\'s value column'
        );
        self::assertSame(
            1,
            $this->nodes($xpath, self::CLOSES_VALUE_CELL),
            'The button closes the setting\'s value cell, after the control and all it prints'
        );
        $after = strpos($html, self::AFTER);
        self::assertIsInt($after, 'The HTML after the element is printed');
        self::assertGreaterThan(
            $after,
            strpos($html, 'data-role="config-history-open"'),
            'The button follows the control and the HTML printed after it'
        );
    }

    /**
     * Each kind of control, by how it prints the HTML after it
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function controls(): array
    {
        $options = ['values' => [['value' => '1', 'label' => 'Yes'], ['value' => '0', 'label' => 'No']]];

        return [
            'text field: the HTML after it in a label' => [Field::class, 'text', ['value' => 'a']],
            'select: the HTML after it in a label' => [Field::class, 'select', ['value' => '1'] + $options],
            'multiselect: the HTML after it bare' => [Field::class, 'multiselect', ['value' => '1'] + $options],
            'tag list' => [TagList::class, 'text', ['value' => 'A,B']],
            'cron editor' => [CronEditor::class, 'text', ['value' => '*/5 * * * *']],
            'code editor' => [Editor::class, 'textarea', ['value' => '{}']],
            'category select' => [UiSelect::class, 'select', ['value' => '']],
            'category multiselect' => [UiSelect::class, 'multiselect', ['value' => '']],
        ];
    }

    /**
     * @param string $blockType
     * @param string $elementType
     * @param array<string, mixed> $data
     * @param int $parts How many times the frontend model prints the element
     * @return void
     */
    #[DataProvider('multiPartControls')]
    public function testAControlPrintedInPartsGetsOneButtonAfterItsLastPartOutsideEveryLabel(
        string $blockType,
        string $elementType,
        array $data,
        int $parts
    ): void {
        $html = $this->render($this->block($blockType), $this->element($elementType, $data));
        $xpath = $this->rows($html);
        $part = 'name="groups[zz_group][fields][zz_field][value][]"';

        self::assertSame($parts, substr_count($html, $part), 'Every part of the control is printed');
        self::assertSame(1, $this->nodes($xpath, self::BUTTON . '[@data-path="' . self::PATH . '"]'));
        self::assertSame(0, $this->nodes($xpath, '//label' . self::BUTTON), 'The button is in no label');
        self::assertSame(
            1,
            $this->nodes($xpath, self::CLOSES_VALUE_CELL),
            'The button closes the setting\'s value cell'
        );
        $lastPart = strrpos($html, $part);
        self::assertIsInt($lastPart);
        self::assertGreaterThan(
            $lastPart,
            strpos($html, 'data-role="config-history-open"'),
            'The button follows the last part, not the first'
        );
    }

    /**
     * Frontend models that print the element more than once, and how many times
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int}>
     */
    public static function multiPartControls(): array
    {
        return [
            'a year range: two text fields' => [YearRange::class, 'text', ['value' => '2000,2030'], 2],
            'a date fields order: three selects' => [DateFieldsOrder::class, 'select', ['value' => 'm,d,y'], 3],
            'a month and a day: two selects' => [MonthDayField::class, 'select', ['value' => '02,15'], 2],
        ];
    }

    public function testATableOfRowsThatPrintsNoControlGetsOneButtonClosingItsValueCellOutsideEveryLabel(): void
    {
        $block = $this->block(EntityTypeSettings::class);
        $element = $this->element('text', [
            'value' => [],
            'comment' => 'zz-comment',
            'tooltip' => 'zz-tooltip',
            'can_use_default_value' => true,
        ]);

        $xpath = $this->rows($this->render($block, $element) . $this->render($block, $element));

        $valueCell = '//td[contains(concat(" ", @class, " "), " value ")]';
        self::assertSame(1, $this->nodes($xpath, self::BUTTON . '[@data-path="' . self::PATH . '"]'));
        self::assertSame(1, $this->nodes($xpath, $valueCell . '/span[@class="config-history"]' . self::BUTTON));
        self::assertSame(0, $this->nodes($xpath, '//label' . self::BUTTON), 'The button is in no label');
        self::assertSame(
            1,
            $this->nodes($xpath, $valueCell . '/*[last()][@class="config-history"]'),
            'The button closes the value cell, after the table, its tooltip and the comment'
        );
        self::assertSame(0, $this->nodes($xpath, '//table//table' . self::BUTTON), 'The button is not in the table');
        self::assertSame(
            2,
            $this->nodes($xpath, '//tr/td[@class="use-default"]/following-sibling::td[not(node())]'),
            'Each row keeps its "Use Default" and hint cells'
        );
        self::assertSame(0, $this->nodes($xpath, '//td[@class="use-default"]' . self::BUTTON));
    }

    public function testAnElementRenderedTwiceGetsOneButton(): void
    {
        $block = $this->block(Field::class);
        $element = $this->element('text', ['value' => 'a']);

        $xpath = $this->rows($this->render($block, $element) . $this->render($block, $element));

        self::assertSame(1, $this->nodes($xpath, self::BUTTON));
        self::assertSame(0, $this->nodes($xpath, '//label' . self::BUTTON));
    }

    public function testAWidgetThatAlsoPrintsTheElementsOwnControlGetsOneButton(): void
    {
        $wrapper = $this->service(AddonLabelWrapper::class);
        $block = $this->_objectManager->create(ElementHtmlWidget::class, [
            'data' => [AbstractWidgetField::ADDON_LABELS => $wrapper],
        ]);
        self::assertInstanceOf(Field::class, $block);

        $xpath = $this->rows($this->render($block, $this->element('text', ['value' => 'a'])));

        self::assertSame(1, $this->nodes($xpath, self::BUTTON));
        self::assertSame(0, $this->nodes($xpath, '//label' . self::BUTTON));
    }

    public function testAPageWithoutTheHistoryGetsNoButton(): void
    {
        $layout = $this->_objectManager->create(LayoutInterface::class);
        self::assertInstanceOf(LayoutInterface::class, $layout);
        $block = $this->block(TagList::class);
        $block->setLayout($layout);

        $xpath = $this->rows($block->render($this->element('text', ['value' => 'A'])));

        self::assertSame(0, $this->nodes($xpath, self::BUTTON));
    }

    /**
     * A shared service of the container, of the type asked for
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private function service(string $type): object
    {
        $service = $this->_objectManager->get($type);
        self::assertInstanceOf($type, $service);

        return $service;
    }

    /**
     * A configuration field block of the type, built by the container
     *
     * @param string $blockType
     * @return Field
     */
    private function block(string $blockType): Field
    {
        $block = $this->_objectManager->create($blockType);
        self::assertInstanceOf(Field::class, $block);

        return $block;
    }

    /**
     * The row the configuration form prints for the element, on a page that shows the history
     *
     * @param Field $block
     * @param AbstractElement $element
     * @return string
     */
    private function render(Field $block, AbstractElement $element): string
    {
        $layout = $this->_objectManager->create(LayoutInterface::class);
        self::assertInstanceOf(LayoutInterface::class, $layout);
        $layout->getUpdate()->addHandle(HistoryPage::HANDLE);
        $block->setLayout($layout);

        return $block->render($element);
    }

    /**
     * An element of a setting that stores a value, on a form, with HTML before and after it
     *
     * @param string $type
     * @param array<string, mixed> $data
     * @return AbstractElement
     */
    private function element(string $type, array $data): AbstractElement
    {
        $form = $this->service(FormFactory::class)->create();
        $form->setData('html_id_prefix', 'cfg_');
        $element = $this->service(ElementFactory::class)->create($type, ['data' => $data + [
            'html_id' => 'zz_field',
            'name' => 'groups[zz_group][fields][zz_field][value]',
            'label' => 'Zz Field',
            'before_element_html' => '<span>zz-before</span>',
            'after_element_html' => '<span>' . self::AFTER . '</span>',
            'field_config' => ['id' => 'zz_field', 'path' => self::GROUP, 'type' => $type],
        ]]);
        $element->setForm($form);

        return $element;
    }

    /**
     * The section page's body
     *
     * @return string
     */
    private function page(): string
    {
        // Building admin links switches the shared URL model's secret key back on; the test's request URIs carry none
        $this->service(BackendUrl::class)->turnOffSecretKey();
        $request = $this->getRequest();
        self::assertInstanceOf(TestRequest::class, $request);
        $request->setMethod('GET');
        $this->dispatch($this->uri);
        $response = $this->getResponse();
        self::assertInstanceOf(TestResponse::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return (string) $response->getBody();
    }

    /**
     * Rows of the configuration form as a document to query, inside a table as the form prints them
     *
     * @param string $html
     * @return \DOMXPath
     */
    private function rows(string $html): \DOMXPath
    {
        return $this->dom('<html><body><table>' . $html . '</table></body></html>');
    }

    /**
     * A page as a document to query
     *
     * @param string $html
     * @return \DOMXPath
     */
    private function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($document);
    }

    /**
     * How many nodes an XPath expression finds
     *
     * @param \DOMXPath $xpath
     * @param string $expression
     * @return int
     */
    private function nodes(\DOMXPath $xpath, string $expression): int
    {
        $nodes = $xpath->query($expression);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        return $nodes->length;
    }
}
