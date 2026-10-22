<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange\Inherited;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Inherited\ConfigInheritedState;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use PHPUnit\Framework\TestCase;

class ConfigInheritedStateTest extends TestCase
{
    public function testASettingInheritsWhatItsScopeReadsBeforeTheSave(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with('web/cookie/path', 'websites', 2)
            ->willReturn('/');

        self::assertSame(
            ['path' => 'web/cookie/path', 'value' => '/'],
            (new ConfigInheritedState($scopeConfig))->stateOf(
                $this->value(['path' => 'web/cookie/path', 'value' => '/shop', 'scope' => 'websites', 'scope_id' => 2])
            )
        );
    }

    public function testTheDefaultScopeInheritsTheModulesDefault(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())->method('getValue')->with('web/cookie/path', 'default', null)
            ->willReturn('/');

        self::assertSame(
            ['path' => 'web/cookie/path', 'value' => '/'],
            (new ConfigInheritedState($scopeConfig))->stateOf($this->value(['path' => 'web/cookie/path']))
        );
    }

    public function testASettingWithoutAnyValueInheritsNoValue(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);

        self::assertSame(
            ['path' => 'custom/new/flag', 'value' => null],
            (new ConfigInheritedState($scopeConfig))->stateOf($this->value(['path' => 'custom/new/flag']))
        );
    }

    public function testAPathNamingAGroupOrNothingInheritsNothing(): void
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(['lifetime' => '3600']);
        $state = new ConfigInheritedState($scopeConfig);

        self::assertNull($state->stateOf($this->value(['path' => 'web/cookie'])));
        self::assertNull($state->stateOf($this->value([])));
    }

    /**
     * A configuration value model holding this data
     *
     * @param array<string, mixed> $data
     * @return Value
     */
    private function value(array $data): Value
    {
        $value = $this->getMockBuilder(Value::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $value->setData($data);

        return $value;
    }
}
