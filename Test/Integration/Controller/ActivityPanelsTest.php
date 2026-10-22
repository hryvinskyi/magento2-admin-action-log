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
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;

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
