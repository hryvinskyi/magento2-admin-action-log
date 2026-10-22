<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Data;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Model\Data\EntityChange;
use Hryvinskyi\AdminActionLog\Model\Data\EntityScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A change to record is valid by construction: every with…() returns a valid copy and leaves the original as it was
 */
class EntityChangeTest extends TestCase
{
    public function testAChangeFromTheFactoryIsBlank(): void
    {
        $change = $this->blank();

        self::assertSame('', $change->getEntityCode());
        self::assertSame('', $change->getEntityId());
        self::assertSame(EntityChangeInterface::OPERATION_UPDATE, $change->getOperation());
        self::assertSame(EntityScopeInterface::TYPE_GLOBAL, $change->getScope()->getType());
        self::assertSame([], $change->getFields());
        self::assertSame([], $change->getBefore());
        self::assertSame([], $change->getAfter());
    }

    public function testEachWithReturnsACopyAndLeavesTheOriginal(): void
    {
        $blank = $this->blank();
        $change = $blank->withEntity('product', ' 42 ')
            ->withOperation(EntityChangeInterface::OPERATION_CREATE)
            ->withScope(EntityScopeInterface::TYPE_GLOBAL, 0, 'Default Source')
            ->withField('quantity', '100.0000', '95.0000');

        self::assertSame(['product', '42'], [$change->getEntityCode(), $change->getEntityId()]);
        self::assertSame(EntityChangeInterface::OPERATION_CREATE, $change->getOperation());
        self::assertSame('Default Source', $change->getScope()->getLabel());
        self::assertSame(['quantity'], $change->getFields());
        self::assertSame(['quantity' => '100.0000'], $change->getBefore());
        self::assertSame(['quantity' => '95.0000'], $change->getAfter());

        self::assertSame('', $blank->getEntityCode());
        self::assertSame('Global', $blank->getScope()->getLabel());
        self::assertSame([], $blank->getFields());
    }

    public function testAFieldGivenAgainKeepsItsPlaceAndTakesTheNewValues(): void
    {
        $change = $this->blank()
            ->withField('quantity', '1', '2')
            ->withField('source_status', null, '1')
            ->withField(' quantity ', '3', null);

        self::assertSame(['quantity', 'source_status'], $change->getFields());
        self::assertSame(['quantity' => '3', 'source_status' => null], $change->getBefore());
        self::assertSame(['quantity' => null, 'source_status' => '1'], $change->getAfter());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedEntities(): array
    {
        return [
            'upper case code' => ['Product', '42'],
            'code with a dash' => ['product-item', '42'],
            'empty code' => ['', '42'],
            'blank id' => ['product', '   '],
            'id too long' => ['product', str_repeat('1', 65)],
        ];
    }

    #[DataProvider('malformedEntities')]
    public function testAMalformedEntityIsRefused(string $code, string $id): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->blank()->withEntity($code, $id);
    }

    public function testAnUnknownOperationIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->blank()->withOperation('rename');
    }

    public function testAScopeIsHeldToTheRulesOfAScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->blank()->withScope('source', 0, 'Default Source');
    }

    public function testABlankOrTooLongFieldNameIsRefused(): void
    {
        $refused = 0;
        foreach (['  ', str_repeat('f', 256)] as $field) {
            try {
                $this->blank()->withField($field, null, '1');
            } catch (\InvalidArgumentException) {
                $refused++;
            }
        }

        self::assertSame(2, $refused);
    }

    public function testAChangeCarriesABoundedNumberOfFields(): void
    {
        $change = $this->blank();
        for ($field = 0; $field < EntityChangeInterface::MAX_FIELDS; $field++) {
            $change = $change->withField('f' . $field, null, '1');
        }

        $change = $change->withField('f0', '1', '2');
        self::assertCount(EntityChangeInterface::MAX_FIELDS, $change->getFields(), 'A field given again is no new one');

        $this->expectException(\InvalidArgumentException::class);
        $change->withField('one_too_many', null, '1');
    }

    private function blank(): EntityChangeInterface
    {
        return new EntityChange(new EntityScope());
    }
}
