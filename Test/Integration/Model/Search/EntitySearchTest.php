<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model\Search;

use Hryvinskyi\AdminActionLog\Test\Integration\Model\Search\Fixture\EntityChanges;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Cms\Test\Fixture\Page as PageFixture;
use Magento\Customer\Test\Fixture\Customer as CustomerFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\OrderFactory;
use Magento\SalesRule\Test\Fixture\Rule as CartRuleFixture;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use PHPUnit\Framework\TestCase;

/**
 * The keys entity changes answer, through the search engine and the conditions the container registers
 *
 * Every test empties the journal first, inside its own transaction.
 */
#[DbIsolation(true)]
#[AppArea('adminhtml')]
class EntitySearchTest extends TestCase
{
    /**
     * Most bytes of SQL one query of a search may take, however long its plain-text filter
     */
    private const BOUNDED_SQL_BYTES = 32_768;

    /**
     * @var EntityChanges
     */
    private EntityChanges $changes;

    protected function setUp(): void
    {
        $this->changes = new EntityChanges();
        $this->changes->clear();
    }

    public function testAnIdAfterAHashFindsThatRecordAndNoOther(): void
    {
        $exact = $this->changes->change('product', '1245', [['field' => 'price', 'before' => '10', 'after' => '12']]);
        $this->changes->change(
            'product',
            '12450',
            [['field' => 'price', 'before' => '1', 'after' => '2']],
            journal: ['subject_label' => 'Product #12450 · 1245 ml bottle']
        );
        $this->changes->change('category', '1245', [['field' => 'name', 'before' => 'A', 'after' => 'B']]);

        self::assertSame([$exact], $this->changes->find([['product', '#1245']]));
        self::assertSame([$exact], $this->changes->find([['product', '# 1245']]));
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-search-duffle', 'name' => 'Aal Joust Duffle Bag'], 'product')]
    public function testAProductIsFoundBySkuNameOrIdAndADeletedOneByItsRecordedName(): void
    {
        $productId = $this->fixtureId('product');
        $live = $this->changes->change('product', $productId, [['field' => 'price', 'before' => '1', 'after' => '2']]);
        $deleted = $this->changes->change(
            'product',
            '999991',
            [['field' => 'price', 'before' => '5', 'after' => null]],
            journal: ['subject_label' => 'Product aal-gone-sku · Retired Tote']
        );
        $deletedEarlier = $this->changes->change('product', '999991', [['field' => 'price']]);
        $this->changes->change('product', '999992', [['field' => 'price', 'before' => '7', 'after' => '8']]);

        self::assertSame([$live], $this->changes->find([['product', 'aal-search-duffle']]), 'By SKU');
        self::assertSame([$live], $this->changes->find([['product', 'joust duffle']]), 'By part of the name');
        self::assertSame([$live], $this->changes->find([['product', $productId]]), 'By id');
        self::assertSame(
            [$deleted, $deletedEarlier],
            $this->changes->find([['product', 'aal-gone-sku']]),
            'By the recorded name, with every change of that record'
        );
        self::assertSame([], $this->changes->find([['product', 'aal-nothing-like-it']]));
    }

    public function testASettingPathMatchesWholeSegmentsOfSettingsOnly(): void
    {
        $listMode = $this->changes->change('config_value', '11', [['field' => 'catalog/frontend/list_mode']]);
        $longer = $this->changes->change('config_value', '12', [['field' => 'catalog/frontend/list_mode_extra']]);
        $sibling = $this->changes->change('config_value', '13', [['field' => 'catalog/frontend/grid_per_page']]);
        $this->changes->change('config_value', '14', [['field' => 'catalog/frontend_x/list_mode']]);
        $this->changes->change('product', '15', [['field' => 'catalog/frontend/list_mode']]);

        self::assertSame([$listMode], $this->changes->find([['config', 'catalog/frontend/list_mode']]));
        self::assertSame(
            [$listMode, $longer, $sibling],
            $this->changes->find([['config', 'catalog/frontend']]),
            'A group path finds every setting under it, and a trailing slash changes nothing'
        );
        self::assertSame([$listMode, $longer, $sibling], $this->changes->find([['config', 'catalog/frontend/']]));
        self::assertSame([], $this->changes->find([['config', 'catalog/front']]));
    }

    public function testMaskedValuesAreNeverFoundByValueOrText(): void
    {
        $this->changes->change('config_value', '21', [[
            'field' => 'payment/gateway/api_secret',
            'before' => 'aalhiddenword',
            'after' => 'aalhiddenword',
            'masked' => true,
        ]]);
        $plain = $this->changes->change('cms_block', '22', [['field' => 'content', 'after' => 'aalhiddenword here']]);

        self::assertSame([$plain], $this->changes->find([['value', 'aalhiddenword']]));
        self::assertSame([$plain], $this->changes->find([['text', 'aalhiddenword']]));
        self::assertSame([], $this->changes->find([['value', 'payment/gateway']]), 'value: searches values only');
        $secret = $this->changes->find([['text', 'api_secret']]);
        self::assertCount(1, $secret, 'A masked field is still found by its name');
    }

    public function testFieldsSetAutomaticallyAreFoundLikeAnyOther(): void
    {
        $automatic = $this->changes->change('product', '23', [[
            'field' => 'thumbnail_label',
            'before' => 'aalautolabel',
            'after' => null,
            'automatic' => true,
        ]]);

        self::assertSame([$automatic], $this->changes->find([['field', 'thumbnail_label']]));
        self::assertSame([$automatic], $this->changes->find([['value', 'aalautolabel']]));
        self::assertSame([$automatic], $this->changes->find([['text', 'aalautolabel']]));
    }

    public function testValueTakesWildcardsLiterally(): void
    {
        $percent = $this->changes->change('product', '31', [['field' => 'name', 'after' => '50% off']]);
        $this->changes->change('product', '32', [['field' => 'name', 'after' => '50 and off']]);

        self::assertSame([$percent], $this->changes->find([['value', '50%']]));
        self::assertSame([], $this->changes->find([['value', '5_']]));
    }

    public function testStoreFindsTheJournalsStoreViewOrTheScopeOfAChange(): void
    {
        $storeManager = EntityChanges::service(StoreManagerInterface::class);
        $websiteId = (int) $storeManager->getWebsite('base')->getId();
        $storeId = (int) $storeManager->getStore('default')->getId();

        $inStoreView = $this->changes->change(
            'product',
            '41',
            [['field' => 'name']],
            ['global', 0, 'Global'],
            ['store_id' => $storeId]
        );
        $forWebsite = $this->changes->change(
            'config_value',
            '42',
            [['field' => 'web/unsecure/base_url']],
            ['website', $websiteId, 'Main Website']
        );
        $byDefault = $this->changes->change('config_value', '43', [['field' => 'web/secure/base_url']]);
        $this->changes->change('cms_block', '44', [['field' => 'title']], ['global', 0, 'Global']);

        self::assertSame([$forWebsite], $this->changes->find([['store', 'Main Website']]));
        self::assertSame([$forWebsite], $this->changes->find([['store', 'base']]), 'A website by its code');
        self::assertSame(
            [$inStoreView],
            $this->changes->find([['store', 'default']]),
            'The store view coded "default"; "Default Config" contains the word but is not named by it'
        );
        self::assertSame([$byDefault], $this->changes->find([['store', 'Default Config']]));
        self::assertSame([$byDefault], $this->changes->find([['store', 'config']]), 'A part, with no whole name');
    }

    public function testTheTwoNamesOfTheDefaultScopeStayApartAndAWholeNameWinsOverAPart(): void
    {
        $storeId = (int) EntityChanges::service(StoreManagerInterface::class)->getStore('default')->getId();
        $setting = $this->changes->change('config_value', '45', [['field' => 'web/secure/base_url']]);
        $allViews = $this->changes->change('product', '46', [['field' => 'name']], ['default', 0, 'All Store Views']);
        $inView = $this->changes->change(
            'product',
            '47',
            [['field' => 'name']],
            ['store', $storeId, 'Default Store View']
        );

        self::assertSame([$allViews], $this->changes->find([['store', 'All Store Views']]));
        self::assertSame([$setting], $this->changes->find([['store', 'default config']]), 'Case aside');
        self::assertSame(
            [$inView],
            $this->changes->find([['store', 'Default Store View']]),
            'The whole name of the store view, never "All Store Views" as well'
        );
        self::assertSame(
            [$allViews, $inView],
            $this->changes->find([['store', 'store view']]),
            'A part of both names, which no scope is named in whole'
        );
        self::assertSame(
            [$allViews, $inView],
            $this->changes->find([['store', 'default | All Store Views']]),
            'A store view by its code and the scope its values fall back to, as the record panel asks'
        );
    }

    public function testAScopeOnlyAChangeNamesIsFoundByItsWholeRecordedName(): void
    {
        $inSource = $this->changes->change(
            'product',
            '48',
            [['field' => 'quantity']],
            ['global', 0, 'Default Source']
        );
        $this->changes->change('product', '49', [['field' => 'quantity']], ['global', 0, 'Back Room']);
        $this->changes->change('product', '50', [['field' => 'name']], ['global', 0, 'Global']);

        self::assertSame([$inSource], $this->changes->find([['store', 'Default Source']]));
        self::assertSame([$inSource], $this->changes->find([['store', 'default source']]), 'Case aside');
        self::assertSame([], $this->changes->find([['store', 'Source']]), 'A part of a recorded name is no name');
    }

    public function testValueAndPlainWordsFindChangedFieldsOnly(): void
    {
        $snapshot = $this->changes->change('product', '48', [
            ['field' => 'aal_snapshot_code', 'before' => 'aalkeptvalue', 'after' => 'aalkeptvalue', 'changed' => false],
            ['field' => 'status', 'before' => '1', 'after' => '2'],
        ]);
        $changed = $this->changes->change('product', '49', [
            ['field' => 'aal_changed_code', 'before' => 'old', 'after' => 'aalkeptvalue'],
        ]);

        self::assertSame([$changed], $this->changes->find([['value', 'aalkeptvalue']]));
        self::assertSame([$changed], $this->changes->find([['text', 'aalkeptvalue']]));
        self::assertSame([], $this->changes->find([['text', 'aal_snapshot_code']]), 'A field kept unchanged');
        self::assertSame([$changed], $this->changes->find([['text', 'aal_changed_code']]));
        self::assertSame([$snapshot], $this->changes->find([['field', 'status']]));
    }

    public function testEntityFindsAKindByCodeOrLabel(): void
    {
        $block = $this->changes->change('cms_block', '51', [['field' => 'title']]);
        $page = $this->changes->change('cms_page', '52', [['field' => 'title']]);

        self::assertSame([$block], $this->changes->find([['entity', 'cms_block']]));
        self::assertSame([$page], $this->changes->find([['entity', 'CMS Page']]));
        self::assertSame([], $this->changes->find([['entity', 'cms']]));
    }

    public function testFieldFindsChangedFieldsByCodePartOfCodeOrLabel(): void
    {
        $status = $this->changes->change('product', '61', [['field' => 'status', 'before' => '1', 'after' => '2']]);
        $special = $this->changes->change('product', '62', [
            ['field' => 'special_price', 'before' => '1', 'after' => '2'],
            ['field' => 'status', 'before' => '1', 'after' => '1', 'changed' => false],
        ]);
        $listMode = $this->changes->change('config_value', '63', [['field' => 'catalog/frontend/list_mode']]);

        self::assertSame(
            [$status],
            $this->changes->find([['field', 'status']]),
            'A field a snapshot kept unchanged does not count'
        );
        self::assertSame([$status], $this->changes->find([['field', 'Enable Product']]), 'By attribute label');
        self::assertSame([$special], $this->changes->find([['field', 'price']]), 'By part of the code');
        self::assertSame([$listMode], $this->changes->find([['field', 'List Mode']]), 'By setting label');
        self::assertSame([], $this->changes->find([['field', 'Enable']]), 'A label is matched whole');
    }

    public function testFieldFindsAProductFieldKeptByIdByItsLabel(): void
    {
        $set = $this->changes->change('product', '67', [
            ['field' => 'attribute_set_id', 'before' => '9', 'after' => '10'],
        ]);
        $this->changes->change('product', '68', [['field' => 'price', 'before' => '1', 'after' => '2']]);

        self::assertSame([$set], $this->changes->find([['field', 'Attribute Set']]), 'By the label it is shown with');
        self::assertSame([$set], $this->changes->find([['field', 'attribute set']]), 'Case aside');
    }

    public function testAQuotedFieldNamesOneCodeExactly(): void
    {
        $price = $this->changes->change('product', '64', [['field' => 'price', 'before' => '1', 'after' => '2']]);
        $special = $this->changes->change('product', '65', [
            ['field' => 'special_price', 'before' => '1', 'after' => '2'],
        ]);
        $snapshot = $this->changes->change('product', '66', [
            ['field' => 'price', 'before' => '3', 'after' => '3', 'changed' => false],
        ]);

        self::assertSame([$price], $this->changes->find([['field', '"price"']]), 'Exactly that code, changed');
        self::assertSame([$price, $special], $this->changes->find([['field', 'price']]), 'Unquoted, part of a code');
        self::assertSame([], $this->changes->find([['field', '"Special Price"']]), 'A code, not a label');
        self::assertSame([], $this->changes->find([['field', '"pric"']]), 'Never a part of a code');
        self::assertNotContains($snapshot, $this->changes->find([['field', '"price"']]));
    }

    public function testTextFindsEveryWordAcrossTheFieldsOfOneChange(): void
    {
        $shirt = $this->changes->change('product', '71', [
            ['field' => 'description', 'before' => 'plain', 'after' => 'soft aalcotton shirt'],
            ['field' => 'meta_title', 'after' => 'Aaltee'],
        ]);
        $status = $this->changes->change('product', '72', [['field' => 'status', 'before' => '1', 'after' => '2']]);

        self::assertSame([$shirt], $this->changes->find([['text', 'aalcotton aaltee']]));
        self::assertSame([], $this->changes->find([['text', 'aalcotton zebraqx']]));
        self::assertSame([$shirt], $this->changes->find([['text', 'meta_title']]), 'A field code');
        self::assertSame([$status], $this->changes->find([['text', 'enable']]), 'Part of an attribute label');
    }

    public function testPlainWordsMayBeFoundOneInTheJournalAndAnotherInWhatChanged(): void
    {
        $price = $this->changes->change(
            'product',
            '81',
            [['field' => 'price', 'before' => '34.00', 'after' => '39.00']],
            journal: ['subject_label' => 'Product 24-MB01 · Joust Duffle Bag']
        );
        $this->changes->change(
            'product',
            '81',
            [['field' => 'name', 'before' => 'Bag', 'after' => 'Duffle']],
            journal: ['subject_label' => 'Product 24-MB01 · Joust Duffle Bag']
        );
        $this->changes->change(
            'product',
            '82',
            [['field' => 'price', 'before' => '1.00', 'after' => '2.00']],
            journal: ['subject_label' => 'Product 24-WB04 · Overnight Duffle']
        );

        self::assertSame([$price], $this->changes->find([['text', 'MB01 price']]));
        self::assertSame([$price], $this->changes->find([['text', 'price MB01']]), 'In any order');
        self::assertSame([], $this->changes->find([['text', 'MB01 zebraqx']]), 'Every word is required');
    }

    public function testALongPlainTextFilterOfSingleLettersBuildsABoundedQuery(): void
    {
        $this->changes->change('product', '83', [['field' => 'name', 'before' => 'a', 'after' => 'abcdefgh']]);
        $letters = mb_substr(str_repeat('a b c d e f g h i j k l m n o p q r s t u v w x y z ', 10), 0, 255);
        self::assertSame(255, mb_strlen($letters));

        $connection = EntityChanges::service(ResourceConnection::class)->getConnection();
        self::assertInstanceOf(\Zend_Db_Adapter_Abstract::class, $connection);
        $profiler = $connection->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $found = $this->changes->find([['text', $letters]]);
            $queries = 0;
            $longest = 0;
            $total = 0;
            foreach ($profiler->getQueryProfiles() ?: [] as $profile) {
                self::assertInstanceOf(\Zend_Db_Profiler_Query::class, $profile);
                $queries++;
                $longest = max($longest, strlen($profile->getQuery()));
                $total += strlen($profile->getQuery());
            }
        } finally {
            $profiler->setEnabled(false);
            $profiler->clear();
        }

        self::assertCount(1, $found, 'The first eight distinct letters are all in the change');
        self::assertGreaterThan(0, $queries);
        self::assertLessThan(self::BOUNDED_SQL_BYTES, $longest, 'The longest query stays small');
        self::assertLessThan(self::BOUNDED_SQL_BYTES * 10, $total, 'So does the whole search');
    }

