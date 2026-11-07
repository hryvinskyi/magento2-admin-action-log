<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\StockItem;

use Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem\CorrectedRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The rows before a quantity correction are the rows it left with its own quantity taken back, and nothing else moved
 */
class CorrectedRowsTest extends TestCase
{
    /**
     * @param string $operator
     * @param float|int|string $quantity
     * @param string $after
     * @param string $expectedBefore
     * @return void
     */
    #[DataProvider('corrections')]
    public function testTheQuantityBeforeIsTheQuantityAfterWithTheCorrectionTakenBack(
        string $operator,
        float|int|string $quantity,
        string $after,
        string $expectedBefore
    ): void {
        $before = (new CorrectedRows())->before(
            ['42/1' => $this->row('42', '0', $after)],
            [42 => $quantity],
            0,
            $operator
        );

        self::assertSame($expectedBefore, $before['42/1']['qty'] ?? null);
    }

    /**
     * A correction's operator and quantity, the stored quantity after it and the quantity before it
     *
     * @return array<string, array{0: string, 1: float|int|string, 2: string, 3: string}>
     */
    public static function corrections(): array
    {
        return [
            'a sale took 2' => ['-', 2, '98.0000', '100.0000'],
            'a cancellation put 3 back' => ['+', 3, '103.0000', '100.0000'],
            'a decimal quantity' => ['-', 1.5, '2.5000', '4.0000'],
            'a quantity as text' => ['+', '0.25', '1.2500', '1.0000'],
            'below zero, with backorders' => ['-', 5, '-3.0000', '2.0000'],
            'a quantity finer than the stored one, which the database rounded' => ['-', 1.33333, '98.6667', '100.0000'],
        ];
    }

    public function testAStoredQuantityWithoutDecimalsTakesTheCorrectionQuantitysOnes(): void
    {
        $before = (new CorrectedRows())->before(['42/1' => $this->row('42', '0', '8')], [42 => 1.5], 0, '-');

        self::assertSame('9.5', $before['42/1']['qty'] ?? null);
    }

    public function testRowsTheCorrectionDidNotMoveAreAsTheyAre(): void
    {
        $after = [
            '42/1' => $this->row('42', '1', '98.0000'),
            '43/1' => $this->row('43', '0', '5.0000'),
            '44/1' => ['qty' => null] + $this->row('44', '0', '0'),
            '45/1' => $this->row('45', '0', '7.0000'),
        ];

        $before = (new CorrectedRows())->before($after, [42 => 2, 44 => 1, 45 => 'not a number'], 0, '-');

        self::assertSame($after, $before, 'Another website, an unlisted product, no quantity, no amount');
    }

    public function testOnlyTheQuantityOfAMovedRowChanges(): void
    {
        $row = ['min_qty' => '1.0000', 'is_in_stock' => '1', 'item_id' => '7'] + $this->row('42', '0', '98.0000');

        $before = (new CorrectedRows())->before(['42/1' => $row], [42 => 2], 0, '-');

        self::assertSame(['qty' => '100.0000'] + $row, $before['42/1'] ?? null);
    }

    public function testAnUnknownOperatorTellsNothing(): void
    {
        $after = ['42/1' => $this->row('42', '0', '98.0000')];

        self::assertNull((new CorrectedRows())->before($after, [42 => 2], 0, '*'));
    }

    /**
     * A stock item row as the database returns it
     *
     * @param string $productId
     * @param string $websiteId
     * @param string $qty
     * @return array<string, mixed>
     */
    private function row(string $productId, string $websiteId, string $qty): array
    {
        return ['product_id' => $productId, 'stock_id' => '1', 'website_id' => $websiteId, 'qty' => $qty];
    }
}
