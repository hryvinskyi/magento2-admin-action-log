<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\SecretFieldsInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityReadAheadInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\Fake\RecordedReadAhead;
use Magento\Catalog\Model\Product;
use Magento\CatalogInventory\Model\Stock\Item as StockItem;
use Magento\Framework\Model\AbstractModel;
use Magento\Store\Model\Website;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Entities and the parts of them, kept apart: a part is matched as a part and is never a kind of entity of its own;
 * one part per entity and model class; the one rule telling a secret field; and the read-aheads of an entity kind
 */
class TrackedEntityPoolTest extends TestCase
{
    public function testAPartIsMatchedAsAPartAndNeverListedAsAnEntity(): void
    {
        $product = $this->entity('product', Product::class);
        $stock = $this->part('product', StockItem::class);
        $pool = new TrackedEntityPool(
            $this->createStub(SanitizerInterface::class),
            $this->createStub(LoggerInterface::class),
            ['product' => $product],
            ['product_stock_item' => $stock]
        );

        $stockItem = $this->createStub(StockItem::class);
        self::assertSame($stock, $pool->matchPart($stockItem));
        self::assertNull($pool->match($stockItem), 'A stock item is not an entity of its own');
        self::assertSame($product, $pool->match($this->createStub(Product::class)));
        self::assertNull($pool->matchPart($this->createStub(Product::class)));
        self::assertSame([$product], $pool->all());
        self::assertSame($product, $pool->get('product'), 'The part does not replace its entity by code');
    }

    public function testAPartOfAnEntityNotTrackedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"product_stock_item" belongs to "product"');

