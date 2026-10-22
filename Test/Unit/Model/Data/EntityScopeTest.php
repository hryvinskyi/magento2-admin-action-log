<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Data;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Model\Data\EntityScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A scope is valid however it is made: by its constructor, or as a copy with other values
 */
class EntityScopeTest extends TestCase
{
    public function testAScopeMadeWithoutValuesIsTheGlobalOne(): void
    {
        $scope = new EntityScope();

        self::assertSame([EntityScopeInterface::TYPE_GLOBAL, 0, 'Global'], [
            $scope->getType(),
            $scope->getId(),
            $scope->getLabel(),
        ]);
    }

    public function testACopyWithOtherValuesLeavesTheOriginal(): void
    {
        $global = new EntityScope();
        $store = $global->withValues(EntityScopeInterface::TYPE_STORE, 1, 'Default Store View');

        self::assertSame([EntityScopeInterface::TYPE_STORE, 1, 'Default Store View'], [
            $store->getType(),
            $store->getId(),
            $store->getLabel(),
        ]);
        self::assertSame('Global', $global->getLabel());
    }

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function brokenScopes(): array
    {
        return [
            'unknown type' => ['source', 0, 'Default Source'],
            'negative id' => [EntityScopeInterface::TYPE_STORE, -1, 'Default Store View'],
            'blank label' => [EntityScopeInterface::TYPE_GLOBAL, 0, '  '],
            'label too long' => [EntityScopeInterface::TYPE_GLOBAL, 0, str_repeat('a', 256)],
        ];
    }

    #[DataProvider('brokenScopes')]
    public function testACopyBreakingTheRulesIsRefused(string $type, int $id, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new EntityScope())->withValues($type, $id, $label);
    }

    #[DataProvider('brokenScopes')]
    public function testAScopeBreakingTheRulesIsNeverMade(string $type, int $id, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EntityScope($type, $id, $label);
    }
}
