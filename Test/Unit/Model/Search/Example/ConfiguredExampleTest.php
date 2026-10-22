<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Example;

use Hryvinskyi\ActivityLog\Api\Data\Search\RangePreset;
use Hryvinskyi\AdminActionLog\Model\Search\Example\ConfiguredExample;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfiguredExampleTest extends TestCase
{
    public function testItKeepsTheFiltersInOrderWithTheirRange(): void
    {
        $example = new ConfiguredExample(
            'Who changed product prices',
            [
                'field' => ['key' => 'field', 'value' => ' price '],
                'entity' => ['key' => 'entity', 'value' => 'product'],
            ],
            '7d'
        );

        self::assertSame('Who changed product prices', $example->getTitle());
        self::assertSame(
            [['key' => 'field', 'value' => 'price'], ['key' => 'entity', 'value' => 'product']],
            $example->getChips()
        );
        self::assertSame(RangePreset::LastSevenDays, $example->getPreset());
    }

    /**
     * @return array<string, array{0: string, 1: array<string, array<string, mixed>>, 2: string}>
     */
    public static function malformed(): array
    {
        return [
            'no title' => [' ', ['a' => ['key' => 'field', 'value' => 'price']], '30d'],
            'no filter' => ['Title', [], '30d'],
            'a malformed key' => ['Title', ['a' => ['key' => 'Field', 'value' => 'price']], '30d'],
            'an empty value' => ['Title', ['a' => ['key' => 'field', 'value' => ' ']], '30d'],
            'an unknown range' => ['Title', ['a' => ['key' => 'field', 'value' => 'price']], '90d'],
        ];
    }

    /**
     * @param string $title
     * @param array<string, array<string, mixed>> $chips
     * @param string $preset
     */
    #[DataProvider('malformed')]
    public function testAMalformedDeclarationFailsAtConstruction(string $title, array $chips, string $preset): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ConfiguredExample($title, $chips, $preset);
    }
}
