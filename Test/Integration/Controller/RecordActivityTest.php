<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\ActivitySearchInterface;
use Hryvinskyi\ActivityLog\Model\Search\RecentDays;
use Hryvinskyi\ActivityLog\Model\Search\RequestParser;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivitySummary;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecentFieldChanges;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecordSearch;
use Hryvinskyi\AdminActionLog\Test\Integration\Controller\Fixture\CountingActivitySearch;
use Hryvinskyi\AdminActionLog\Test\Integration\Model\Search\Fixture\EntityChanges;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Test\Fixture\Block as BlockFixture;
use Magento\Cms\Test\Fixture\Page as PageFixture;
use Magento\Customer\Test\Fixture\Customer as CustomerFixture;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Request as TestRequest;
use Magento\TestFramework\Response as TestResponse;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Magento\User\Model\User;

/**
 * A record's activity on its edit page, through the real admin stack: the "Activity" button with the count of the
 * last 30 days, the panel's config and the timeline's assets, the product field hints, and nothing of it for a new
 * record or an admin who may not read the activity log
 *
 * Changes are written straight to the journal and the change tables, at times relative to now: the recorder writes
 * them only after a save's transaction commits, which never happens inside a test's transaction.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class RecordActivityTest extends AbstractBackendController
{
    private const HOUR_MS = 3_600_000;
    private const DAY_MS = 86_400_000;

    /**
     * The page the inherited access tests open: a product edit page, which the button and panel are added to
     *
     * @var string
     */
    protected $resource = 'Magento_Catalog::products';

    /**
     * @var string
     */
    protected $uri = 'backend/catalog/product/new/set/4/type/simple/';

    /**
     * @var EntityChanges
     */
    private EntityChanges $changes;

    /**
     * @var int
     */
    private int $nowMs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changes = new EntityChanges();
        $this->changes->clear();
        foreach ([ActivitySummary::class, RecentFieldChanges::class] as $remembering) {
            $this->_objectManager->removeSharedInstance($remembering);
        }
        $this->nowMs = EntityChanges::service(MillisecondClockInterface::class)->nowMs();
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-activity-bag', 'name' => 'Activity Bag'], 'product')]
    public function testTheProductPageCountsTheChangesThePanelListsForTheLast30Days(): void
    {
        $id = $this->fixtureId('product');
        $this->seed('product', $id, 1 * self::HOUR_MS, 'john', 'price', '34.00', '39.00');
        $this->seed('product', $id, 2 * self::DAY_MS, 'anna', 'status', '2', '1');
        $this->seed('product', $id, 20 * self::DAY_MS, 'john', 'name', 'Bag', 'Activity Bag');
        $this->seed('product', $id, 40 * self::DAY_MS, 'john', 'name', 'Old Bag', 'Bag');
        $this->seed('product', $id . '0', 1 * self::HOUR_MS, 'john', 'price', '1.00', '2.00');
        $this->seed('category', $id, 1 * self::HOUR_MS, 'john', 'name', 'A', 'B');

        // The panel's list of the last 30 days, asked as the browser asks and read as the endpoint reads it
        $startMs = EntityChanges::service(RecentDays::class)->startMs(ActivitySummary::DAYS, $this->nowMs);
        $listed = EntityChanges::service(ActivitySearchInterface::class)->search(
            EntityChanges::service(RequestParser::class)->parse([
                'from' => (string) $startMs,
                'to' => (string) ($this->nowMs + self::HOUR_MS),
                'c' => ['product:#' . $id],
                'changes' => '1',
            ])
        );
        self::assertCount(3, $listed->getEvents());

        $body = $this->page('backend/catalog/product/edit/id/' . $id);

        self::assertSame(['3', 'false'], $this->buttonCount($body));
        self::assertStringContainsString('data-role="activity-timeline-assets"', $body);
        $config = $this->panelConfig($body);
        $record = $this->part($config, 'record');
        $summary = $this->part($config, 'summary');
        self::assertSame(
            ['product', '#' . $id, $id, ['label' => 'SKU', 'value' => 'aal-activity-bag']],
            [$record['key'] ?? null, $record['value'] ?? null, $record['id'] ?? null, $record['naturalKey'] ?? null]
        );
        self::assertSame(
            [3, false, 'john'],
            [$summary['count'] ?? null, $summary['atLeast'] ?? null, $summary['latestActor'] ?? null]
        );
        self::assertSame(['anna', 'john'], $config['actors'] ?? null);
        self::assertSame(['name', 'price', 'status'], $this->fieldCodes($config));
        self::assertSame('All Store Views', $config['scope'] ?? null);
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-activity-bag', 'name' => 'Activity Bag'], 'product')]
    public function testAnAdminWhoMayNotReadTheLogGetsNothingAndNothingIsCounted(): void
    {
        $id = $this->fixtureId('product');
        $this->seed('product', $id, self::HOUR_MS, 'john', 'price', '34.00', '39.00');
        $searches = $this->countSearches();
        $admin = $this->_auth->getUser();
        self::assertInstanceOf(User::class, $admin);
        $aclBuilder = EntityChanges::service(AclBuilder::class);
        $aclBuilder->getAcl()->deny($admin->getRoles(), 'Hryvinskyi_ActivityLog::view');

        try {
            $body = $this->page('backend/catalog/product/edit/id/' . $id);
            self::assertSame(0, $searches->searches(), 'No search runs for an admin without the permission');
        } finally {
            $this->restoreSearch();
            $aclBuilder->resetRuntimeAcl();
        }

        self::assertStringContainsString('Activity Bag', $body);
        self::assertStringNotContainsString('record-activity', $body);
        self::assertStringNotContainsString('activity-timeline-assets', $body);
        self::assertStringNotContainsString('record-activity-hints', $body);
    }

    public function testANewProductHasNoActivity(): void
    {
        $searches = $this->countSearches();
        try {
            $body = $this->page('backend/catalog/product/new/set/4/type/simple/');
            self::assertSame(0, $searches->searches(), 'Nothing is searched for a record that does not exist yet');
        } finally {
            $this->restoreSearch();
        }

        self::assertStringContainsString('product_form', $body);
        self::assertStringNotContainsString('record-activity', $body);
        self::assertStringNotContainsString('activity-timeline-assets', $body);
    }

    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testTheCategoryPageShowsTheActivityOfItsCategory(): void
    {
        $this->assertPageShowsItsRecord(
            'category',
            'category',
            'backend/catalog/category/edit/id/%s',
            'All Store Views'
        );
    }

    #[DataFixture(PageFixture::class, as: 'page')]
    public function testTheCmsPagePageShowsTheActivityOfItsPage(): void
    {
        $this->assertPageShowsItsRecord('page', 'cms_page', 'backend/cms/page/edit/page_id/%s', null);
    }

    #[DataFixture(BlockFixture::class, as: 'block')]
    public function testTheCmsBlockPageShowsTheActivityOfItsBlock(): void
    {
        $this->assertPageShowsItsRecord('block', 'cms_block', 'backend/cms/block/edit/block_id/%s', null);
    }

    #[DataFixture(CustomerFixture::class, as: 'customer')]
    public function testTheCustomerPageShowsTheActivityOfItsCustomer(): void
    {
        $this->assertPageShowsItsRecord('customer', 'customer', 'backend/customer/index/edit/id/%s', null);
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-activity-bag', 'name' => 'Activity Bag'], 'product')]
    public function testFieldHintsCarryTheNewestChangeOfEachFieldAsDataThePagePrintsEscaped(): void
    {
        $id = $this->fixtureId('product');
        $script = '<script>alert("aal")</script>';
        $this->seed('product', $id, 2 * self::DAY_MS, 'anna', 'price', '34.00', '35.00');
        $this->seed('product', $id, 1 * self::HOUR_MS, 'john', 'price', '35.00', '39.00');
        $this->seed('product', $id, 3 * self::HOUR_MS, 'anna', 'meta_title', null, $script);
        $this->seed('product', $id, 4 * self::HOUR_MS, 'anna', 'description', null, str_repeat('Long text. ', 40));
        $this->seed('product', $id, 10 * self::DAY_MS, 'john', 'name', 'Bag', 'Activity Bag');

        $body = $this->page('backend/catalog/product/edit/id/' . $id);
        $hints = $this->hints($body);

        self::assertSame(['price', 'meta_title', 'description'], array_keys($hints));
        self::assertSame(['john', '35.00', '39.00', 'short'], [
            $hints['price']['actor'] ?? null,
            $hints['price']['before'] ?? null,
            $hints['price']['after'] ?? null,
            $hints['price']['kind'] ?? null,
        ]);
        self::assertSame([null, $script, 'short'], [
            array_key_exists('before', $hints['meta_title']) ? $hints['meta_title']['before'] : 'absent',
            $hints['meta_title']['after'] ?? null,
            $hints['meta_title']['kind'] ?? null,
        ]);
        self::assertSame([null, null, 'long'], [
            array_key_exists('before', $hints['description']) ? $hints['description']['before'] : 'absent',
            array_key_exists('after', $hints['description']) ? $hints['description']['after'] : 'absent',
            $hints['description']['kind'] ?? null,
        ]);
        self::assertStringNotContainsString($script, $body, 'A value is never printed as markup');
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-activity-bag', 'name' => 'Activity Bag'], 'product')]
    public function testAValueThatLooksLikeATemplateReachesThePageOnlyInsideTheHintsJson(): void
    {
        $id = $this->fixtureId('product');
        $marker = 'aalTemplate' . random_int(1000, 9999);
        $value = '${' . "\n" . 'alert(\'' . $marker . '\')}';
        $this->seed('product', $id, self::HOUR_MS, 'anna', 'meta_title', null, $value);

        $body = $this->page('backend/catalog/product/edit/id/' . $id);
        $block = $this->hintsBlock($body);

        self::assertSame($value, $this->hints($body)['meta_title']['after'] ?? null, 'The hint carries the value');
        self::assertGreaterThan(0, substr_count($block, $marker));
        self::assertSame(
            substr_count($block, $marker),
            substr_count($body, $marker),
            'The value appears nowhere but in the hints\' JSON: no component config carries it'
        );
        self::assertStringNotContainsString('"hintConfig"', $body);
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-activity-bag', 'name' => 'Activity Bag'], 'product')]
    public function testAFieldSetAutomaticallyGivesNoHint(): void
    {
        $id = $this->fixtureId('product');
        $this->seed('product', $id, 2 * self::DAY_MS, 'anna', 'image_label', null, 'Activity Bag');
        $automatic = [
            ['field' => 'image_label', 'before' => 'Activity Bag', 'after' => null, 'automatic' => true],
            ['field' => 'thumbnail_label', 'before' => 'Activity Bag', 'after' => null, 'automatic' => true],
            ['field' => 'price', 'before' => '34.00', 'after' => '39.00'],
        ];
        $ms = $this->nowMs - self::HOUR_MS;
        $this->changes->change('product', $id, $automatic, ['default', 0, 'All Store Views'], [
            'occurred_at_ms' => $ms,
            'created_at' => gmdate('Y-m-d H:i:s', intdiv($ms, 1000)),
            'actor_name' => 'john',
        ]);

        $hints = $this->hints($this->page('backend/catalog/product/edit/id/' . $id));

        self::assertSame(['price', 'image_label'], array_keys($hints), 'Nobody set the thumbnail label');
        self::assertSame(['john', 'anna', 'Activity Bag'], [
            $hints['price']['actor'] ?? null,
            $hints['image_label']['actor'] ?? null,
            $hints['image_label']['after'] ?? null,
        ], 'The image label\'s hint is the change someone made, not the one Magento made since');
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-activity-bag', 'name' => 'Activity Bag'], 'product')]
    public function testEveryFieldOfASaveThatChangedThirtyFieldsGetsItsHint(): void
    {
        $id = $this->fixtureId('product');
        $fields = [];
        foreach (range(1, 30) as $index) {
            $fields[] = [
                'field' => sprintf('aal_field_%02d', $index),
                'before' => 'a' . $index,
                'after' => 'b' . $index,
            ];
        }
        $ms = $this->nowMs - self::HOUR_MS;
        $this->changes->change('product', $id, $fields, ['default', 0, 'All Store Views'], [
            'occurred_at_ms' => $ms,
            'created_at' => gmdate('Y-m-d H:i:s', intdiv($ms, 1000)),
            'actor_name' => 'john',
        ]);
        $this->seed('product', $id, 8 * self::DAY_MS, 'anna', 'aal_field_31', 'old', 'older');

        $hints = $this->hints($this->page('backend/catalog/product/edit/id/' . $id));

        self::assertCount(30, $hints, 'More fields than a search result lists for one change');
        self::assertSame(array_column($fields, 'field'), array_keys($hints));
        self::assertSame(['john', 'a30', 'b30'], [
            $hints['aal_field_30']['actor'] ?? null,
            $hints['aal_field_30']['before'] ?? null,
            $hints['aal_field_30']['after'] ?? null,
        ]);
    }

    /**
     * An edit page shows the button with its record's count and the panel searching its record
     *
     * @param string $key The fixture's name, which is also the record's search key
     * @param string $entityType
     * @param string $uri With "%s" for the record's id
     * @param string|null $scope The scope "All store views" switches to, or null for a kind without store views
     * @return void
     */
    private function assertPageShowsItsRecord(string $key, string $entityType, string $uri, ?string $scope): void
    {
        $id = $this->fixtureId($key);
        $this->seed($entityType, $id, self::HOUR_MS, 'john', 'title', 'Before', 'After');
        $this->seed($entityType, $id, 40 * self::DAY_MS, 'john', 'title', 'Older', 'Before');

        $body = $this->page(sprintf($uri, $id));

        self::assertSame(['1', 'false'], $this->buttonCount($body));
        $config = $this->panelConfig($body);
        $record = $this->part($config, 'record');
        self::assertSame([$key, '#' . $id, null, $scope], [
            $record['key'] ?? null,
            $record['value'] ?? null,
            array_key_exists('naturalKey', $record) ? $record['naturalKey'] : 'absent',
            array_key_exists('scope', $config) ? $config['scope'] : 'absent',
        ]);
    }

    /**
     * Write one change of a record, that long before now
     *
     * @param string $entityType
     * @param string $entityId
     * @param int $agoMs
     * @param string $actor
     * @param string $field
     * @param string|null $before
     * @param string $after
     * @return void
     */
    private function seed(
        string $entityType,
        string $entityId,
        int $agoMs,
        string $actor,
        string $field,
        ?string $before,
        string $after
    ): void {
        $ms = $this->nowMs - $agoMs;
        $this->changes->change(
            $entityType,
            $entityId,
            [['field' => $field, 'before' => $before, 'after' => $after]],
            ['default', 0, 'All Store Views'],
            [
                'occurred_at_ms' => $ms,
                'created_at' => gmdate('Y-m-d H:i:s', intdiv($ms, 1000)),
                'actor_name' => $actor,
            ]
        );
    }

    /**
     * The body of an admin page
     *
     * @param string $uri
     * @return string
     */
    private function page(string $uri): string
    {
        $this->resetRequest();
        // Building admin links switches the shared URL model's secret key back on; the test's request URIs carry none
        EntityChanges::service(BackendUrl::class)->turnOffSecretKey();
        $request = $this->getRequest();
        self::assertInstanceOf(TestRequest::class, $request);
        $request->setMethod('GET');
        $this->dispatch($uri);

        $response = $this->getResponse();
        self::assertInstanceOf(TestResponse::class, $response);
        self::assertSame(200, $response->getHttpResponseCode(), $uri);

        return (string) $response->getBody();
    }

    /**
     * The count the "Activity" button carries, and whether it is a lower bound
     *
     * @param string $body
     * @return array{0: string, 1: string}
     */
    private function buttonCount(string $body): array
    {
        self::assertSame(
            1,
            preg_match('/<button[^>]*data-role="record-activity-open"[^>]*>/', $body, $button),
            'The page has the button'
        );
        $tag = $button[0] ?? '';
        preg_match('/data-count="([^"]*)"/', $tag, $count);
        preg_match('/data-count-at-least="([^"]*)"/', $tag, $atLeast);

        return [$count[1] ?? '', $atLeast[1] ?? ''];
    }

    /**
     * The panel's config as the page prints it
     *
     * @param string $body
     * @return array<string, mixed>
     */
    private function panelConfig(string $body): array
    {
        $pattern = '#<script type="application/json" data-role="record-activity-config">(.*?)</script>#s';
        self::assertSame(1, preg_match($pattern, $body, $match), 'The page has the panel');
        $config = json_decode(trim($match[1] ?? ''), true);
        self::assertIsArray($config);
        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * Codes of the fields the panel offers
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function fieldCodes(array $config): array
    {
        $codes = [];
        foreach (is_array($config['fields'] ?? null) ? $config['fields'] : [] as $field) {
            $codes[] = is_array($field) && is_string($field['value'] ?? null) ? $field['value'] : '';
        }
        sort($codes);

        return $codes;
    }

    /**
     * The hints' JSON element as the page prints it, whole
     *
     * @param string $body
     * @return string
     */
    private function hintsBlock(string $body): string
    {
        $pattern = '#<script type="application/json" data-role="record-activity-hints-config">.*?</script>#s';
        self::assertSame(1, preg_match($pattern, $body, $match), 'The page has the hints');

        return $match[0] ?? '';
    }

    /**
     * The field hints the page prints for its product, keyed by field code
     *
     * @param string $body
     * @return array<string, array<string, mixed>>
     */
    private function hints(string $body): array
    {
        $json = preg_replace('#^<script[^>]*>|</script>$#', '', $this->hintsBlock($body));
        $config = json_decode(trim((string) $json), true);
        self::assertIsArray($config);
        $hints = [];
        foreach ($this->part($config, 'hints') as $hint) {
            self::assertIsArray($hint);
            $field = $hint['field'] ?? null;
            self::assertIsString($field);
            /** @var array<string, mixed> $hint */
            $hints[$field] = $hint;
        }

        return $hints;
    }

    /**
     * Swaps the search behind the record's activity for one that counts its searches
     *
     * @return CountingActivitySearch
     */
    private function countSearches(): CountingActivitySearch
    {
        $counting = new CountingActivitySearch(EntityChanges::service(ActivitySearchInterface::class));
        $this->_objectManager->addSharedInstance($counting, ActivitySearchInterface::class, true);
        foreach ([RecordSearch::class, ActivitySummary::class, RecentFieldChanges::class] as $dependent) {
            $this->_objectManager->removeSharedInstance($dependent);
        }

        return $counting;
    }

    /**
     * Puts the real search back for whatever runs next
     *
     * @return void
     */
    private function restoreSearch(): void
    {
        $this->_objectManager->removeSharedInstance(ActivitySearchInterface::class, true);
        foreach ([RecordSearch::class, ActivitySummary::class, RecentFieldChanges::class] as $dependent) {
            $this->_objectManager->removeSharedInstance($dependent);
        }
    }

    /**
     * A part of a decoded payload that must be an object
     *
     * @param array<mixed> $data
     * @param string $key
     * @return array<mixed>
     */
    private function part(array $data, string $key): array
    {
        $part = $data[$key] ?? null;
        self::assertIsArray($part, $key);

        return $part;
    }

    /**
     * Id of a record a fixture created, as text
     *
     * @param string $name
     * @return string
     */
    private function fixtureId(string $name): string
    {
        $record = DataFixtureStorageManager::getStorage()->get($name);
        self::assertNotNull($record);
        $id = $record->getData(match ($name) {
            'page' => 'page_id',
            'block' => 'block_id',
            default => 'entity_id',
        });
        self::assertTrue(is_scalar($id), $name);

        return (string) $id;
    }
}
