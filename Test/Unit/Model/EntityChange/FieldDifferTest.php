<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldEquivalences;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldInputTypesInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\LineEndingsAreNotChanges;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\NoValueMeansNo;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldDiffer;
use PHPUnit\Framework\TestCase;

class FieldDifferTest extends TestCase
{
    public function testAnUpdateComparesTheFieldsTheEntityHasNow(): void
    {
        $changes = $this->differ()->diff(
            'record',
            ['price' => '49.0000', 'special_price' => null, 'sku' => '0123', 'name' => 'Bag', 'gone' => 'x'],
            ['price' => '39', 'special_price' => '34.90', 'sku' => '123', 'name' => 'Bag'],
            ChangeOperation::Update
        );

        self::assertSame([
            ['field' => 'price', 'before' => '49.0000', 'after' => '39', 'changed' => true],
            ['field' => 'special_price', 'before' => null, 'after' => '34.90', 'changed' => true],
            ['field' => 'sku', 'before' => '0123', 'after' => '123', 'changed' => true],
            ['field' => 'name', 'before' => 'Bag', 'after' => 'Bag', 'changed' => false],
        ], $changes, 'A field missing after the save is not reported as emptied.');
    }

    public function testDecimalsCompareByValueAndNoValueEqualsEmptyText(): void
    {
        $changes = $this->differ()->diff(
            'record',
            ['price' => '52.00', 'note' => null, 'qty' => '1'],
            ['price' => '52', 'note' => '', 'qty' => '1.0000'],
            ChangeOperation::Update
        );

        self::assertSame([false, false, false], array_column($changes, 'changed'));
    }

    public function testACreatedEntityHadOnlyWhatItInheritedAndADeletedOneKeepsNothing(): void
    {
        $differ = $this->differ();

        self::assertSame(
            [['field' => 'title', 'before' => null, 'after' => 'Footer', 'changed' => true]],
            $differ->diff('record', [], ['title' => 'Footer'], ChangeOperation::Create)
        );
        self::assertSame(
            [
                ['field' => 'web/cookie/lifetime', 'before' => '3600', 'after' => '3600', 'changed' => false],
                ['field' => 'web/cookie/path', 'before' => '/', 'after' => '/shop', 'changed' => true],
            ],
            $differ->diff(
                'record',
                ['web/cookie/lifetime' => '3600', 'web/cookie/path' => '/'],
                ['web/cookie/lifetime' => '3600', 'web/cookie/path' => '/shop'],
                ChangeOperation::Create
            ),
            'A setting saved for the first time is compared with the value it inherited'
        );
        self::assertSame(
            [
                ['field' => 'title', 'before' => 'Footer', 'after' => null, 'changed' => true],
                ['field' => 'content', 'before' => '', 'after' => null, 'changed' => false],
            ],
            $differ->diff(
                'record',
                ['title' => 'Footer', 'content' => ''],
                ['title' => 'ignored'],
                ChangeOperation::Delete
            )
        );
    }

    public function testAYesNoFieldHoldingNoValueReadsAsNo(): void
    {
        $changes = $this->differ()->diff(
            'record',
            ['featured' => null, 'gift_wrap' => null, 'sale' => null, 'new' => '0', 'label' => null],
            ['featured' => '0', 'gift_wrap' => '0', 'sale' => '1', 'new' => '', 'label' => '0'],
            ChangeOperation::Update
        );

        self::assertSame(
            ['featured' => false, 'gift_wrap' => true, 'sale' => true, 'new' => false, 'label' => true],
            array_combine(array_column($changes, 'field'), array_column($changes, 'changed')),
            'Only Yes/No fields read no value as No; a select and a text keep "0" as a value of its own'
        );
        self::assertSame(
            ['field' => 'featured', 'before' => null, 'after' => '0', 'changed' => false],
            $changes[0],
            'The values stay as stored'
        );
    }

