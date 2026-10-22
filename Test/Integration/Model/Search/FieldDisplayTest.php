<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model\Search;

use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplayInterface;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Fields and values of recorded changes as the admin sees them, through the displays the container builds over the
 * real configuration screens and attributes
 *
 * The configuration screens are read from the admin area's system.xml, hence the admin area.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class FieldDisplayTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (['config_value', 'product', 'category', 'customer'] as $entityType) {
            $display = $this->displays()->forEntity($entityType);
            if ($display instanceof ResetAfterRequestInterface) {
                $display->_resetState();
            }
        }
    }

    public function testNamesASettingByItsGroupAndFieldAndItsValuesByTheirOptions(): void
    {
        $settings = $this->display('config_value');

        self::assertSame(
            [
                'hryvinskyi_admin_action_log/admin/enabled' => 'Admin Requests › Record Admin Requests',
                'hryvinskyi_admin_action_log/entity/actor_types' => 'Entity Changes › Record Changes Made By',
            ],
            $settings->labelsOf([
                'hryvinskyi_admin_action_log/admin/enabled',
                'hryvinskyi_admin_action_log/entity/actor_types',
                'aalfixture/no_such/path',
            ])
        );
        self::assertSame(
            [['1' => 'Yes', '0' => 'No'], [], ['admin,cron,retired' => 'Admin users, Cron jobs, retired']],
            [
                $settings->valuesOf('hryvinskyi_admin_action_log/admin/enabled', ['1', '0']),
                $settings->valuesOf('hryvinskyi_admin_action_log/admin/retention_days', ['30']),
                $settings->valuesOf('hryvinskyi_admin_action_log/entity/actor_types', ['admin,cron,retired']),
            ],
            'A text setting is shown as stored; a multiselect is named option by option'
        );
    }

    public function testNamesProductAttributesAndTheirOptionsByTheirAdminLabels(): void
    {
        $products = $this->display('product');

        self::assertSame(
            ['status' => 'Enable Product', 'visibility' => 'Visibility', 'price' => 'Price'],
            $products->labelsOf(['status', 'visibility', 'price', 'aalfixture_no_such_attribute'])
        );
        self::assertSame(
            [['1' => 'Enabled', '2' => 'Disabled'], ['4' => 'Catalog, Search'], []],
            [
                $products->valuesOf('status', ['1', '2', '9']),
                $products->valuesOf('visibility', ['4']),
                $products->valuesOf('aalfixture_no_such_attribute', ['1']),
            ]
        );
        self::assertSame(
            [['34.000000' => '34.00', '0.125000' => '0.125', '7' => '7.00'], ['-1.500000' => '-1.50']],
            [
                $products->valuesOf('price', ['34.000000', '0.125000', '7', 'n/a']),
                $products->valuesOf('weight', ['-1.500000']),
            ],
            'A decimal is written as the admin\'s forms write it, anything else as stored'
        );
    }

    public function testEachKindOfRecordNamesItsOwnAttributes(): void
    {
        self::assertSame(['name' => 'Product Name'], $this->display('product')->labelsOf(['name']));
        self::assertSame(['name' => 'Name'], $this->display('category')->labelsOf(['name']));
        self::assertSame(['1' => 'Yes'], $this->display('category')->valuesOf('is_anchor', ['1']));
        self::assertSame(['1' => 'General'], $this->display('customer')->valuesOf('group_id', ['1']));
        self::assertNull($this->displays()->forEntity('order'), 'A kind without a display is shown as stored');
    }

    #[DataFixture('Magento/Catalog/_files/multiselect_attribute.php')]
    public function testNamesEachOptionOfAMultiselect(): void
    {
        $ids = [];
        foreach (self::service(ProductAttributeRepositoryInterface::class)->get('multiselect_attribute')->getOptions()
            ?? [] as $option) {
            $ids[(string) $option->getLabel()] = (string) $option->getValue();
        }

        $stored = implode(',', [$ids['Option 1'] ?? '', $ids['Option 3'] ?? '', '999999']);
        self::assertSame(
            [$stored => 'Option 1, Option 3, 999999'],
            $this->display('product')->valuesOf('multiselect_attribute', [$stored]),
            'An option that no longer exists is shown as stored'
        );
    }

    /**
     * The display of a kind of record, which must exist
     *
     * @param string $entityType
     * @return FieldDisplayInterface
     */
    private function display(string $entityType): FieldDisplayInterface
    {
        $display = $this->displays()->forEntity($entityType);
        self::assertInstanceOf(FieldDisplayInterface::class, $display);

        return $display;
    }

    /**
     * @return FieldDisplays
     */
    private function displays(): FieldDisplays
    {
        return self::service(FieldDisplays::class);
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
}
