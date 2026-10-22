<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\Automatic;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic\AutomaticFields;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic\SubmittedForm;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldEquivalences;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldInputTypesInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldDiffer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which changed fields an admin form did not ask for, over a product form described the way di.xml describes it
 *
 * @phpstan-import-type FieldChange from AutomaticFields
 */
class AutomaticFieldsTest extends TestCase
{
    private const PRODUCT_FORM = [
        'id' => ['id'],
        'values' => [
            'product[quantity_and_stock_status][{field}]',
            'product[{field}]',
            'product[stock_data][{field}]',
        ],
        'useConfig' => ['product[use_config_{field}]', 'product[stock_data][use_config_{field}]'],
        'useDefault' => ['use_default[{field}]'],
    ];

    /**
     * @var SubmittedForm
     */
    private SubmittedForm $form;

    protected function setUp(): void
    {
        $this->form = new SubmittedForm();
    }

    public function testAFieldTheRecordKeepsThatTheFormDidNotSubmitWasNotAskedFor(): void
    {
        $this->form->begin(['id' => '335', 'product' => ['name' => 'Bag', 'price' => '39.00']]);

        self::assertSame(['image_label', 'thumbnail_label'], $this->automatic(
            ['image_label' => 'Bag', 'thumbnail_label' => 'Bag', 'price' => '34.00'],
            ['image_label' => null, 'thumbnail_label' => null, 'price' => '39.00']
        ), 'The price was asked for; the labels are fields of the record the form never sent');
    }

    public function testAFieldAnotherStoreKeepsIsNotJudgedByItsAbsence(): void
    {
        $this->form->begin(['id' => '335', 'product' => ['name' => 'Bag'], 'sources' => [['quantity' => '95']]]);

        self::assertSame([], $this->automatic(['quantity' => '100.0000'], ['quantity' => '95.0000']));
    }

    public function testAValueSubmittedAsItWasBeforeWasNotAskedFor(): void
    {
        $this->form->begin(['id' => '335', 'product' => ['price' => '34.00', 'weight' => '2']]);

        self::assertSame(
            ['price'],
            $this->automatic(['price' => '34.000000', 'weight' => '1.0000'], ['price' => '35.00', 'weight' => '2'])
        );
    }

    public function testASettingThatFollowedTheConfigurationAndWasSentBackBlankWasNotAskedFor(): void
    {
        $this->form->begin(['id' => '335', 'product' => [
            'gift_message_available' => '0',
            'use_config_gift_message_available' => '0',
            'gift_wrapping_available' => '1',
            'use_config_gift_wrapping_available' => '0',
            'msrp_display_actual_price_type' => '0',
        ]]);

        self::assertSame(['gift_message_available'], $this->automatic(
            [
                'gift_message_available' => null,
                'gift_wrapping_available' => null,
                'msrp_display_actual_price_type' => null,
            ],
            [
                'gift_message_available' => '0',
                'gift_wrapping_available' => '1',
                'msrp_display_actual_price_type' => '0',
            ]
        ), 'Switched on was asked for; blank without a use-config box may have been chosen');
    }

    public function testABoxThatStayedTickedKeepsTheFieldUnasked(): void
    {
        $this->form->begin(['id' => '335', 'store' => '1', 'product' => [
            'status' => '1',
            'stock_data' => [
                'min_qty' => '0',
                'use_config_min_qty' => '1',
                'notify_stock_qty' => '1',
                'use_config_notify_stock_qty' => '1',
            ],
        ], 'use_default' => ['status' => '1', 'name' => '1']]);

        self::assertSame(['status', 'min_qty'], $this->automatic(
            [
                'status' => null,
                'name' => 'Bag',
                'min_qty' => '3',
                'use_config_min_qty' => '1',
                'notify_stock_qty' => '5',
                'use_config_notify_stock_qty' => '0',
            ],
            [
                'status' => '1',
                'name' => null,
                'min_qty' => '0',
                'use_config_min_qty' => '1',
                'notify_stock_qty' => '1',
                'use_config_notify_stock_qty' => '1',
            ]
        ), 'Inherited before, or a box ticked before; a box just ticked was asked for');
    }

    public function testUseDefaultTickedOverAStoreViewValueOfOneWasAskedFor(): void
    {
        $this->form->begin(['id' => '335', 'store' => '1', 'product' => ['status' => '1'], 'use_default' => [
            'status' => '1',
        ]]);

        self::assertSame(
            [],
            $this->automatic(['status' => '1'], ['status' => null]),
            'The store view held its own "1": the box was clear before and the admin ticked it'
        );
    }

    public function testTheQuantityTheFormEditsIsReadBeforeTheOneItLoaded(): void
    {
        $this->form->begin(['id' => '335', 'product' => [
            'quantity_and_stock_status' => ['qty' => '95', 'is_in_stock' => '0'],
            'stock_data' => ['qty' => '100', 'is_in_stock' => '1', 'min_qty' => '2'],
        ]]);

        self::assertSame(['min_qty'], $this->automatic(
            ['qty' => '100.0000', 'is_in_stock' => '1', 'min_qty' => '2'],
            ['qty' => '95.0000', 'is_in_stock' => '0', 'min_qty' => '0']
        ), 'The typed quantity and status were asked for; a stock field the form loaded and sent back was not');
    }

