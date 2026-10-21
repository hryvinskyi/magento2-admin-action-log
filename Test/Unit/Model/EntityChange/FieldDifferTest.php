<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldDiffer;
use PHPUnit\Framework\TestCase;

class FieldDifferTest extends TestCase
{
    public function testAnUpdateComparesTheFieldsTheEntityHasNow(): void
    {
        $changes = (new FieldDiffer())->diff(
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
        $changes = (new FieldDiffer())->diff(
            ['price' => '52.00', 'note' => null, 'qty' => '1'],
            ['price' => '52', 'note' => '', 'qty' => '1.0000'],
            ChangeOperation::Update
        );

        self::assertSame([false, false, false], array_column($changes, 'changed'));
    }

    public function testACreatedEntityHadNothingAndADeletedOneKeepsNothing(): void
    {
        $differ = new FieldDiffer();

        self::assertSame(
            [['field' => 'title', 'before' => null, 'after' => 'Footer', 'changed' => true]],
            $differ->diff(['title' => 'stale'], ['title' => 'Footer'], ChangeOperation::Create)
        );
        self::assertSame(
            [
                ['field' => 'title', 'before' => 'Footer', 'after' => null, 'changed' => true],
                ['field' => 'content', 'before' => '', 'after' => null, 'changed' => false],
            ],
            $differ->diff(['title' => 'Footer', 'content' => ''], ['title' => 'ignored'], ChangeOperation::Delete)
        );
    }
}
