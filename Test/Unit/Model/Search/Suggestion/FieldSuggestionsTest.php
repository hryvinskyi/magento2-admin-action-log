<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Suggestion;

use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterfaceFactory;
use Hryvinskyi\ActivityLog\Model\Data\Search\Suggestion;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecentChanges;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplayInterface;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\FieldSuggestions;
use PHPUnit\Framework\TestCase;

/**
 * A field is offered once, with all its changes, under the label of the kind of record that changed it most
 */
class FieldSuggestionsTest extends TestCase
{
    public function testFieldsAreJoinedAcrossKindsAndLabelledOncePerKind(): void
    {
        $recentChanges = $this->createMock(RecentChanges::class);
        $recentChanges->expects(self::once())->method('changedFields')->with([])->willReturn([
            ['field' => 'name', 'entity_type' => 'category', 'count' => 5],
            ['field' => 'price', 'entity_type' => 'product', 'count' => 4],
            ['field' => 'name', 'entity_type' => 'product', 'count' => 2],
            ['field' => 'sort_order', 'entity_type' => 'cms_block', 'count' => 4],
            ['field' => 'status', 'entity_type' => 'product', 'count' => 1],
        ]);
        $products = $this->createMock(FieldDisplayInterface::class);
        $products->expects(self::once())->method('labelsOf')->with(['price', 'name', 'status'])
            ->willReturn(['price' => 'Price', 'name' => 'Product Name', 'status' => 'Enable Product']);
        $categories = $this->createMock(FieldDisplayInterface::class);
        $categories->expects(self::once())->method('labelsOf')->with(['name'])->willReturn(['name' => 'name']);

        $provider = new FieldSuggestions(
            $recentChanges,
            new FieldDisplays(['product' => $products, 'category' => $categories]),
            $this->factory()
        );

        self::assertSame('field', $provider->getKey());
        self::assertSame(
            [
                ['name', 'name', '', 7],
                ['price', 'Price', 'price', 4],
                ['sort_order', 'sort_order', '', 4],
                ['status', 'Enable Product', 'status', 1],
            ],
            $this->rows($provider)
        );
    }

    public function testAProviderOfSomeKindsAsksForTheirFieldsOnly(): void
    {
        $recentChanges = $this->createMock(RecentChanges::class);
        $recentChanges->expects(self::once())->method('changedFields')->with(['config_value'])->willReturn([]);

        $provider = new FieldSuggestions(
            $recentChanges,
            new FieldDisplays(),
            $this->factory(),
            'config',
            ['config_value' => 'config_value']
        );

        self::assertSame('config', $provider->getKey());
        self::assertSame([], $provider->suggestions());
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
     * @param FieldSuggestions $provider
     * @return list<array{string, string, string, int|null}>
     */
    private function rows(FieldSuggestions $provider): array
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
