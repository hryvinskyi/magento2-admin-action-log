<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Field;

use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplayChain;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplayInterface;
use PHPUnit\Framework\TestCase;

/**
 * Displays asked in order, the first that knows a code or a value winning
 */
class FieldDisplayChainTest extends TestCase
{
    public function testTheFirstDisplayThatKnowsACodeOrAValueWinsAndTheNextIsAskedOnlyForTheRest(): void
    {
        $asked = [];
        $first = $this->display(['attribute_set_id' => 'Attribute Set'], ['9' => 'Top'], $asked, 'first');
        $second = $this->display(
            ['attribute_set_id' => 'attribute_set_id', 'price' => 'Price'],
            ['9' => 'nine', '10' => 'Bottom'],
            $asked,
            'second'
        );
        $chain = new FieldDisplayChain([$first, $second]);

        self::assertSame(
            ['attribute_set_id' => 'Attribute Set', 'price' => 'Price'],
            $chain->labelsOf(['attribute_set_id', 'price', 'sku'])
        );
        self::assertSame(['9' => 'Top', '10' => 'Bottom'], $chain->valuesOf('attribute_set_id', ['9', '10', '11']));
        self::assertSame([
            ['first', ['attribute_set_id', 'price', 'sku']],
            ['second', ['price', 'sku']],
            ['first', ['9', '10', '11']],
            ['second', ['10', '11']],
        ], $asked);
    }

    /**
     * A display knowing some labels and values, recording what it was asked
     *
     * @param array<string,string> $labels
     * @param array<int|string,string> $values
     * @param list<array{0: string, 1: list<mixed>}> $asked
     * @param string $name
     * @return FieldDisplayInterface
     */
    private function display(array $labels, array $values, array &$asked, string $name): FieldDisplayInterface
    {
        $display = $this->createMock(FieldDisplayInterface::class);
        $display->method('labelsOf')->willReturnCallback(
            static function (array $codes) use ($labels, &$asked, $name): array {
                $asked[] = [$name, $codes];

                return array_intersect_key($labels, array_flip(array_filter($codes, 'is_string')));
            }
        );
        $display->method('valuesOf')->willReturnCallback(
            static function (string $code, array $stored) use ($values, &$asked, $name): array {
                $asked[] = [$name, $stored];

                return array_intersect_key($values, array_flip(array_filter($stored, 'is_string')));
            }
        );

        return $display;
    }
}
