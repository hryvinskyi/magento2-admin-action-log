<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\Data\Search\ValueKind;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueKinds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one rule deciding how a changed field's values are shown, on the activity page and in search results alike
 */
class ValueKindsTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null, bool, ValueKind}>
     */
    public static function kinds(): array
    {
        $long = str_repeat('a', 161);

        return [
            'two short values' => ['34.00', '39.00', false, ValueKind::Short],
            'a value set for the first time' => [null, 'Joust Duffle Bag', false, ValueKind::Short],
            'exactly 160 characters' => [str_repeat('a', 160), 'b', false, ValueKind::Short],
            'over 160 characters before' => [$long, 'b', false, ValueKind::Long],
            'over 160 characters after' => ['a', $long, false, ValueKind::Long],
            'three line breaks' => ["one\ntwo\nthree\nfour", 'x', false, ValueKind::Long],
            'two line breaks' => ["one\ntwo\nthree", 'x', false, ValueKind::Short],
            'two JSON lists' => ['["a","b"]', '["b","c"]', false, ValueKind::Json],
            'a JSON list set for the first time' => [null, '["a"]', false, ValueKind::Json],
            'a JSON list emptied' => ['["a"]', '', false, ValueKind::Json],
            'a long JSON list' => ['[' . str_repeat('"item",', 40) . '"end"]', '[]', false, ValueKind::Json],
            'JSON objects are not lists' => ['{"a":1}', '{"a":2}', false, ValueKind::Short],
            'a list on one side only' => ['["a"]', 'plain', false, ValueKind::Short],
            'broken JSON' => ['["a"', '["a"]', false, ValueKind::Short],
            'nothing on either side' => [null, '', false, ValueKind::Short],
            'masked wins over everything' => [$long, '["a"]', true, ValueKind::Masked],
        ];
    }

    #[DataProvider('kinds')]
    public function testDecidesTheKindOfAFieldsValues(
        ?string $before,
        ?string $after,
        bool $masked,
        ValueKind $expected
    ): void {
        self::assertSame($expected, (new ValueKinds())->kindOf($before, $after, $masked));
    }

    public function testCallsAValueLongByLengthOrLineBreaksAloneWhateverItHolds(): void
    {
        $kinds = new ValueKinds();
        $longList = '[' . str_repeat('"item",', 40) . '"end"]';

        self::assertTrue($kinds->isLong($longList, null), 'A long JSON list is still clipped on the activity page');
        self::assertTrue($kinds->isLong(null, "a\nb\nc\nd"));
        self::assertFalse($kinds->isLong('short', null));
        self::assertFalse($kinds->isLong(null, null));
    }
}
