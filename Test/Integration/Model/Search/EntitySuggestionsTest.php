<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model\Search;

use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterface;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\SuggestionProviderInterface;
use Hryvinskyi\ActivityLog\Model\ResourceModel\RecentSubjects;
use Hryvinskyi\ActivityLog\Model\Search\Suggestion\ProviderReader;
use Hryvinskyi\ActivityLog\Model\Search\Suggestion\RecentWindow;
use Hryvinskyi\ActivityLog\Model\Search\SuggestionPool;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecentChanges;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\EntityTypeSuggestions;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\FieldSuggestions;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\ScopeSuggestions;
use Hryvinskyi\AdminActionLog\Test\Integration\Model\Search\Fixture\EntityChanges;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * What entity changes suggest to the search bar, through the providers the container declares, over changes written
 * straight into the tables, with the recent window ending at EntityChanges::NOW_MS
 *
 * Every test empties the journal first, inside its own transaction. Changes older than the window are written first,
 * as the log writes them: activity ids grow with time.
 */
#[DbIsolation(true)]
#[AppArea('adminhtml')]
class EntitySuggestionsTest extends TestCase
{
    private const DAY_MS = 86_400_000;
    private const NS = 'Hryvinskyi\\AdminActionLog\\Model\\Search\\Suggestion\\';

    /**
     * @var EntityChanges
     */
    private EntityChanges $changes;

    /**
     * @var RecentWindow
     */
    private RecentWindow $window;

    protected function setUp(): void
    {
        $this->changes = new EntityChanges();
        $this->changes->clear();
        $clock = $this->createStub(MillisecondClockInterface::class);
        $clock->method('nowMs')->willReturn(EntityChanges::NOW_MS);
        $window = Bootstrap::getObjectManager()->create(RecentWindow::class, ['clock' => $clock]);
        self::assertInstanceOf(RecentWindow::class, $window);
        $this->window = $window;
    }

    public function testFieldsAreCountedByTheirChangesAndShownUnderTheirLabel(): void
    {
        $this->old('product', '1', [['field' => 'sku', 'before' => 'a', 'after' => 'b']]);
        $this->changes->change('product', '1', [
            ['field' => 'price', 'before' => '10', 'after' => '12'],
            ['field' => 'name', 'before' => 'Tote', 'after' => 'Tote', 'changed' => false],
        ]);
        $this->changes->change('product', '2', [
            ['field' => 'price', 'before' => '5', 'after' => '6'],
            ['field' => 'status', 'before' => '1', 'after' => '2'],
        ]);
        $this->changes->change('category', '3', [['field' => 'name', 'before' => 'Bags', 'after' => 'Totes']]);

        self::assertSame(
            [
                ['price', 'Price', 'price', 2],
                ['name', 'Name', 'name', 1],
                ['status', 'Enable Product', 'status', 1],
            ],
            $this->rows($this->provider(FieldSuggestions::class))
        );
    }