    public function testAQuantityTheFormSentBackAsLoadedWasNotAskedFor(): void
    {
        $this->form->begin(['id' => '335', 'product' => [
            'quantity_and_stock_status' => ['qty' => '100'],
            'stock_data' => ['qty' => '100'],
        ]]);

        self::assertSame(['qty'], $this->automatic(['qty' => '100.0000'], ['qty' => '90.0000']));
    }

    public function testAListTheFormDidNotSubmitWasAskedFor(): void
    {
        $this->form->begin(['id' => '335', 'product' => ['name' => 'Bag']]);

        self::assertSame(['image_label'], $this->automatic(
            ['category_ids' => '["42"]', 'website_ids' => '[1]', 'image_label' => 'Bag', 'color_set' => '4,5'],
            ['category_ids' => '[]', 'website_ids' => null, 'image_label' => null, 'color_set' => null]
        ), 'Emptied lists are left out of what the form submits; an image label is not a list');
    }

    public function testAJsonMapIsNotAList(): void
    {
        $this->form->begin(['id' => '335', 'product' => []]);

        self::assertSame(['tier_map'], $this->automatic(['tier_map' => '{"1":"5"}'], ['tier_map' => null]));
    }

    public function testAValueSubmittedAsAListWasAskedFor(): void
    {
        $this->form->begin(['id' => '335', 'product' => ['category_ids' => ['42']]]);

        self::assertSame([], $this->automatic(['category_ids' => null], ['category_ids' => '["42"]']));
    }

    #[DataProvider('otherRecords')]
    public function testOnlyTheRecordTheFormSavesCanBeChangedAutomatically(?string $entityId, mixed $formId): void
    {
        $this->form->begin(['id' => $formId, 'product' => ['name' => 'Bag']]);

        self::assertSame([], (new AutomaticFields($this->form, $this->differ(), ['product' => self::PRODUCT_FORM], [
            'product' => $this->recordFields(),
        ]))->of('product', $entityId, [$this->change('image_label', 'Bag', null)], ['image_label' => 'Bag']));
    }

    /**
     * @return array<string, array{0: string|null, 1: mixed}>
     */
    public static function otherRecords(): array
    {
        return [
            'another record' => ['336', '335'],
            'a new record' => ['336', null],
            'a list of ids' => ['335', ['335']],
            'no id recorded' => [null, '335'],
        ];
    }

    public function testNothingIsAutomaticOutsideAnAdminActionOrForAKindWithoutAForm(): void
    {
        self::assertSame([], $this->automatic(['image_label' => 'Bag'], ['image_label' => null]));

        $this->form->begin(['id' => '335', 'product' => []]);
        $rule = new AutomaticFields($this->form, $this->differ(), ['product' => self::PRODUCT_FORM]);
        self::assertSame(
            [],
            $rule->of('order', '335', [$this->change('status', 'new', 'holded')], ['status' => 'new'])
        );

        $this->form->end();
        self::assertSame([], $this->automatic(['image_label' => 'Bag'], ['image_label' => null]));
    }

    /**
     * @param array<mixed> $form
     */
    #[DataProvider('wrongForms')]
    public function testAFormDescribedWronglyFailsAtConstruction(array $form): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AutomaticFields($this->form, $this->differ(), ['product' => $form]);
    }

    /**
     * @return array<string, array{0: array<mixed>}>
     */
    public static function wrongForms(): array
    {
        return [
            'an unknown part' => [['id' => ['id'], 'values' => ['{field}'], 'labels' => ['x']]],
            'no record id' => [['values' => ['product[{field}]']]],
            'no values' => [['id' => ['id']]],
            'a value without the field' => [['id' => ['id'], 'values' => ['product[name]']]],
            'the field not last' => [['id' => ['id'], 'values' => ['product[{field}][value]']]],
            'an id holding the field' => [['id' => ['{field}'], 'values' => ['{field}']]],
            'a malformed name' => [['id' => ['id'], 'values' => ['product[{field}']]],
            'not a list' => [['id' => 'id', 'values' => ['{field}']]],
        ];
    }

    /**
     * The automatic fields of a change of product 335, every field that differs counting as changed
     *
     * @param array<string,string|null> $before
     * @param array<string,string|null> $after
     * @return list<string>
     */
    private function automatic(array $before, array $after): array
    {
        $differ = $this->differ();
        $changed = [];
        foreach ($after as $field => $value) {
            if (!$differ->same('product', $field, $before[$field] ?? null, $value)) {
                $changed[] = $this->change($field, $before[$field] ?? null, $value);
            }
        }

        return (new AutomaticFields($this->form, $differ, ['product' => self::PRODUCT_FORM], [
            'product' => $this->recordFields(),
        ]))->of('product', '335', $changed, $before);
    }

    /**
     * @param string $field
     * @param string|null $before
     * @param string|null $after
     * @return FieldChange
     */
    private function change(string $field, ?string $before, ?string $after): array
    {
        return ['field' => $field, 'before' => $before, 'after' => $after, 'changed' => true];
    }

    /**
     * The product's attributes: every field but those another store keeps
     *
     * @return FieldInputTypesInterface
     */
    private function recordFields(): FieldInputTypesInterface
    {
        $types = $this->createMock(FieldInputTypesInterface::class);
        $types->method('inputTypeOf')->willReturnCallback(
            static fn (string $field): ?string => match (true) {
                in_array($field, ['quantity', 'source_status'], true) => null,
                $field === 'color_set' => 'multiselect',
                default => 'text',
            }
        );

        return $types;
    }

    /**
     * @return FieldDiffer
     */
    private function differ(): FieldDiffer
    {
        return new FieldDiffer(new FieldEquivalences());
    }
}
