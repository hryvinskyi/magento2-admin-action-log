<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource\FieldSourceInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Scope\ScopeResolverInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\EntityStorageInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntity;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TrackedEntityTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: string}>
     */
    public static function names(): array
    {
        return [
            'by id, the default' => [[], ['id' => 1245], 'Product #1245'],
            'one naming field' => [
                ['labelField' => 'identifier', 'labelPattern' => '%1$s %2$s'],
                ['id' => 7, 'identifier' => 'footer-links'],
                'Product footer-links',
            ],
            'one naming field, empty, falls back to the id' => [
                ['labelField' => 'identifier', 'labelPattern' => '%1$s %2$s'],
                ['id' => 7, 'identifier' => ''],
                'Product 7',
            ],
            'several naming fields' => [
                ['labelFields' => ['sku', 'name'], 'labelPattern' => '%1$s %2$s · %3$s'],
                ['id' => 1245, 'sku' => '24-MB01', 'name' => 'Joust Duffle Bag'],
                'Product 24-MB01 · Joust Duffle Bag',
            ],
            'the id after the naming fields' => [
                ['labelFields' => ['name'], 'labelPattern' => '%1$s %2$s (#%3$s)'],
                ['id' => 12, 'name' => 'Gear'],
                'Product Gear (#12)',
            ],
            'a missing naming field names the entity by id' => [
                ['labelFields' => ['sku', 'name'], 'labelPattern' => '%1$s %2$s · %3$s'],
                ['id' => 1245, 'sku' => '24-MB01'],
                'Product #1245',
            ],
            'no id and no naming value' => [
                ['labelFields' => ['name'], 'labelPattern' => '%1$s %2$s (#%3$s)'],
                [],
                'Product',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $declaration
     * @param array<string, mixed> $data
     * @param string $expected
     */
    #[DataProvider('names')]
    public function testDescribeNamesTheEntity(array $declaration, array $data, string $expected): void
    {
        $entity = new TrackedEntity(
            $this->createStub(EntityStorageInterface::class),
            $this->createStub(ScopeResolverInterface::class),
            $this->createStub(FieldSourceInterface::class),
            $this->createStub(SanitizerInterface::class),
            'product',
            'Product',
            AbstractModel::class,
            labelField: is_string($declaration['labelField'] ?? null) ? $declaration['labelField'] : '',
            labelPattern: is_string($declaration['labelPattern'] ?? null) ? $declaration['labelPattern'] : '%1$s #%2$s',
            labelFields: array_values(array_filter(
                is_array($declaration['labelFields'] ?? null) ? $declaration['labelFields'] : [],
                'is_string'
            ))
        );

        $model = $this->getMockBuilder(AbstractModel::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $model->setData($data);

        self::assertSame($expected, $entity->describe($model));
    }

    public function testTheStateBeforeIsTheOriginalDataUnlessTheStoredStateIsAskedFor(): void
    {
        $storage = $this->createStub(EntityStorageInterface::class);
        $storage->method('load')->willReturn(['qty' => '100.0000']);
        $model = $this->getMockBuilder(AbstractModel::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $model->setIdFieldName('item_id');
        $model->setData(['item_id' => 7, 'qty' => 95]);
        $model->setOrigData('qty', '1.0000');

        self::assertSame(['qty' => '1.0000'], $this->recordReadFrom($storage, false)->stateBefore($model));
        self::assertSame(
            ['qty' => '100.0000'],
            $this->recordReadFrom($storage, true)->stateBefore($model),
            'Original data a request kept can be out of date; the stored row is not'
        );
    }

    /**
     * @param EntityStorageInterface $storage
     * @param bool $readsStoredState
     * @return TrackedEntity
     */
    private function recordReadFrom(EntityStorageInterface $storage, bool $readsStoredState): TrackedEntity
    {
        return new TrackedEntity(
            $storage,
            $this->createStub(ScopeResolverInterface::class),
            $this->createStub(FieldSourceInterface::class),
            $this->createStub(SanitizerInterface::class),
            'stock_item',
            'Stock Item',
            AbstractModel::class,
            readsStoredState: $readsStoredState
        );
    }

    public function testAModelWithoutAnIdIsNewWhateverOriginalDataItsSaveFilledIn(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects(self::never())->method('load');
        $model = $this->getMockBuilder(AbstractModel::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $model->setData(['sku' => '24-MB01']);
        $model->setOrigData('website_ids', []);

        self::assertNull($this->recordReadFrom($storage, false)->stateBefore($model));
    }
}