    public function testTextsDifferingOnlyInLineEndingsAreTheSameForEveryField(): void
    {
        $changes = $this->differ()->diff(
            'block',
            [
                'content' => "First line\nSecond line\n",
                'old_mac' => "One\rTwo",
                'note' => "One\nTwo",
                'emptied' => "\n",
            ],
            [
                'content' => "First line\r\nSecond line\r\n",
                'old_mac' => "One\r\nTwo",
                'note' => "One\r\nThree",
                'emptied' => null,
            ],
            ChangeOperation::Update
        );

        self::assertSame(
            ['content' => false, 'old_mac' => false, 'note' => true, 'emptied' => true],
            array_combine(array_column($changes, 'field'), array_column($changes, 'changed')),
            'Only the line endings may differ; a changed word or a value removed is a change'
        );
        self::assertSame("First line\r\nSecond line\r\n", $changes[0]['after'], 'The values stay as stored');
    }

    public function testAKindOfRecordWithoutInputTypesComparesAsText(): void
    {
        $changes = $this->differ()->diff('block', ['featured' => null], ['featured' => '0'], ChangeOperation::Update);

        self::assertTrue($changes[0]['changed']);
    }

    public function testASettingKeptOnTheConfigurationBeforeAndAfterDidNotChangeWhateverItStores(): void
    {
        $changes = $this->differ()->diff(
            'record',
            ['max_sale_qty' => '0.0000', 'use_config_max_sale_qty' => '1', 'notify_stock_qty' => null],
            ['max_sale_qty' => '10000.0000', 'use_config_max_sale_qty' => '1', 'notify_stock_qty' => '1.0000'],
            ChangeOperation::Update
        );

        self::assertSame(
            ['max_sale_qty' => false, 'use_config_max_sale_qty' => false, 'notify_stock_qty' => true],
            array_combine(array_column($changes, 'field'), array_column($changes, 'changed')),
            'A field no box keeps on the configuration is compared by its value.'
        );
    }

    public function testASettingTakenOffOrPutOnTheConfigurationChanged(): void
    {
        $differ = $this->differ();

        $off = $differ->diff(
            'record',
            ['qty_increments' => '1.0000', 'use_config_qty_increments' => '1'],
            ['qty_increments' => '5.0000', 'use_config_qty_increments' => '0'],
            ChangeOperation::Update
        );
        $on = $differ->diff(
            'record',
            ['qty_increments' => '5.0000', 'use_config_qty_increments' => '0'],
            ['qty_increments' => '1.0000', 'use_config_qty_increments' => '1'],
            ChangeOperation::Update
        );
        $created = $differ->diff(
            'record',
            [],
            ['qty_increments' => '1.0000', 'use_config_qty_increments' => '1'],
            ChangeOperation::Create
        );
        $otherKind = $differ->diff(
            'other',
            ['max_sale_qty' => '0', 'use_config_max_sale_qty' => '1'],
            ['max_sale_qty' => '10000', 'use_config_max_sale_qty' => '1'],
            ChangeOperation::Update
        );

        self::assertSame([true, true], array_column($off, 'changed'));
        self::assertSame([true, true], array_column($on, 'changed'));
        self::assertSame([true, true], array_column($created, 'changed'), 'A created record had no setting to keep.');
        self::assertSame([true, false], array_column($otherKind, 'changed'), 'Only the kind that names the box.');
    }

    public function testAFollowedSettingMustNameItsBox(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new FieldEquivalences([], [], [], ['record' => ['max_sale_qty' => '']]);
    }

    /**
     * A differ for "record", whose "featured", "sale" and "new" are Yes/No fields, "gift_wrap" a select and "label" a
     * text field, and whose "max_sale_qty" and "qty_increments" a box can keep on the configuration
     *
     * @return FieldDiffer
     */
    private function differ(): FieldDiffer
    {
        $types = $this->createMock(FieldInputTypesInterface::class);
        $types->method('inputTypeOf')->willReturnCallback(static fn (string $field): ?string => [
            'featured' => 'boolean',
            'sale' => 'boolean',
            'new' => 'boolean',
            'gift_wrap' => 'select',
            'label' => 'text',
        ][$field] ?? null);

        return new FieldDiffer(new FieldEquivalences(
            ['record' => $types],
            ['boolean' => new NoValueMeansNo()],
            ['lineEndings' => new LineEndingsAreNotChanges()],
            ['record' => ['max_sale_qty' => 'use_config_max_sale_qty', 'qty_increments' => 'use_config_qty_increments']]
        ));
    }
}
