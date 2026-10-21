<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueNormalizer;
use PHPUnit\Framework\TestCase;

class ValueNormalizerTest extends TestCase
{
    public function testStoresScalarsAsTextAndListsAsJson(): void
    {
        $normalizer = new ValueNormalizer();

        self::assertNull($normalizer->normalize(null));
        self::assertSame('1', $normalizer->normalize(true));
        self::assertSame('0', $normalizer->normalize(false));
        self::assertSame('42', $normalizer->normalize(42));
        self::assertSame('34.9', $normalizer->normalize(34.9));
        self::assertSame('[1,2]', $normalizer->normalize([1, 2]));
        self::assertSame('{"url":"https://a.test/é"}', $normalizer->normalize(['url' => 'https://a.test/é']));
    }

    public function testObjectsAreNotFieldValues(): void
    {
        $normalizer = new ValueNormalizer();

        self::assertTrue($normalizer->isComparable(['a' => [1, 'b', null]]));
        self::assertFalse($normalizer->isComparable(new \stdClass()));
        self::assertFalse($normalizer->isComparable(['stock_item' => new \DateTimeImmutable()]));
    }
}
