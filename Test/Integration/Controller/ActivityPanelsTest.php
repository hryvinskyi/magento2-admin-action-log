<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\AdminRequestStore;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\ViewModel\EntityChangePanel;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\BlockFactory;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Magento\User\Model\User;

/**
 * The admin request and entity change panels on the activity detail page, through the real layout
 */
class ActivityPanelsTest extends AbstractBackendController
{
    /**
     * The inherited access tests: an admin who may not view the log is refused the page the panels show on
     *
     * @var string
     */
    protected $resource = 'Hryvinskyi_ActivityLog::view';

    /**
     * @var string
     */
    protected $uri = 'backend/activitylog/activity/view/id/0';

    /**
     * Gives back what a test changed on objects every later test shares: the admin's permissions, and the change
     * store a test replaced
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->service(AclBuilder::class)->resetRuntimeAcl();
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);
        $objectManager->removeSharedInstance(EntityChangeStore::class);
        $objectManager->removeSharedInstance(EntityChangePanel::class);
        parent::tearDown();
    }

    public function testAnAdminRequestShowsItsRouteParametersResponseAndMessages(): void
    {
        $id = $this->insertActivity('admin', 'catalog/product/save', 'Product #1188');
        $this->service(AdminRequestStore::class)->save($id, [
            'route' => 'catalog/product/save',
            'method' => 'POST',
            'controller' => 'Magento\Catalog\Controller\Adminhtml\Product\Save',
            'ajax' => false,
            'parameters' => [
                ['name' => 'product[name]', 'value' => 'Chaz Kangaroo Hoodie', 'tag' => ''],
                ['name' => 'form_key', 'value' => SanitizerInterface::MASK, 'tag' => 'masked'],
            ],
            'omitted' => 3,
            'responseCode' => 302,
            'redirectPath' => 'catalog/product/edit/id/1188',
            'messages' => [['type' => 'success', 'text' => 'You saved the product.']],
        ]);

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $html = $this->body();

        self::assertStringContainsString('Admin request', $html);
        self::assertStringContainsString('Magento\Catalog\Controller\Adminhtml\Product\Save', $html);
        self::assertStringContainsString('catalog/product/edit/id/1188', $html);
        self::assertStringContainsString('class="admin-action-panel__arrow lucide lucide-arrow-right"', $html);
        self::assertStringContainsString('class="menu-item-icon lucide lucide-mouse-pointer-click"', $html);
        self::assertStringContainsString('product[name]', $html);
        self::assertStringContainsString('admin-action-tag', $html);
        self::assertStringContainsString('3 more parameters were not stored.', $html);
        self::assertStringContainsString('You saved the product.', $html);
        self::assertStringNotContainsString('No installed module records more about this channel.', $html);
    }

    public function testAnEntityChangeShowsItsFieldsGroupedByScope(): void
    {
        $id = $this->insertActivity('entity', 'Product saved', 'Product #1245 · price, special_price');
        $scope = $this->service(EntityScopeInterfaceFactory::class)->create([
            'type' => 'store',
            'id' => 1,
            'label' => 'Default Store View',
        ]);
        $this->service(EntityChangeStore::class)->save(
            $id,
            'product',
            '1245',
            ChangeOperation::Update,
            DiffMode::Snapshot,
            1,
            $scope,
            [
                [
                    'field' => 'price',
                    'before' => '49.00',
                    'after' => '39.00',
                    'changed' => true,
                    'masked' => false,
                    'automatic' => false,
                ],
                [
                    'field' => 'special_price',
                    'before' => null,
                    'after' => '34.90',
                    'changed' => true,
                    'masked' => false,
                    'automatic' => false,
                ],
                [
                    'field' => 'image_label',
                    'before' => 'Joust Duffle Bag',
                    'after' => null,
                    'changed' => true,
                    'masked' => false,
                    'automatic' => true,
                ],
                [
                    'field' => 'meta_title',
                    'before' => 'Joust Duffle Bag',
                    'after' => 'Joust Duffle Bag',
                    'changed' => false,
                    'masked' => false,
                    'automatic' => false,
                ],
            ]
        );

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $html = $this->body();

        self::assertStringContainsString('Entity change', $html);
        self::assertStringContainsString('Default Store View', $html);
        self::assertStringContainsString('store · 1', $html);
        self::assertStringContainsString('data-field="special_price"', $html);
        self::assertStringContainsString('(empty)', $html);
        self::assertStringContainsString('Show unchanged fields (1)', $html);
        self::assertMatchesRegularExpression('/data-field="meta_title"\s+data-changed="0"/', $html);
        self::assertSame(1, substr_count($html, 'admin-action-tag--automatic'), 'Only the field set automatically');
        self::assertMatchesRegularExpression(
            '/data-field="image_label".*?admin-action-tag--automatic.*?set automatically/s',
            $html
        );
    }

    #[DbIsolation(true)]
    public function testTheSubjectOfAnEntityChangeLinksToTheRecordsEditPage(): void
    {
        $blockId = $this->savedBlock('aal-panel-link');
        $id = $this->insertActivity('entity', 'CMS block saved', 'Block aal-panel-link');
        $this->saveBlockChange($id, $blockId);

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);

        self::assertMatchesRegularExpression(
            '~<a class="activity-section__note admin-action-diff__record"\s+href="[^"]*cms/block/edit/block_id/'
            . $blockId . '/[^"]*">\s*Block aal-panel-link\s*</a>~',
            $this->body()
        );
    }

    #[DbIsolation(true)]
    public function testTheLinkTextIsOpenTheRecordWhenTheActivityHasNoSubject(): void
    {
        $blockId = $this->savedBlock('aal-panel-unnamed');
        $id = $this->insertActivity('entity', 'CMS block saved', 'Block aal-panel-unnamed');
        $this->clearSubject($id);
        $this->saveBlockChange($id, $blockId);

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);

        self::assertMatchesRegularExpression(
            '~<a class="activity-section__note admin-action-diff__record"\s+href="[^"]*cms/block/edit/block_id/'
            . $blockId . '/[^"]*">\s*Open the record\s*</a>~',
            $this->body()
        );
    }

    #[DbIsolation(true)]
    public function testTheSubjectOfAChangeToARecordThatNoLongerExistsIsPlainText(): void
    {
        $id = $this->insertActivity('entity', 'CMS block deleted', 'Block aal-panel-gone');
        $this->saveBlockChange($id, '987654');

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $html = $this->body();

        self::assertStringContainsString('Block aal-panel-gone', $html);
        self::assertStringNotContainsString('admin-action-diff__record', $html);
        self::assertStringNotContainsString('cms/block/edit', $html);
    }

    #[DbIsolation(true)]
    public function testTheSubjectIsPlainTextForAnAdminWhoMayNotOpenTheRecordsPage(): void
    {
        $blockId = $this->savedBlock('aal-panel-denied');
        $id = $this->insertActivity('entity', 'CMS block saved', 'Block aal-panel-denied');
        $this->saveBlockChange($id, $blockId);

        $aclBuilder = $this->service(AclBuilder::class);
        $admin = $this->_auth->getUser();
        self::assertInstanceOf(User::class, $admin);
        $aclBuilder->getAcl()->deny($admin->getRoles(), 'Magento_Cms::block');
        $this->resetRequest();
        // Building admin links switches the shared URL model's secret key back on; the test's request URIs carry none
        $this->service(BackendUrl::class)->turnOffSecretKey();

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $html = $this->body();

        self::assertStringContainsString('Block aal-panel-denied', $html);
        self::assertStringNotContainsString('admin-action-diff__record', $html);
        self::assertStringNotContainsString('cms/block/edit', $html);
    }

    #[DbIsolation(true)]
    public function testALongChangedValueCarriesItsRawValuesForTheSplitDiff(): void
    {
        $before = "<p>Opening</p>\r\n<p>Second</p>\r\n<p>Third</p>\r\n</script><b>bold</b>\r\n<p>End</p>";
        $after = "<p>Opening</p>\n<p>Second line</p>\n<p>Third</p>\n</script><b>bold</b>\n<p>End</p>";
        $id = $this->insertActivity('entity', 'CMS block saved', 'Block aal-panel-split');
        $this->saveBlockChange($id, '987655', [
            $this->field('content', $before, $after, false),
            $this->field('title', 'Before', 'After', false),
            $this->field('custom_theme', str_repeat('secret ', 40), str_repeat('hidden ', 40), true),
        ]);

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $html = $this->body();

        self::assertMatchesRegularExpression('~data-field="content"\s+data-changed="1"\s+data-split="1"~', $html);
        self::assertMatchesRegularExpression('~data-field="title"\s+data-changed="1"\s+class=~', $html);
        self::assertMatchesRegularExpression('~data-field="custom_theme"\s+data-changed="1"\s+class=~', $html);
        self::assertSame(1, substr_count($html, 'data-split="1"'), 'Only the long changed value that is not masked');
        self::assertSame(1, substr_count($html, 'data-role="split-values"'));

        $json = preg_match('~<script type="application/json" data-role="split-values">(.*?)</script>~s', $html, $block)
            === 1 ? $block[1] : '';
        self::assertNotSame('', $json, 'The row carries its values as JSON');
        self::assertStringNotContainsString('</script>', $json, 'A value cannot end the script element');
        self::assertSame(['before' => $before, 'after' => $after], json_decode($json, true));

        self::assertMatchesRegularExpression(
            '~<link[^>]+href="[^"]*/Hryvinskyi_ActivityLog/css/split-diff\.css"~',
            $html
        );
        self::assertSame('Show %1 unchanged lines', $this->attribute($html, 'data-fold-label'));
        self::assertSame('No line', $this->attribute($html, 'data-filler-label'));
    }

    #[DbIsolation(true)]
    public function testALongChangedJsonListIsNotDrawnAsASplitDiff(): void
    {
        $before = json_encode(array_map(static fn (int $n): string => 'option-value-' . $n, range(1, 12)));
        $after = json_encode(array_map(static fn (int $n): string => 'option-value-' . $n, range(2, 13)));
        self::assertIsString($before);
        self::assertIsString($after);
        self::assertGreaterThan(160, strlen($before), 'Long enough to count as long text');
        $id = $this->insertActivity('entity', 'CMS block saved', 'Block aal-panel-json');
        $this->saveBlockChange($id, '987656', [$this->field('options', $before, $after, false)]);

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $html = $this->body();

        self::assertMatchesRegularExpression('~data-field="options"\s+data-changed="1"\s+class=~', $html);
        self::assertStringNotContainsString('data-split="1"', $html, 'A JSON list keeps its before and after cells');
        self::assertStringNotContainsString('data-role="split-values"', $html);
    }

    /**
     * A value that is not valid UTF-8 cannot be stored (the database replaces the byte), so the store is replaced for
     * this one page by one that hands such a value over, as a value read some other way would arrive
     *
     * @return void
     */
    #[DbIsolation(true)]
    public function testALongChangedValueThatIsNotValidUtf8KeepsItsCellsAndThePageRenders(): void
    {
        $id = $this->insertActivity('entity', 'CMS block saved', 'Block aal-panel-bytes');
        $store = $this->createMock(EntityChangeStore::class);
        $store->method('find')->willReturn([
            'entityType' => 'cms_block',
            'entityId' => '987657',
            'operation' => ChangeOperation::Update,
            'mode' => DiffMode::Changed,
            'unchangedCount' => 0,
            'fields' => [
                [
                    'scopeType' => 'global',
                    'scopeId' => 0,
                    'scopeLabel' => 'All Store Views',
                    'field' => 'content',
                    'before' => str_repeat("line\n", 5) . "broken \xff byte",
                    'after' => str_repeat("line\n", 5) . 'fixed byte',
                    'changed' => true,
                    'masked' => false,
                    'automatic' => false,
                ],
            ],
        ]);
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);
        $objectManager->removeSharedInstance(EntityChangePanel::class);
        $objectManager->addSharedInstance($store, EntityChangeStore::class);

        $this->dispatch('backend/activitylog/activity/view/id/' . $id);
        $html = $this->body();

        self::assertStringContainsString('Entity change', $html);
        self::assertMatchesRegularExpression('~data-field="content"\s+data-changed="1"\s+class=~', $html);
        self::assertStringNotContainsString('data-split="1"', $html, 'The row keeps the two cells the server drew');
        self::assertStringNotContainsString('data-role="split-values"', $html);
        self::assertStringContainsString('fixed byte', $html);
    }

    /**
     * The decoded value of the first attribute of that name on the page
     *
     * @param string $html
     * @param string $name
     * @return string
     */
    private function attribute(string $html, string $name): string
    {
        $value = preg_match('~\s' . preg_quote($name, '~') . '="([^"]*)"~', $html, $match) === 1 ? $match[1] : null;
        self::assertNotNull($value, 'The page carries the attribute ' . $name);

        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A changed field as the store takes it
     *
     * @param string $name
     * @param string $before
     * @param string $after
     * @param bool $masked
     * @return array{field: string, before: string, after: string, changed: true, masked: bool, automatic: false}
     */
    private function field(string $name, string $before, string $after, bool $masked): array
    {
        return [
            'field' => $name,
            'before' => $before,
            'after' => $after,
            'changed' => true,
            'masked' => $masked,
            'automatic' => false,
        ];
    }

    /**
     * Leaves the activity without a subject, as one recorded for something with no label
     *
     * @param int $activityId
     * @return void
     */
    private function clearSubject(int $activityId): void
    {
        $resource = $this->service(ResourceConnection::class);
        $resource->getConnection()->update(
            $resource->getTableName('hryvinskyi_activity_log'),
            ['subject_type' => null, 'subject_label' => null],
            ['entity_id = ?' => $activityId]
        );
    }

    /**
     * A CMS block that exists, so its edit page can be opened
     *
     * @param string $identifier
     * @return string
     */
    private function savedBlock(string $identifier): string
    {
        $block = $this->service(BlockFactory::class)->create();
        $block->setIdentifier($identifier)->setTitle('Panel link')->setContent('Body')->setIsActive(true);
        $block->setData('store_id', [0]);
        $saved = $this->service(BlockRepositoryInterface::class)->save($block);
        self::assertInstanceOf(Block::class, $saved);

        return (string) $saved->getId();
    }

    /**
     * Stores a change of a CMS block for the activity: its title by default
     *
     * @param int $activityId
     * @param string $blockId
     * @param list<array{
     *     field: string,
     *     before: string,
     *     after: string,
     *     changed: bool,
     *     masked: bool,
     *     automatic: bool
     * }>|null $fields
     * @return void
     */
    private function saveBlockChange(int $activityId, string $blockId, ?array $fields = null): void
    {
        $scope = $this->service(EntityScopeInterfaceFactory::class)->create([
            'type' => 'global',
            'id' => 0,
            'label' => 'All Store Views',
        ]);
        $this->service(EntityChangeStore::class)->save(
            $activityId,
            'cms_block',
            $blockId,
            ChangeOperation::Update,
            DiffMode::Changed,
            1,
            $scope,
            $fields ?? [$this->field('title', 'Before', 'After', false)]
        );
    }

    /**
     * @param string $channel
     * @param string $action
     * @param string $subject
     * @return int
     */
    private function insertActivity(string $channel, string $action, string $subject): int
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $table = $resource->getTableName('hryvinskyi_activity_log');
        $correlationId = bin2hex(random_bytes(8));
        $connection->insert($table, [
            'correlation_id' => $correlationId,
            'channel' => $channel,
            'action' => $action,
            'subject_type' => 'product',
            'subject_label' => $subject,
            'actor_type' => 'admin',
            'actor_name' => 'jane.doe',
            'outcome' => 'success',
            'occurred_at_ms' => 1792500000000,
            'created_at' => '2026-10-20 12:00:00',
        ]);
        $id = $connection->fetchOne(
            $connection->select()->from($table, ['entity_id'])->where('correlation_id = ?', $correlationId)
        );
        self::assertIsNumeric($id);

        return (int) $id;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @return string
     */
    private function body(): string
    {
        $response = $this->getResponse();
        self::assertInstanceOf(\Magento\TestFramework\Response::class, $response);

        return (string) $response->getBody();
    }
}
