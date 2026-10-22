<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Suggestion;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Search\RecentSubjectsInterface;
use Hryvinskyi\ActivityLog\Model\Data\Search\Suggestion;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordKeysInterface;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\RecordSuggestions;
use PHPUnit\Framework\TestCase;

/**
 * A record is suggested by a value that picks exactly it: its natural key when it has a usable one, else "#<id>"
 */
class RecordSuggestionsTest extends TestCase
{
    public function testARecordIsPickedByItsNaturalKeyOrByItsId(): void
    {
        $skus = $this->createMock(RecordKeysInterface::class);
        $skus->expects(self::once())->method('keysOf')->with(['1', '2', '4', '3'])
            ->willReturn(['1' => 'aal-tote', '2' => '20261022', '3' => 'aal-other']);
        $provider = $this->provider(
            [
                ['type' => 'product', 'id' => '1', 'label' => 'Product aal-tote · Tote', 'count' => 3],
                ['type' => 'product', 'id' => '2', 'label' => 'Product 20261022 · Numbered', 'count' => 1],
                ['type' => 'product', 'id' => '4', 'label' => 'Product aal-gone · Gone', 'count' => 1],
                ['type' => 'product', 'id' => '3', 'label' => '', 'count' => 2],
            ],
            ['product'],
            ['product' => $skus]
        );

        self::assertSame(
            [
                ['aal-tote', 'Product aal-tote · Tote', 'Product', 3],
                ['#2', 'Product 20261022 · Numbered', 'Product', 1],
                ['#4', 'Product aal-gone · Gone', 'Product', 1],
                ['aal-other', 'Product #3', 'Product', 2],
            ],
            $this->rows($provider)
        );
    }

    public function testAKindWithoutNaturalKeysIsPickedByIdAndAnUnknownKindIsNamedByItsCode(): void
    {
        $subjects = [
            ['type' => 'cart_price_rule', 'id' => '7', 'label' => 'Cart price rule Autumn', 'count' => 1],
            ['type' => 'gone_kind', 'id' => '8', 'label' => '', 'count' => 1],
        ];
        $provider = $this->provider($subjects, ['cart_price_rule', 'gone_kind']);

        self::assertSame('rule', $provider->getKey());
        self::assertSame(
            [['#7', 'Cart price rule Autumn', 'Cart price rule', 1], ['#8', 'gone_kind #8', 'gone_kind', 1]],
            $this->rows($provider)
        );
    }

    public function testOnlyTheKindsTheKeyCoversAreAskedFor(): void
    {
        $recentSubjects = $this->createMock(RecentSubjectsInterface::class);
        $recentSubjects->expects(self::once())->method('recentlyChanged')
            ->with(['cart_price_rule', 'catalog_price_rule'])
            ->willReturn([]);

        $provider = new RecordSuggestions(
            $recentSubjects,
            $this->factory(),
            new TrackedEntityPool($this->createStub(SanitizerInterface::class)),
            'rule',
            ['cart' => 'cart_price_rule', 'catalog' => 'catalog_price_rule', 'again' => 'cart_price_rule']
        );

        self::assertSame([], $provider->suggestions());
    }

    public function testAKeyCoveringNoKindIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RecordSuggestions(
            $this->createStub(RecentSubjectsInterface::class),
            $this->factory(),
            new TrackedEntityPool($this->createStub(SanitizerInterface::class)),
            'product',
            []
        );
    }

    /**
     * @param list<array{type: string, id: string, label: string, count: int}> $subjects
     * @param list<string> $entityTypes
     * @param array<string, RecordKeysInterface> $naturalKeys
     * @return RecordSuggestions
     */
    private function provider(array $subjects, array $entityTypes, array $naturalKeys = []): RecordSuggestions
    {
        $recentSubjects = $this->createStub(RecentSubjectsInterface::class);
        $recentSubjects->method('recentlyChanged')->willReturn($subjects);
        $kinds = [];
        foreach (['product' => 'Product', 'cart_price_rule' => 'Cart price rule'] as $code => $label) {
            $kind = $this->createStub(TrackedEntityInterface::class);
            $kind->method('getCode')->willReturn($code);
            $kind->method('getLabel')->willReturn($label);
            $kinds[$code] = $kind;
        }

        return new RecordSuggestions(
            $recentSubjects,
            $this->factory(),
            new TrackedEntityPool($this->createStub(SanitizerInterface::class), $kinds),
            $entityTypes === ['product'] ? 'product' : 'rule',
            array_combine($entityTypes, $entityTypes),
            $naturalKeys
        );
    }

    /**
     * @return SuggestionInterfaceFactory
     */
    private function factory(): SuggestionInterfaceFactory
    {
        $factory = $this->createStub(SuggestionInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): Suggestion => new Suggestion());

        return $factory;
    }

    /**
     * @param RecordSuggestions $provider
     * @return list<array{string, string, string, int|null}>
     */
    private function rows(RecordSuggestions $provider): array
    {
        return array_map(
            static fn (SuggestionInterface $s): array => [
                $s->getValue(),
                $s->getLabel(),
                $s->getHint(),
                $s->getCount(),
            ],
            $provider->suggestions()
        );
    }
}
