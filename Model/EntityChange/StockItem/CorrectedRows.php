<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem;

/**
 * Stock item rows as they were before a quantity correction, told from the rows the correction left and the
 * correction itself
 *
 * A correction adds its quantity to, or takes it from, the `qty` of each product it lists, in the rows of one website,
 * and changes nothing else. A row's state before it is therefore the row after it with that quantity taken back, and
 * no read before the correction is needed: a write another request makes between such a read and the correction can
 * never become part of the change, so the change recorded is exactly the correction's own amount. The quantity before
 * is written with as many decimals as the stored quantity after, the way the database returns it: the database
 * rounded the quantity after to them, so the quantity before is told to them too. A stored quantity without decimals
 * takes the correction quantity's.
 *
 * A row of another website, of a product the correction does not list, or without a quantity is returned as it is.
 * An operator other than adding (`+`) or taking (`-`) tells nothing, and no row is returned for it.
 */
class CorrectedRows
{
    private const PRODUCT_ID = 'product_id';
    private const WEBSITE_ID = 'website_id';
    private const QTY = 'qty';

    /**
     * The rows before the correction, keyed as the rows after it; null when the operator is unknown
     *
     * @param array<string,array<string,mixed>> $after The stored rows after the correction, keyed by product and stock
     * @param array<int,float|int|string> $quantities The correction's quantities, keyed by product id
     * @param int $websiteId The website whose rows the correction moved
     * @param string $operator `-` for a quantity taken, `+` for a quantity put back
     * @return array<string,array<string,mixed>>|null
     */
    public function before(array $after, array $quantities, int $websiteId, string $operator): ?array
    {
        $takenBack = match ($operator) {
            '-' => 1,
            '+' => -1,
            default => null,
        };
        if ($takenBack === null) {
            return null;
        }

        $before = [];
        foreach ($after as $key => $row) {
            $quantity = $this->quantityOf($row, $quantities, $websiteId);
            $qty = $row[self::QTY] ?? null;
            $before[$key] = $quantity === null || !is_numeric($qty)
                ? $row
                : [self::QTY => $this->sum($qty, $quantity, $takenBack)] + $row;
        }

        return $before;
    }

    /**
     * The quantity the correction moved the row by, or null when it did not move the row
     *
     * @param array<string,mixed> $row
     * @param array<int,float|int|string> $quantities
     * @param int $websiteId
     * @return float|int|string|null
     */
    private function quantityOf(array $row, array $quantities, int $websiteId): float|int|string|null
    {
        $productId = $row[self::PRODUCT_ID] ?? null;
        $rowWebsiteId = $row[self::WEBSITE_ID] ?? null;
        if (!is_numeric($productId) || !is_numeric($rowWebsiteId) || (int) $rowWebsiteId !== $websiteId) {
            return null;
        }

        $quantity = $quantities[(int) $productId] ?? null;

        return is_numeric($quantity) ? $quantity : null;
    }

    /**
     * The stored quantity moved by the correction's quantity in the direction given
     *
     * Written with the stored quantity's decimals, or the correction quantity's when the stored one has none.
     *
     * @param float|int|string $stored
     * @param float|int|string $quantity
     * @param int $direction 1 to add the quantity, -1 to take it
     * @return string
     */
    private function sum(float|int|string $stored, float|int|string $quantity, int $direction): string
    {
        $decimals = $this->decimals($stored) ?: $this->decimals($quantity);

        return number_format(
            round((float) $stored + $direction * (float) $quantity, $decimals),
            $decimals,
            '.',
            ''
        );
    }

    /**
     * How many decimals a number is written with
     *
     * @param float|int|string $number
     * @return int
     */
    private function decimals(float|int|string $number): int
    {
        $text = (string) $number;
        $point = strpos($text, '.');

        return $point === false ? 0 : strlen($text) - $point - 1;
    }
}
