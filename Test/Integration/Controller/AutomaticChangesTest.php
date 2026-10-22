<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller;

use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeJournal;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeRecorder;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Observer\EntityChange\RecordCommittedChange;
use Hryvinskyi\AdminActionLog\Observer\EntityChange\SnapshotBeforeDelete;
use Hryvinskyi\AdminActionLog\Observer\EntityChange\SnapshotBeforeSave;
use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ProductFactory;
use Magento\Catalog\Test\Fixture\MultiselectAttribute;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Message\MessageInterface;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager as TestObjectManager;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Saves through the admin product form record what Magento sets on its own as set automatically, and what the admin
 * changed as asked for
 *
 * The product keeps a stored label for each image role, as products imported with labels do, and no value for a Yes/No
 * setting that follows the configuration; the form shows an existing gallery image with an empty label and that
 * setting switched off with its "Use Config Settings" box clear. Saving that form untouched makes Magento's media
 * gallery handler clear the labels and stores the setting as "No". Entity changes are journaled when the product's
 * transaction commits, so these tests commit for real and remove what they wrote.
 *
 * The payloads are those the product form posts: the quantity and the stock status the admin edits under
 * quantity_and_stock_status beside the values the form loaded under stock_data, a cleared multiple-choice field as
 * an empty text, no category list once every category is removed, and "Use Default Value" boxes at a store view.
 *
 * @phpstan-import-type ChangeDetail from EntityChangeStore
 */
#[AppArea('adminhtml')]
#[DbIsolation(false)]
class AutomaticChangesTest extends AbstractBackendController
{
    private const SKU = 'aal-auto-bag';
    private const OWN_STOCK_PART = 'Hryvinskyi\AdminActionLog\Model\EntityChange\ProductStockItemPart';
    private const CATEGORY_ID = 2;
    private const STORE_VIEW = 1;
    private const NAME = 'Aal Auto Bag';
    private const IMAGE = '/a/a/aal-auto-bag.jpg';
    private const LABELS = ['image_label', 'small_image_label', 'thumbnail_label'];
    private const STOCK = [
        'qty' => '100',
        'is_in_stock' => '1',
        'manage_stock' => '1',
        'use_config_manage_stock' => '1',
    ];

    /**
     * @var string
     */
    protected $resource = 'Magento_Catalog::products';

    /**
     * @var string
     */
    protected $uri = 'backend/catalog/product/save';

    /**
     * @var string
     */
    protected $httpMethod = HttpRequest::METHOD_POST;

    /**
     * @var string
     */
    private string $productId = '';

    /**
     * @var int
     */
    private int $imageId = 0;