    #[DataFixture('Magento/Sales/_files/order.php')]
    public function testAnOrderIsFoundWithItsComments(): void
    {
        $order = EntityChanges::service(OrderFactory::class)->create()->loadByIncrementId('100000001');
        $orderId = is_scalar($order->getEntityId()) ? (string) $order->getEntityId() : '';
        $resource = EntityChanges::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $connection->insert($resource->getTableName('sales_order_status_history'), [
            'parent_id' => (int) $orderId,
            'comment' => 'Called the customer',
            'entity_name' => 'order',
        ]);
        $commentId = (string) $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('sales_order_status_history'), ['MAX(entity_id)'])
                ->where('parent_id = ?', (int) $orderId)
        );

        $orderChange = $this->changes->change('order', $orderId, [['field' => 'status']]);
        $commentChange = $this->changes->change('order_comment', $commentId, [['field' => 'comment']]);
        $this->changes->change('order_comment', '999993', [['field' => 'comment']]);

        self::assertSame([$orderChange, $commentChange], $this->changes->find([['order', '100000001']]));
        self::assertSame([$orderChange, $commentChange], $this->changes->find([['order', '#' . $orderId]]));
        self::assertSame(
            [$orderChange, $commentChange],
            $this->changes->find([['order', '#100000001']]),
            'The exact form names an order by its number as well as by its id'
        );
        self::assertSame([], $this->changes->find([['order', '#10000000']]), 'Never a part of a number');
    }

    #[DataFixture(CategoryFixture::class, ['name' => 'Aal Summer Gear'], 'category')]
    #[DataFixture(PageFixture::class, ['identifier' => 'aal-returns', 'title' => 'Aal Returns Policy'], 'page')]
    #[DataFixture(
        CustomerFixture::class,
        ['email' => 'aal.jane@example.com', 'firstname' => 'Aaljane', 'lastname' => 'Doe'],
        'customer'
    )]
    #[DataFixture(CartRuleFixture::class, ['name' => 'Aal Autumn Discount'], 'rule')]
    public function testRecordsAreFoundByWhatPeopleKnowOfThem(): void
    {
        $category = $this->changes->change('category', $this->fixtureId('category'), [['field' => 'name']]);
        $page = $this->changes->change('cms_page', $this->fixtureId('page'), [['field' => 'title']]);
        $customer = $this->changes->change('customer', $this->fixtureId('customer'), [['field' => 'email']]);
        $rule = $this->changes->change('cart_price_rule', $this->fixtureId('rule'), [['field' => 'name']]);

        self::assertSame([$category], $this->changes->find([['category', 'summer gear']]));
        self::assertSame([$page], $this->changes->find([['page', 'aal-returns']]));
        self::assertSame([$page], $this->changes->find([['page', 'returns policy']]));
        self::assertSame([$customer], $this->changes->find([['customer', 'aal.jane@example.com']]));
        self::assertSame([$customer], $this->changes->find([['customer', 'Aaljane Doe']]));
        self::assertSame([$rule], $this->changes->find([['rule', 'autumn discount']]));
    }

    /**
     * Id of a record a data fixture created, as text
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