        new TrackedEntityPool(
            $this->createStub(SanitizerInterface::class),
            $this->createStub(LoggerInterface::class),
            [],
            ['product_stock_item' => $this->part('product', StockItem::class)]
        );
    }

    public function testASecondPartOfAnEntityKeptByTheSameClassIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"product_stock_item" and "stock_from_elsewhere" both keep');

        new TrackedEntityPool(
            $this->createStub(SanitizerInterface::class),
            $this->createStub(LoggerInterface::class),
            ['product' => $this->entity('product', Product::class)],
            [
                'product_stock_item' => $this->part('product', StockItem::class),
                'stock_from_elsewhere' => $this->part('product', '\\' . StockItem::class),
            ]
        );
    }

    public function testPartsOfOneEntityKeptByDifferentClassesAreKept(): void
    {
        $pool = new TrackedEntityPool(
            $this->createStub(SanitizerInterface::class),
            $this->createStub(LoggerInterface::class),
            ['product' => $this->entity('product', Product::class)],
            [
                'product_stock_item' => $this->part('product', StockItem::class),
                'product_website' => $this->part('product', Website::class),
            ]
        );

        self::assertNotNull($pool->matchPart($this->createStub(Website::class)));
    }

    public function testAFieldIsSecretByItsNameByTheEntitysRuleOrByItsModel(): void
    {
        $sanitizer = $this->createStub(SanitizerInterface::class);
        $sanitizer->method('isSecretField')->willReturnCallback(
            static fn (string $field): bool => $field === 'password'
        );
        $encrypted = $this->createStub(SecretFieldsInterface::class);
        $encrypted->method('isSecretField')->willReturnCallback(
            static fn (string $field): bool => $field === 'carriers/courier/account'
        );
        $config = $this->entity('config_value', AbstractModel::class);
        $config->method('isSecretField')->willReturnCallback(
            static fn (AbstractModel $model, string $field): bool => $field === 'payment/gateway/merchant'
        );
        $pool = new TrackedEntityPool(
            $sanitizer,
            $this->createStub(LoggerInterface::class),
            ['config_value' => $config],
            [],
            ['config_value' => $encrypted]
        );

        self::assertTrue($pool->isSecretField($config, 'password'));
        self::assertTrue($pool->isSecretField($config, 'carriers/courier/account'), 'Told without a model');
        self::assertFalse($pool->isSecretField($config, 'payment/gateway/merchant'), 'Only a model tells this one');
        $model = $this->createStub(AbstractModel::class);
        self::assertTrue($pool->isSecretField($config, 'payment/gateway/merchant', $model));
        self::assertFalse($pool->isSecretField($config, 'web/secure/base_url', $model));
    }

    public function testTheEntitiesAWriteChangedAreAnnouncedToEveryReadAheadOfTheirKindOnly(): void
    {
        $stock = new RecordedReadAhead();
        $prices = new RecordedReadAhead();
        $categories = new RecordedReadAhead();
        $pool = new TrackedEntityPool(
            $this->createStub(SanitizerInterface::class),
            $this->createStub(LoggerInterface::class),
            [
                'product' => $this->entity('product', Product::class),
                'category' => $this->entity('category', AbstractModel::class),
            ],
            [],
            [],
            [
                'product' => ['stock' => $stock, 'prices' => $prices],
                'category' => ['tree' => $categories],
            ]
        );

        $pool->readAhead('product', ['42', '43']);
        $pool->readAhead('product', []);
        $pool->readAhead('customer', ['7']);

        self::assertSame([['42', '43']], $stock->announced, 'Once, and never for an empty write');
        self::assertSame([['42', '43']], $prices->announced);
        self::assertSame([], $categories->announced, 'Another kind of entity is not told');
    }

    public function testAReadAheadThatFailsIsLoggedAndTheNextOneIsStillTold(): void
    {
        $failing = $this->createStub(TrackedEntityReadAheadInterface::class);
        $failing->method('readAhead')->willThrowException(new \RuntimeException('Lost connection to the database'));
        $next = new RecordedReadAhead();
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(
            static function (string|\Stringable $message, array $context) use (&$logged): void {
                $logged[] = [(string) $message, $context['exception'] ?? null];
            }
        );
        $pool = new TrackedEntityPool(
            $this->createStub(SanitizerInterface::class),
            $logger,
            ['product' => $this->entity('product', Product::class)],
            [],
            [],
            ['product' => ['failing' => $failing, 'next' => $next]]
        );

        $pool->readAhead('product', ['42']);

        self::assertSame([['42']], $next->announced, 'The read-ahead after the failed one is still told');
        self::assertCount(1, $logged);
        self::assertSame(
            'The read-ahead "failing" of "product" failed; its entities are read one at a time.',
            $logged[0][0]
        );
        self::assertInstanceOf(\RuntimeException::class, $logged[0][1]);
    }

    public function testAReadAheadOfAnEntityNotTrackedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The read-ahead "stock" belongs to "product"');

        new TrackedEntityPool(
            $this->createStub(SanitizerInterface::class),
            $this->createStub(LoggerInterface::class),
            [],
            [],
            [],
            ['product' => ['stock' => new RecordedReadAhead()]]
        );
    }

    /**
     * @param string $code
     * @param class-string<AbstractModel> $modelClass
     * @return TrackedEntityInterface&Stub
     */
    private function entity(string $code, string $modelClass): TrackedEntityInterface&Stub
    {
        $entity = $this->createStub(TrackedEntityInterface::class);
        $entity->method('getCode')->willReturn($code);
        $entity->method('supports')->willReturnCallback(
            static fn (AbstractModel $model): bool => $model instanceof $modelClass
        );

        return $entity;
    }

    /**
     * @param string $code
     * @param string $modelClass
     * @return TrackedEntityPartInterface
     */
    private function part(string $code, string $modelClass): TrackedEntityPartInterface
    {
        $part = $this->createStub(TrackedEntityPartInterface::class);
        $part->method('getCode')->willReturn($code);
        $part->method('getModelClass')->willReturn($modelClass);
        $part->method('supports')->willReturnCallback(
            static fn (AbstractModel $model): bool => $model instanceof $modelClass
        );

        return $part;
    }
}