    public function testSettingsAreSuggestedByPathUnderTheirLabelAndOnlySettings(): void
    {
        $path = 'hryvinskyi_admin_action_log/admin/enabled';
        $this->changes->change('config_value', '11', [['field' => $path, 'before' => '0', 'after' => '1']]);
        $this->changes->change('config_value', '11', [['field' => $path, 'before' => '1', 'after' => '0']]);
        $this->changes->change('config_value', '12', [['field' => 'aalfixture/no_such/path', 'after' => 'x']]);
        $this->changes->change('product', '13', [['field' => 'price', 'before' => '1', 'after' => '2']]);

        $settings = $this->provider(self::NS . 'SettingSuggestions');

        self::assertSame('config', $settings->getKey());
        self::assertSame(
            [
                [$path, 'Admin Requests › Record Admin Requests', $path, 2],
                ['aalfixture/no_such/path', 'aalfixture/no_such/path', '', 1],
            ],
            $this->rows($settings)
        );
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-suggest-tote', 'name' => 'Aal Suggest Tote'], 'tote')]
    #[DataFixture(ProductFixture::class, ['sku' => '20261022', 'name' => 'Aal Numbered'], 'numbered')]
    public function testRecordsComeMostRecentlyChangedFirstAndPickExactlyThatRecord(): void
    {
        $tote = $this->fixtureId('tote');
        $numbered = $this->fixtureId('numbered');
        $this->old('product', '999990', [['field' => 'price']], 'Product aal-ancient · Ancient');
        $this->change('product', $tote, 'Product #' . $tote);
        $this->change('product', $tote, 'Product aal-suggest-tote · Aal Suggest Tote');
        $this->change('product', '999991', 'Product aal-gone · Retired');
        $this->change('product', $numbered, 'Product 20261022 · Aal Numbered');
        $this->change('category', '5', 'Category Bags (#5)');

        self::assertSame(
            [
                ['#' . $numbered, 'Product 20261022 · Aal Numbered', 'Product', 1],
                ['#999991', 'Product aal-gone · Retired', 'Product', 1],
                ['aal-suggest-tote', 'Product aal-suggest-tote · Aal Suggest Tote', 'Product', 2],
            ],
            $this->rows($this->provider(self::NS . 'ProductSuggestions'))
        );
        self::assertSame(
            [['#5', 'Category Bags (#5)', 'Category', 1]],
            $this->rows($this->provider(self::NS . 'CategorySuggestions'))
        );
    }

    public function testAKeyOffersOnlyTheKindsOfRecordItCovers(): void
    {
        $this->change('order', '21', 'Order 000000021');
        $this->change('order_comment', '22', 'Order comment #22');
        $this->change('cart_price_rule', '7', 'Cart price rule Autumn');
        $this->change('catalog_price_rule', '8', 'Catalog price rule Winter');

        self::assertSame(
            [['#21', 'Order 000000021', 'Order', 1]],
            $this->rows($this->provider(self::NS . 'OrderSuggestions'))
        );
        self::assertSame(
            [
                ['#8', 'Catalog price rule Winter', 'Catalog price rule', 1],
                ['#7', 'Cart price rule Autumn', 'Cart price rule', 1],
            ],
            $this->rows($this->provider(self::NS . 'RuleSuggestions'))
        );
    }

    public function testEveryKindOfRecordIsOfferedTheChangedOnesFirst(): void
    {
        $this->change('cms_block', '31', 'CMS block footer');
        $this->change('cms_block', '32', 'CMS block header');
        $this->change('customer', '33', 'Customer #33');

        $rows = $this->rows($this->provider(EntityTypeSuggestions::class));

        self::assertSame(
            [['cms_block', 'CMS block', '', 2], ['customer', 'Customer', '', 1]],
            array_slice($rows, 0, 2)
        );
        self::assertCount(count(EntityChanges::service(TrackedEntityPool::class)->all()), $rows);
        self::assertSame([0], array_values(array_unique(array_column(array_slice($rows, 2), 3))));
    }

    public function testScopesAreSuggestedByTheirRecordedNameWithTheirKindMostFrequentThenByName(): void
    {
        $storeName = (string) EntityChanges::service(StoreManagerInterface::class)->getStore('default')->getName();
        $this->changes->change('config_value', '41', [['field' => 'a/b/c'], ['field' => 'a/b/d']]);
        $this->changes->change('config_value', '42', [['field' => 'a/b/c']], ['website', 1, 'Main Website']);
        $this->changes->change('product', '43', [['field' => 'name']], ['store', 1, $storeName]);
        self::assertGreaterThan(0, strcmp('Main Website', $storeName), 'The store view sorts first');

        self::assertSame(
            [
                ['Default Config', 'Default Config', '', 2],
                [$storeName, $storeName, 'Store view', 1],
                ['Main Website', 'Main Website', 'Website', 1],
            ],
            $this->rows($this->provider(ScopeSuggestions::class))
        );
    }

    public function testNoListEverHoldsARecordedValueMaskedOrNot(): void
    {
        $this->changes->change('config_value', '51', [
            [
                'field' => 'aal/api/key',
                'before' => 'aal-masked-before',
                'after' => 'aal-masked-after',
                'masked' => true,
            ],
            ['field' => 'aal/api/url', 'before' => 'aal-plain-before', 'after' => 'aal-plain-after'],
        ], journal: ['subject_label' => 'Config value aal/api/key']);

        $texts = [];
        foreach ($this->providers() as $provider) {
            foreach ($provider->suggestions() as $suggestion) {
                $texts[] = $suggestion->getValue() . ' ' . $suggestion->getLabel() . ' ' . $suggestion->getHint();
            }
        }

        self::assertNotEmpty($texts);
        self::assertStringNotContainsString('aal-masked', implode("\n", $texts));
        self::assertStringNotContainsString('aal-plain', implode("\n", $texts));
    }

    public function testTheContainerAddsTheProvidersOfEntityChangesToThePool(): void
    {
        $pool = Bootstrap::getObjectManager()->create(SuggestionPool::class, [
            'reader' => EntityChanges::service(ProviderReader::class),
        ]);
        self::assertInstanceOf(SuggestionPool::class, $pool);

        $codes = [];
        foreach (EntityChanges::service(TrackedEntityPool::class)->all() as $entity) {
            $codes[] = $entity->getCode();
        }
        $offered = array_map(
            static fn (SuggestionInterface $suggestion): string => $suggestion->getValue(),
            $pool->lists()['entity'] ?? []
        );
        sort($codes);
        sort($offered);

        self::assertSame($codes, $offered);
    }

    /**
     * Every provider of entity changes, over the test's window
     *
     * @return list<SuggestionProviderInterface>
     */
    private function providers(): array
    {
        $providers = [
            $this->provider(EntityTypeSuggestions::class),
            $this->provider(FieldSuggestions::class),
            $this->provider(ScopeSuggestions::class),
        ];
        foreach (['Setting', 'Product', 'Category', 'CmsPage', 'CmsBlock', 'Customer', 'Order', 'Rule'] as $name) {
            $providers[] = $this->provider(self::NS . $name . 'Suggestions');
        }

        return $providers;
    }

    /**
     * A provider as the container declares it, reading the test's window
     *
     * @param string $type A class or a virtual type
     * @return SuggestionProviderInterface
     */
    private function provider(string $type): SuggestionProviderInterface
    {
        $objectManager = Bootstrap::getObjectManager();
        $provider = $objectManager->create($type, [
            'recentChanges' => $objectManager->create(RecentChanges::class, ['window' => $this->window]),
            'recentSubjects' => $objectManager->create(RecentSubjects::class, ['window' => $this->window]),
        ]);
        self::assertInstanceOf(SuggestionProviderInterface::class, $provider);

        return $provider;
    }

    /**
     * A change of one field with the given recorded name
     *
     * @param string $entityType
     * @param string $entityId
     * @param string $label
     * @return void
     */
    private function change(string $entityType, string $entityId, string $label): void
    {
        $this->changes->change(
            $entityType,
            $entityId,
            [['field' => 'title', 'before' => 'a', 'after' => 'b']],
            journal: ['subject_label' => $label]
        );
    }

    /**
     * A change made 31 days before NOW_MS
     *
     * @param string $entityType
     * @param string $entityId
     * @param list<array{field: string, before?: string|null, after?: string|null}> $fields
     * @param string|null $label
     * @return void
     */
    private function old(string $entityType, string $entityId, array $fields, ?string $label = null): void
    {
        $ms = EntityChanges::NOW_MS - 31 * self::DAY_MS;
        $this->changes->change($entityType, $entityId, $fields, journal: array_filter([
            'occurred_at_ms' => $ms,
            'created_at' => gmdate('Y-m-d H:i:s', intdiv($ms, 1000)),
            'subject_label' => $label,
        ], static fn (mixed $value): bool => $value !== null));
    }

    /**
     * @param SuggestionProviderInterface $provider
     * @return list<array{string, string, string, int|null}>
     */
    private function rows(SuggestionProviderInterface $provider): array
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

    /**
     * Id of a record a data fixture created
     *
     * @param string $name
     * @return string
     */
    private function fixtureId(string $name): string
    {
        $record = DataFixtureStorageManager::getStorage()->get($name);
        self::assertTrue(is_object($record) && method_exists($record, 'getId'), sprintf('No fixture "%s".', $name));
        $id = $record->getId();
        self::assertIsScalar($id);

        return (string) $id;
    }
}
