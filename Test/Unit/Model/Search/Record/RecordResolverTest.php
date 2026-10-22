<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Record;

use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordLookupInterface;
use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordResolver;
use PHPUnit\Framework\TestCase;

class RecordResolverTest extends TestCase
{
    public function testANaturalKeyWinsOverNames(): void
    {
        $lookup = $this->lookup(['24-MB01' => ['1245']], ['24-MB01' => ['7', '8']]);

        self::assertSame(['1245'], (new RecordResolver($lookup))->idsFor('24-MB01'));
    }

    public function testANumberIsAlsoTheIdWhenTheKindIsSearchableById(): void
    {
        $lookup = $this->lookup(['1245' => ['77']], ['1245' => ['9']]);

        self::assertSame(['77', '1245'], (new RecordResolver($lookup, true))->idsFor('1245'));
        self::assertSame(['1245'], (new RecordResolver($this->lookup([], []), true))->idsFor('01245'));
        self::assertSame(['77'], (new RecordResolver($lookup, false))->idsFor('1245'));
    }

    public function testNamesAreSearchedOnlyWhenNothingElseMatchesAndAreCapped(): void
    {
        $lookup = $this->lookup([], ['duffle' => ['3', '4', '3']]);

        self::assertSame(['3', '4'], (new RecordResolver($lookup, true))->idsFor('duffle'));
        self::assertSame([RecordResolver::NAME_LIMIT], $lookup->limits);
    }

    public function testNothingFoundIsNoIds(): void
    {
        self::assertSame([], (new RecordResolver($this->lookup([], []), true))->idsFor('nothing'));
    }

    public function testAnIdNamesItselfOnly(): void
    {
        $lookup = $this->lookup(['1245' => ['77']], ['1245' => ['9']]);

        self::assertSame(['1245'], (new RecordResolver($lookup, true))->idsOf('1245'));
    }

    public function testTheExactFormAlsoNamesTheRecordWhoseNaturalKeyItIsWhenTheKindIsNumberedSo(): void
    {
        $lookup = $this->lookup(['100000001' => ['3']], ['100000001' => ['9']]);

        self::assertSame(['100000001', '3'], (new RecordResolver($lookup, false, true))->idsOf('100000001'));
        self::assertSame(['3'], (new RecordResolver($this->lookup(['3' => ['3']], []), false, true))->idsOf('3'));
        self::assertSame(['42'], (new RecordResolver($lookup, false, true))->idsOf('42'), 'Never a name');
    }

    /**
     * @param array<array-key, list<string>> $exact
     * @param array<array-key, list<string>> $named
     * @return RecordLookupInterface&object{limits: list<int>}
     */
    private function lookup(array $exact, array $named): RecordLookupInterface
    {
        return new class ($exact, $named) implements RecordLookupInterface {
            /**
             * @var list<int>
             */
            public array $limits = [];

            /**
             * @param array<array-key, list<string>> $exact
             * @param array<array-key, list<string>> $named
             */
            public function __construct(private readonly array $exact, private readonly array $named)
            {
            }

            public function exact(string $text): array
            {
                return $this->exact[$text] ?? [];
            }

            public function named(string $text, int $limit): array
            {
                $this->limits[] = $limit;

                return $this->named[$text] ?? [];
            }
        };
    }
}