    /**
     * The tracked entity pool's parts as installed, when a test replaced them
     *
     * @var mixed
     */
    private mixed $installedParts = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->removeProduct();
        $this->createProduct();
    }

    protected function tearDown(): void
    {
        $this->removeProduct();
        if ($this->installedParts !== null) {
            $this->useParts($this->installedParts);
            $this->installedParts = null;
        }
        parent::tearDown();
    }

    public function testAnUntouchedSaveMarksWhatMagentoClearedAndSetAsSetAutomatically(): void
    {
        self::assertSame(
            array_fill_keys(self::LABELS, self::NAME) + ['gift_message_available' => null],
            $this->stored(array_merge(self::LABELS, ['gift_message_available'])),
            'The labels are stored and the setting follows the configuration'
        );
        $since = $this->latestActivityId();

        $this->saveForm([]);

        $fields = $this->changedFields($since);
        foreach (self::LABELS as $label) {
            self::assertSame(['before' => self::NAME, 'after' => null, 'automatic' => true], $fields[$label] ?? null);
        }
        self::assertSame(
            ['before' => null, 'after' => '0', 'automatic' => true],
            $fields['gift_message_available'] ?? null,
            'The setting left on the configuration is stored as No by itself'
        );
        self::assertSame(
            [],
            array_filter($fields, static fn (array $field): bool => !$field['automatic']),
            'The admin asked for no change'
        );
    }

    public function testAnEditedPriceIsAskedForWhileTheLabelsClearedWithItAreNot(): void
    {
        $since = $this->latestActivityId();

        $this->saveForm(['price' => '39.00']);

        $fields = $this->changedFields($since);
        self::assertFalse($fields['price']['automatic'] ?? null, 'The price was asked for');
        self::assertSame(
            [true, true, true],
            array_map(static fn (string $label): ?bool => $fields[$label]['automatic'] ?? null, self::LABELS)
        );
    }

    public function testASettingTheAdminSwitchedOnIsAskedFor(): void
    {
        $since = $this->latestActivityId();

        $this->saveForm(['gift_message_available' => '1']);

        self::assertSame(
            ['before' => null, 'after' => '1', 'automatic' => false],
            $this->changedFields($since)['gift_message_available'] ?? null
        );
    }

    public function testUseDefaultTickedAtAStoreViewOverAnEnabledStatusIsAskedFor(): void
    {
        $this->storeValue('catalog_product_entity_int', 'status', 0, '2');
        $this->storeValue('catalog_product_entity_int', 'status', self::STORE_VIEW, '1');
        $since = $this->latestActivityId();

        $this->saveForm(['status' => '1'], [
            'store' => (string) self::STORE_VIEW,
            'use_default' => ['status' => '1', 'name' => '1', 'visibility' => '1'],
        ]);

        $status = $this->changedFields($since)['status'] ?? null;
        self::assertNotNull($status, 'The status change was recorded');
        self::assertSame('1', $status['before'], 'The store view held its own Enabled');
        self::assertFalse(
            $status['automatic'],
            'The admin ticked "Use Default Value": the field\'s old "1" is no ticked box'
        );
    }

    public function testAQuantityTypedIntoTheFormIsAskedForThoughTheFormAlsoSendsTheLoadedOne(): void
    {
        $this->useOwnStockPart();
        $since = $this->latestActivityId();

        $this->saveForm(['quantity_and_stock_status' => ['qty' => '95', 'is_in_stock' => '1']]);

        $quantity = $this->changedFields($since)['qty'] ?? null;
        self::assertNotNull($quantity, 'The stock item\'s quantity change was recorded for the product');
        self::assertEquals([100, 95], [(float) $quantity['before'], (float) $quantity['after']]);
        self::assertFalse($quantity['automatic'], 'Typed under quantity_and_stock_status, loaded under stock_data');
    }

    public function testStockSettingsKeptOnTheConfigurationAreNoChangesThoughTheSaveWritesTheirColumns(): void
    {
        $this->useOwnStockPart();
        $resource = self::service(ResourceConnection::class);
        $table = $resource->getTableName('cataloginventory_stock_item');
        $resource->getConnection()->update($table, [
            'max_sale_qty' => 0,
            'use_config_max_sale_qty' => 1,
            'notify_stock_qty' => null,
            'use_config_notify_stock_qty' => 1,
            'manage_stock' => 0,
            'use_config_manage_stock' => 1,
            'qty_increments' => 0,
            'use_config_qty_increments' => 1,
        ], ['product_id = ?' => (int) $this->productId]);
        self::service(StockRegistryStorage::class)->clean();
        $since = $this->latestActivityId();

        $this->saveForm([
            'price' => '29.00',
            'stock_data' => [
                'qty' => '100',
                'is_in_stock' => '1',
                'max_sale_qty' => '10000',
                'use_config_max_sale_qty' => '1',
                'notify_stock_qty' => '1',
                'use_config_notify_stock_qty' => '1',
                'manage_stock' => '1',
                'use_config_manage_stock' => '1',
                'qty_increments' => '1',
                'use_config_qty_increments' => '1',
            ],
        ]);

        $written = $resource->getConnection()->fetchRow(
            $resource->getConnection()->select()
                ->from($table, ['max_sale_qty', 'manage_stock'])
                ->where('product_id = ?', (int) $this->productId)
        );
        self::assertIsArray($written);
        self::assertIsNumeric($written['max_sale_qty'] ?? null);
        self::assertIsNumeric($written['manage_stock'] ?? null);
        self::assertEquals(
            [10000, 1],
            [(float) $written['max_sale_qty'], (int) $written['manage_stock']],
            'The save wrote the configuration\'s values into the stock item\'s columns'
        );
        $changed = $this->changedFields($since);
        self::assertArrayHasKey('price', $changed, 'The edited price is recorded');
        self::assertSame(
            [],
            array_intersect(
                ['max_sale_qty', 'notify_stock_qty', 'manage_stock', 'qty_increments'],
                array_keys($changed)
            ),
            'Use Config Settings stayed ticked: the shop kept following the configuration'
        );
    }

    public function testRemovingEveryCategoryIsAskedForWhileTheClearedLabelsAreNot(): void
    {
        $resource = self::service(ResourceConnection::class);
        $resource->getConnection()->insert($resource->getTableName('catalog_category_product'), [
            'category_id' => self::CATEGORY_ID,
            'product_id' => (int) $this->productId,
            'position' => 0,
        ]);
        $since = $this->latestActivityId();

        $this->saveForm([]);

        $fields = $this->changedFields($since);
        self::assertSame(
            ['before' => '["' . self::CATEGORY_ID . '"]', 'after' => '[]', 'automatic' => false],
            $fields['category_ids'] ?? null,
            'The form sends no category list once the last category is removed'
        );
        self::assertSame(
            [true, true, true],
            array_map(static fn (string $label): ?bool => $fields[$label]['automatic'] ?? null, self::LABELS)
        );
    }

    #[DataFixture(MultiselectAttribute::class, ['attribute_code' => 'aal_auto_colors'], 'colors')]
    public function testClearingAMultipleChoiceFieldIsAskedFor(): void
    {
        $attribute = DataFixtureStorageManager::getStorage()->get('colors');
        self::assertInstanceOf(ProductAttributeInterface::class, $attribute);
        $options = array_values(array_filter(
            array_map(static fn ($option): string => (string) $option->getValue(), $attribute->getOptions() ?? []),
            static fn (string $value): bool => $value !== ''
        ));
        self::assertCount(2, $options);
        $this->storeValue('catalog_product_entity_text', 'aal_auto_colors', 0, implode(',', $options));
        $since = $this->latestActivityId();

        $this->saveForm(['aal_auto_colors' => '']);

        $colors = $this->changedFields($since)['aal_auto_colors'] ?? null;
        self::assertNotNull($colors, 'The emptied field was recorded');
        self::assertSame(implode(',', $options), $colors['before']);
        self::assertFalse($colors['automatic'], 'An emptied multiple-choice field was asked for');
    }

    public function testAChangeMadeOutsideTheAdminFormIsNeverAutomatic(): void
    {
        $this->saveForm([]);
        $since = $this->latestActivityId();

        $repository = self::service(ProductRepositoryInterface::class);
        $product = $repository->get(self::SKU, true, 0, true);
        self::assertInstanceOf(Product::class, $product);
        $product->setData('meta_title', 'Aal Auto Bag Title');
        $product->setData('thumbnail_label', 'Aal Auto Thumbnail');
        $repository->save($product);

        $fields = $this->changedFields($since);
        self::assertSame([false, false], [
            $fields['meta_title']['automatic'] ?? null,
            $fields['thumbnail_label']['automatic'] ?? null,
        ]);
        self::assertSame(
            [],
            array_keys(array_filter($fields, static fn (array $field): bool => $field['automatic'])),
            'A save after the admin action, as a service, the web API or a command makes it, asked for its changes'
        );
    }

    /**
     * Dispatch the product form's save with the product as the form shows it, and these fields changed
     *
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $parameters Parameters beside the product's, replacing the defaults
     * @return void
     */
    private function saveForm(array $changes, array $parameters = []): void
    {
        $request = $this->getRequest();
        self::assertInstanceOf(HttpRequest::class, $request);
        $request->setMethod(HttpRequest::METHOD_POST);
        $request->setPostValue($parameters + [
            'type' => 'simple',
            'set' => '4',
            'store' => '0',
            'product' => $changes + [
                'attribute_set_id' => '4',
                'status' => '1',
                'name' => self::NAME,
                'sku' => self::SKU,
                'price' => '34.00',
                'weight' => '1',
                'product_has_weight' => '1',
                'tax_class_id' => '2',
                'visibility' => '4',
                'website_ids' => [1 => '1'],
                'image' => self::IMAGE,
                'small_image' => self::IMAGE,
                'thumbnail' => self::IMAGE,
                'media_gallery' => ['images' => [$this->imageId => [
                    'value_id' => (string) $this->imageId,
                    'file' => self::IMAGE,
                    'media_type' => 'image',
                    'label' => '',
                    'position' => '1',
                    'disabled' => '0',
                    'removed' => '',
                ]]],
                'gift_message_available' => '0',
                'use_config_gift_message_available' => '0',
                'quantity_and_stock_status' => ['qty' => '100', 'is_in_stock' => '1'],
                'stock_data' => self::STOCK,
            ],
        ]);
        $this->dispatch('backend/catalog/product/save/id/' . $this->productId);

        $this->assertSessionMessages(self::containsEqual('You saved the product.'), MessageInterface::TYPE_SUCCESS);
    }

    /**
     * Put this module's own stock item part back in the tracked entity pool for the test
     *
     * Another module that records stock from another source may replace it; the test is about the product form's
     * payload as this module's part records it.
     *
     * @return void
     */
    private function useOwnStockPart(): void
    {
        $arguments = self::service(ConfigInterface::class)->getArguments(TrackedEntityPool::class);
        $this->installedParts = $arguments['parts'] ?? [];
        $this->useParts(['product_stock_item' => ['instance' => self::OWN_STOCK_PART]]);
        self::service(StockRegistryStorage::class)->clean();
    }

    /**
     * Give the tracked entity pool these parts, and forget the services built with the pool they had
     *
     * @param mixed $parts The "parts" argument as the container's configuration holds it
     * @return void
     */
    private function useParts(mixed $parts): void
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(TestObjectManager::class, $objectManager);
        $objectManager->configure([TrackedEntityPool::class => ['arguments' => ['parts' => $parts]]]);
        foreach ([
            TrackedEntityPool::class,
            EntityChangeJournal::class,
            EntityChangeJournal::class . '\\Proxy',
            EntityChangeRecorder::class,
            SnapshotBeforeSave::class,
            SnapshotBeforeDelete::class,
            RecordCommittedChange::class,
        ] as $built) {
            $objectManager->removeSharedInstance($built);
        }
    }

    /**
     * Store a value of the product's attribute in a store view, replacing what is there
     *
     * @param string $table
     * @param string $code
     * @param int $storeId
     * @param string $value
     * @return void
     */
    private function storeValue(string $table, string $code, int $storeId, string $value): void
    {
        $resource = self::service(ResourceConnection::class);
        $resource->getConnection()->insertOnDuplicate($resource->getTableName($table), [
            'attribute_id' => $this->attributeId($code),
            'store_id' => $storeId,
            'entity_id' => (int) $this->productId,
            'value' => $value,
        ], ['value']);
    }

    /**
     * A product with an image in every role, a label stored for each role and the Yes/No setting left unset
     *
     * @return void
     */
    private function createProduct(): void
    {
        $product = self::service(ProductFactory::class)->create();
        $product->setSku(self::SKU)
            ->setName(self::NAME)
            ->setAttributeSetId(4)
            ->setTypeId('simple')
            ->setPrice(34)
            ->setWeight(1)
            ->setStatus(1)
            ->setVisibility(4)
            ->setWebsiteIds([1]);
        foreach (['image', 'small_image', 'thumbnail'] as $role) {
            $product->setData($role, self::IMAGE);
            $product->setData($role . '_label', self::NAME);
        }
        $product->setStockData(self::STOCK);
        $this->productId = (string) self::service(ProductRepositoryInterface::class)->save($product)->getId();

        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $connection->insert($resource->getTableName('catalog_product_entity_media_gallery'), [
            'attribute_id' => $this->attributeId('media_gallery'),
            'value' => self::IMAGE,
            'media_type' => 'image',
            'disabled' => 0,
        ]);
        $imageId = $connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName('catalog_product_entity_media_gallery'), ['value_id'])
                ->where('value = ?', self::IMAGE)
        )[0] ?? null;
        self::assertIsNumeric($imageId);
        $this->imageId = (int) $imageId;
        $connection->insert($resource->getTableName('catalog_product_entity_media_gallery_value'), [
            'value_id' => $this->imageId,
            'store_id' => 0,
            'entity_id' => (int) $this->productId,
            'label' => null,
            'position' => 1,
            'disabled' => 0,
        ]);
        $connection->insert($resource->getTableName('catalog_product_entity_media_gallery_value_to_entity'), [
            'value_id' => $this->imageId,
            'entity_id' => (int) $this->productId,
        ]);
    }

    /**
     * Remove the product, its image and every journal row about it
     *
     * @return void
     */
    private function removeProduct(): void
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $connection->delete(
            $resource->getTableName('catalog_product_entity_media_gallery'),
            ['value = ?' => self::IMAGE]
        );
        $connection->delete($resource->getTableName('catalog_product_entity'), ['sku = ?' => self::SKU]);
        $connection->delete($resource->getTableName('url_rewrite'), ['request_path LIKE ?' => self::SKU . '%']);
        $connection->delete($resource->getTableName('hryvinskyi_activity_log'), [
            'subject_label LIKE ?' => '%' . self::SKU . '%',
        ]);
    }

    /**
     * The product's stored default values of these attributes, by code; null where none is stored
     *
     * @param list<string> $codes
     * @return array<string, string|null>
     */
    private function stored(array $codes): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $values = array_fill_keys($codes, null);
        foreach ($codes as $code) {
            $value = $connection->fetchCol(
                $connection->select()
                    ->from($resource->getTableName('catalog_product_entity_varchar'), ['value'])
                    ->where('entity_id = ?', (int) $this->productId)
                    ->where('store_id = ?', 0)
                    ->where('attribute_id = ?', $this->attributeId($code))
            )[0] ?? null;
            $values[$code] = is_string($value) ? $value : null;
        }

        return $values;
    }

    /**
     * The changed fields of the product's changes recorded after an activity, by code: values and the mark
     *
     * @param int $since
     * @return array<string, array{before: string|null, after: string|null, automatic: bool}>
     */
    private function changedFields(int $since): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $fields = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from(['f' => $resource->getTableName(EntityChangeStore::FIELD_TABLE)], [])
                ->join(
                    ['c' => $resource->getTableName(EntityChangeStore::TABLE)],
                    'c.activity_id = f.activity_id',
                    []
                )
                ->columns(['f.field', 'f.before_value', 'f.after_value', 'f.is_automatic'])
                ->where('c.entity_type = ?', 'product')
                ->where('c.entity_id = ?', $this->productId)
                ->where('f.activity_id > ?', $since)
                ->where('f.is_changed = ?', 1)
                ->order('f.field_row_id ASC')
        ) as $row) {
            if (is_array($row) && is_string($row['field'] ?? null)) {
                $fields[$row['field']] = [
                    'before' => is_string($row['before_value'] ?? null) ? $row['before_value'] : null,
                    'after' => is_string($row['after_value'] ?? null) ? $row['after_value'] : null,
                    'automatic' => (bool) ($row['is_automatic'] ?? false),
                ];
            }
        }
        self::assertNotSame([], $fields, 'The save recorded no change of the product.');

        return $fields;
    }

    /**
     * Id of the newest journal row
     *
     * @return int
     */
    private function latestActivityId(): int
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()->from($resource->getTableName('hryvinskyi_activity_log'), ['MAX(entity_id)'])
        );

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * A service from the real container, typed
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * Id of a product attribute
     *
     * @param string $code
     * @return int
     */
    private function attributeId(string $code): int
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('eav_attribute'), ['attribute_id'])
                ->where('attribute_code = ?', $code)
                ->where('entity_type_id = ?', 4)
        );
        self::assertIsNumeric($id, sprintf('The product attribute "%s" does not exist.', $code));

        return (int) $id;
    }
}
