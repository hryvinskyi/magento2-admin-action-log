<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Api\Data\Search\FieldChangeInterface;
use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Test\Integration\Model\Search\Fixture\EntityChanges;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Catalog\Test\Fixture\Attribute as AttributeFixture;
use Magento\Catalog\Test\Fixture\AttributeSet as AttributeSetFixture;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Magento\User\Model\User;

/**
 * Entity changes as the search endpoint describes them, through the real admin stack: the sentence's parts, fields
 * and values as the admin sees them, values cut for transport, and a link only to a record that exists and that the
 * admin may open
 *
 * Changes are written straight to the journal and the change tables: the recorder writes them after the transaction
 * commits, which never happens inside a test's transaction.
 */
#[DbIsolation(true)]
class EntityChangeDetailsTest extends AbstractBackendController
{
    private const DAY_MS = 86_400_000;
    private const REQUEST = 'op-entity-details';

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_ActivityLog::view';

    /**
     * @var string
     */
    protected $uri = 'backend/activitylog/activity/search';

    /**
     * @var string
     */
    protected $httpMethod = 'GET';

    /**
     * @var EntityChanges
     */
    private EntityChanges $changes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changes = new EntityChanges();
        $this->changes->clear();
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-detail-bag', 'name' => 'Detail Bag'], 'product')]
    public function testDescribesAProductChangeAsTheAdminSeesItWithALinkToTheProduct(): void
    {
        $productId = $this->productId();
        $activityId = $this->changes->change(
            'product',
            $productId,
            [['field' => 'status', 'before' => '1', 'after' => '2']],
            ['store', 1, 'Default Store View'],
            ['correlation_id' => self::REQUEST, 'subject_label' => 'Product aal-detail-bag · Detail Bag']
        );

        $event = $this->search()[0] ?? [];

        self::assertSame([$activityId, 'changed'], [$event['id'] ?? null, $event['verb'] ?? null]);
        $detail = $this->detail($event);
        self::assertSame(['Enable Product', 1, 'Product', 'aal-detail-bag · Detail Bag', 0, 0], [
            $detail['what'] ?? null,
            $detail['fieldCount'] ?? null,
            $detail['subjectTypeLabel'] ?? null,
            $detail['subjectLabel'] ?? null,
            $detail['moreFieldCount'] ?? null,
            $detail['unchangedCount'] ?? null,
        ]);
        self::assertIsString($detail['subjectUrl'] ?? null);
        self::assertStringContainsString('/catalog/product/edit/id/' . $productId . '/', $detail['subjectUrl']);
        self::assertSame([
            'name' => 'status',
            'label' => 'Enable Product',
            'scope' => 'Default Store View',
            'before' => 'Enabled',
            'after' => 'Disabled',
            'kind' => 'short',
            'changed' => true,
            'automatic' => false,
            'truncated' => false,
            'beforeLength' => 7,
            'afterLength' => 8,
        ], $this->fields($detail)[0] ?? null);
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-detail-bag', 'name' => 'Detail Bag'], 'product')]
    public function testFieldsSetAutomaticallyReachThePageMarkedAndCountedApart(): void
    {
        $this->changes->change(
            'product',
            $this->productId(),
            [
                ['field' => 'image_label', 'before' => 'Detail Bag', 'after' => null, 'automatic' => true],
                ['field' => 'price', 'before' => '34.00', 'after' => '39.00'],
                ['field' => 'thumbnail_label', 'before' => 'Detail Bag', 'after' => null, 'automatic' => true],
            ],
            ['default', 0, 'All Store Views'],
            ['correlation_id' => self::REQUEST, 'subject_label' => 'Product aal-detail-bag · Detail Bag']
        );

        $detail = $this->detail($this->search()[0] ?? []);

        self::assertSame(['Price', 3, 2], [
            $detail['what'] ?? null,
            $detail['fieldCount'] ?? null,
            $detail['automaticCount'] ?? null,
        ], 'The sentence names the field the admin asked for');
        self::assertSame(
            [['price', false], ['image_label', true], ['thumbnail_label', true]],
            array_map(
                static fn (array $field): array => [$field['name'] ?? null, $field['automatic'] ?? null],
                $this->fields($detail)
            )
        );
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-detail-bag', 'name' => 'Detail Bag'], 'product')]
    public function testNamesTheRecordWithoutTheListOfTheFieldsTheSentenceShows(): void
    {
        $productId = $this->productId();
        $recent = $this->changes->change(
            'product',
            $productId,
            [
                ['field' => 'special_price', 'before' => null, 'after' => '30.00'],
                ['field' => 'special_from_date', 'before' => null, 'after' => '2026-10-01 00:00:00'],
            ],
            journal: [
                'correlation_id' => self::REQUEST,
                'subject_label' => 'Product aal-detail-bag · Detail Bag · special_price, special_from_date',
            ]
        );
        $old = $this->changes->change(
            'product',
            $productId,
            [['field' => 'price', 'before' => '1.00', 'after' => '2.00']],
            journal: ['correlation_id' => self::REQUEST, 'subject_label' => 'Product #' . $productId . ' · price']
        );
        $other = $this->changes->change(
            'product',
            $productId,
            [['field' => 'price', 'before' => '2.00', 'after' => '3.00']],
            journal: ['correlation_id' => self::REQUEST, 'subject_label' => 'Product Bag · Accessories']
        );

        $labels = [];
        foreach ($this->search() as $event) {
            $id = $event['id'] ?? null;
            $labels[is_int($id) ? $id : 0] = $this->detail($event)['subjectLabel'] ?? null;
        }

        self::assertSame(
            [$recent => 'aal-detail-bag · Detail Bag', $old => '#' . $productId, $other => 'Bag · Accessories'],
            [$recent => $labels[$recent] ?? null, $old => $labels[$old] ?? null, $other => $labels[$other] ?? null],
            'New and old names lose the changed fields\' codes; a tail naming anything else is part of the name'
        );
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-detail-bag', 'name' => 'Detail Bag'], 'product')]
    public function testLinksNoRecordThatIsGoneOrThatTheAdminMayNotOpen(): void
    {
        $productId = $this->productId();
        $gone = $this->changes->change(
            'product',
            '999999',
            [['field' => 'price', 'before' => '1.00', 'after' => '2.00']],
            journal: ['correlation_id' => self::REQUEST],
            operation: ChangeOperation::Delete
        );
        $kept = $this->changes->change(
            'product',
            $productId,
            [['field' => 'price', 'before' => '1.00', 'after' => '2.00']],
            journal: ['correlation_id' => self::REQUEST]
        );

        $links = $this->subjectUrls($this->search());
        self::assertArrayHasKey($gone, $links);
        self::assertNull($links[$gone], 'A deleted record has no page to open');
        self::assertIsString($links[$kept] ?? null);

        $aclBuilder = $this->_objectManager->get(AclBuilder::class);
        self::assertInstanceOf(AclBuilder::class, $aclBuilder);
        $admin = $this->_auth->getUser();
        self::assertInstanceOf(User::class, $admin);
        $aclBuilder->getAcl()->deny($admin->getRoles(), 'Magento_Catalog::products');
        $this->resetRequest();
        // Building admin links switches the shared URL model's secret key back on; the test's request URIs carry none
        $backendUrl = $this->_objectManager->get(BackendUrl::class);
        self::assertInstanceOf(BackendUrl::class, $backendUrl);
        $backendUrl->turnOffSecretKey();

        $events = $this->search();
        self::assertSame(
            [$gone => null, $kept => null],
            $this->subjectUrls($events),
            'Nor a product the admin may not open'
        );
        self::assertSame(['deleted', 'Product'], [
            $events[0]['verb'] ?? null,
            $this->detail($events[0])['subjectTypeLabel'] ?? null,
        ], 'The change is still described');
    }

    #[DataFixture(CategoryFixture::class, ['name' => 'Aal Gear'], 'gear')]
    #[DataFixture(CategoryFixture::class, ['name' => 'Aal Bags & Luggage', 'parent_id' => '$gear.id$'], 'bags')]
    #[DataFixture(AttributeSetFixture::class, ['attribute_set_name' => 'Aal Bottom'], 'bottom')]
    #[DataFixture(AttributeFixture::class, [
        'attribute_code' => 'aal_moment',
        'frontend_input' => 'datetime',
        'backend_type' => 'datetime',
        'default_frontend_label' => 'Aal Moment',
    ])]
    #[DataFixture(ProductFixture::class, ['sku' => 'aal-detail-bag', 'name' => 'Detail Bag'], 'product')]
    #[Config('general/locale/timezone', 'UTC', 'store', 'default')]
    public function testShowsCategoriesByPathAttributeSetsByNameAndDatesAsTheAdminReadsThem(): void
    {
        $bags = $this->fixtureId('bags');
        $gear = $this->fixtureId('gear');
        $bottomSet = DataFixtureStorageManager::getStorage()->get('bottom')?->getData('attribute_set_id');
        self::assertTrue(is_scalar($bottomSet));
        $bottom = (string) $bottomSet;
        $activityId = $this->changes->change(
            'product',
            $this->productId(),
            [
                ['field' => 'category_ids', 'before' => '["' . $gear . '"]', 'after' => '["' . $bags . '","999999"]'],
                ['field' => 'attribute_set_id', 'before' => '4', 'after' => $bottom],
                ['field' => 'type_id', 'before' => 'simple', 'after' => 'virtual'],
                ['field' => 'news_from_date', 'before' => null, 'after' => '2026-10-05 00:00:00'],
                ['field' => 'aal_moment', 'before' => '2026-10-05 15:30:00', 'after' => 'soon'],
            ],
            journal: ['correlation_id' => self::REQUEST]
        );

        // ICU writes a narrow space before the day period; any space reads the same
        $spaced = static fn (mixed $value): mixed => is_string($value) ? preg_replace('/\s+/u', ' ', $value) : $value;
        $fields = [];
        foreach ($this->fields($this->detail($this->search()[0] ?? [])) as $field) {
            $name = $field['name'] ?? null;
            $fields[is_string($name) ? $name : ''] = [
                $field['label'] ?? null,
                $spaced($field['before'] ?? null),
                $spaced($field['after'] ?? null),
                $field['kind'] ?? null,
            ];
        }

        self::assertSame([
            'category_ids' => [
                'Categories',
                '["Aal Gear #' . $gear . '"]',
                '["Aal Gear › Aal Bags & Luggage #' . $bags . '","#999999"]',
                'json',
            ],
            'attribute_set_id' => ['Attribute Set', 'Default', 'Aal Bottom', 'short'],
            'type_id' => ['Product Type', 'Simple Product', 'Virtual Product', 'short'],
            'news_from_date' => ['Set Product as New from Date', null, 'Oct 5, 2026', 'short'],
            'aal_moment' => ['Aal Moment', 'Oct 5, 2026, 3:30 PM', 'soon', 'short'],
        ], $fields, 'Ids by name, a category by path and id, a deleted one by its id; a date alone without its time');

        self::assertContains(
            $activityId,
            $this->changes->find([['value', $bags]]),
            'Search still matches what was stored'
        );
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-detail-bag', 'name' => 'Detail Bag'], 'product')]
    public function testShowsAProductsStockAsTheAdminsStockSettingsDo(): void
    {
        $this->changes->change(
            'product',
            $this->productId(),
            [
                ['field' => 'qty', 'before' => '100.0000', 'after' => '4.5000'],
                ['field' => 'is_in_stock', 'before' => '1', 'after' => '0'],
                ['field' => 'backorders', 'before' => '0', 'after' => '1'],
                ['field' => 'use_config_backorders', 'before' => '1', 'after' => '0'],
                ['field' => 'min_sale_qty', 'before' => '1.0000', 'after' => '2.0000'],
            ],
            journal: ['correlation_id' => self::REQUEST]
        );

        $fields = [];
        foreach ($this->fields($this->detail($this->search()[0] ?? [])) as $field) {
            $name = $field['name'] ?? null;
            $fields[is_string($name) ? $name : ''] = [
                $field['label'] ?? null,
                $field['before'] ?? null,
                $field['after'] ?? null,
            ];
        }

        self::assertSame([
            'qty' => ['Quantity', '100', '4.5'],
            'is_in_stock' => ['Stock Status', 'In Stock', 'Out of Stock'],
            'backorders' => ['Backorders', 'No Backorders', 'Allow Qty Below 0'],
            'use_config_backorders' => ['Backorders: Use Config Settings', 'Yes', 'No'],
            'min_sale_qty' => ['Minimum Qty Allowed in Shopping Cart', '1', '2'],
        ], $fields, 'Quantities without padding zeros, the status and options by their labels');
    }

    public function testCountsTheFieldsThatKeptTheirValueAndSaysWhetherTheirRowsCanBeOpened(): void
    {
        $counted = $this->changes->change(
            'cms_block',
            '999997',
            [['field' => 'title', 'before' => 'Old', 'after' => 'New']],
            journal: ['correlation_id' => self::REQUEST],
            unchangedCount: 39
        );
        $snapshot = $this->changes->change(
            'cms_block',
            '999996',
            [
                ['field' => 'title', 'before' => 'Old', 'after' => 'New'],
                ['field' => 'identifier', 'before' => 'footer', 'after' => 'footer', 'changed' => false],
                ['field' => 'content', 'before' => 'Body', 'after' => 'Body', 'changed' => false],
            ],
            journal: ['correlation_id' => self::REQUEST],
            unchangedCount: 2
        );

        $details = [];
        foreach ($this->search() as $event) {
            $id = $event['id'] ?? null;
            $detail = $this->detail($event);
            $details[is_int($id) ? $id : 0] = [$detail['unchangedCount'] ?? null, $detail['unchangedRecorded'] ?? null];
        }

        self::assertSame([39, false], $details[$counted] ?? null, 'Only the number was kept: nothing to open');
        self::assertSame([2, true], $details[$snapshot] ?? null, 'Their rows were recorded and can be opened');
    }

    public function testShowsSettingsByTheirOptionsMasksSecretsAndCutsLongValuesForTransport(): void
    {
        $setting = $this->changes->change(
            'config_value',
            '12',
            [['field' => 'hryvinskyi_admin_action_log/admin/enabled', 'before' => '1', 'after' => '0']],
            journal: [
                'correlation_id' => self::REQUEST,
                'subject_label' => 'Config value hryvinskyi_admin_action_log/admin/enabled',
            ]
        );
        $longBefore = str_repeat('Lorem ipsum dolor sit amet. ', 200);
        $block = $this->changes->change(
            'cms_block',
            '999998',
            [
                ['field' => 'content', 'before' => $longBefore, 'after' => 'Short now'],
                [
                    'field' => 'api_key',
                    'before' => SanitizerInterface::MASK,
                    'after' => SanitizerInterface::MASK,
                    'masked' => true,
                ],
            ],
            journal: ['correlation_id' => self::REQUEST],
            unchangedCount: 3
        );

        $events = [];
        foreach ($this->search() as $event) {
            $id = $event['id'] ?? null;
            $events[is_int($id) ? $id : 0] = $event;
        }

        $settingDetail = $this->detail($events[$setting] ?? []);
        self::assertSame([
            'Admin Requests › Record Admin Requests',
            'Config value',
            'hryvinskyi_admin_action_log/admin/enabled',
            null,
        ], [
            $settingDetail['what'] ?? null,
            $settingDetail['subjectTypeLabel'] ?? null,
            $settingDetail['subjectLabel'] ?? null,
            self::present($settingDetail, 'subjectUrl'),
        ]);
        $settingField = $this->fields($settingDetail)[0] ?? [];
        self::assertSame(['Admin Requests › Record Admin Requests', 'Yes', 'No'], [
            $settingField['label'] ?? null,
            $settingField['before'] ?? null,
            $settingField['after'] ?? null,
        ]);

        $blockDetail = $this->detail($events[$block] ?? []);
        self::assertSame([null, 2, 'CMS block', 3, null], [
            self::present($blockDetail, 'what'),
            $blockDetail['fieldCount'] ?? null,
            $blockDetail['subjectTypeLabel'] ?? null,
            $blockDetail['unchangedCount'] ?? null,
            self::present($blockDetail, 'subjectLabel'),
        ], 'A name recorded without the kind ("Cms_block #999998" is not "CMS block …") stays as recorded');
        [$content, $secret] = $this->fields($blockDetail) + [[], []];
        self::assertSame(['long', true, mb_strlen($longBefore), 9], [
            $content['kind'] ?? null,
            $content['truncated'] ?? null,
            $content['beforeLength'] ?? null,
            $content['afterLength'] ?? null,
        ]);
        self::assertIsString($content['before'] ?? null);
        self::assertSame(FieldChangeInterface::VALUE_LIMIT, mb_strlen($content['before']));
        self::assertSame(['masked', SanitizerInterface::MASK, SanitizerInterface::MASK], [
            $secret['kind'] ?? null,
            $secret['before'] ?? null,
            $secret['after'] ?? null,
        ]);
    }

    /**
     * The events of the seeded request, newest first, as the endpoint answers them
     *
     * @return list<array<string, mixed>>
     */
    private function search(): array
    {
        $request = $this->getRequest();
        self::assertInstanceOf(\Magento\TestFramework\Request::class, $request);
        $request->setMethod('GET')->setParams([
            'from' => (string) (EntityChanges::NOW_MS - self::DAY_MS),
            'to' => (string) (EntityChanges::NOW_MS + self::DAY_MS),
            'c' => ['request:' . self::REQUEST],
        ]);
        $this->dispatch($this->uri);

        $response = $this->getResponse();
        self::assertInstanceOf(\Magento\TestFramework\Response::class, $response);
        self::assertSame(200, $response->getHttpResponseCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertIsArray($body['events'] ?? null);

        $events = [];
        foreach ($body['events'] as $event) {
            self::assertIsArray($event);
            /** @var array<string, mixed> $event */
            $events[] = $event;
        }

        return $events;
    }

    /**
     * A value of a payload, or "absent" when its key is missing
     *
     * @param array<string, mixed> $data
     * @param string $key
     * @return mixed
     */
    private static function present(array $data, string $key): mixed
    {
        return array_key_exists($key, $data) ? $data[$key] : 'absent';
    }

    /**
     * The subject URL of each event, keyed by activity id
     *
     * @param list<array<string, mixed>> $events
     * @return array<int, string|null>
     */
    private function subjectUrls(array $events): array
    {
        $urls = [];
        foreach ($events as $event) {
            $id = $event['id'] ?? null;
            $url = $this->detail($event)['subjectUrl'] ?? null;
            $urls[is_int($id) ? $id : 0] = is_string($url) ? $url : null;
        }

        return $urls;
    }

    /**
     * The detail of an event, which must have one
     *
     * @param array<mixed> $event
     * @return array<string, mixed>
     */
    private function detail(array $event): array
    {
        $detail = $event['detail'] ?? null;
        self::assertIsArray($detail);
        /** @var array<string, mixed> $detail */
        return $detail;
    }

    /**
     * The field rows of a detail
     *
     * @param array<string, mixed> $detail
     * @return list<array<string, mixed>>
     */
    private function fields(array $detail): array
    {
        $fields = [];
        foreach (is_array($detail['fields'] ?? null) ? $detail['fields'] : [] as $field) {
            self::assertIsArray($field);
            /** @var array<string, mixed> $field */
            $fields[] = $field;
        }

        return $fields;
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
        $id = $record->getData('entity_id') ?? $record->getData('id');
        self::assertTrue(is_scalar($id));

        return (string) $id;
    }

    /**
     * Id of the product the fixture created, as text
     *
     * @return string
     */
    private function productId(): string
    {
        $product = DataFixtureStorageManager::getStorage()->get('product');
        self::assertNotNull($product);
        $id = $product->getData('entity_id');
        self::assertTrue(is_scalar($id));

        return (string) $id;
    }
}
