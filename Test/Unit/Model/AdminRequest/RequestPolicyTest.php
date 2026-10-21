<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\AdminRequest;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\RequestPolicy;
use Hryvinskyi\AdminActionLog\Model\Config;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class RequestPolicyTest extends TestCase
{
    public function testRecordsChangesButNotReadsOrTheScreensOwnBackgroundCalls(): void
    {
        $policy = $this->policy(['enabled' => '1', 'get' => '0', 'ignored' => 'mui/*, */*/validate*']);

        self::assertTrue($policy->shouldRecord('catalog/product/save', 'POST'));
        self::assertTrue($policy->shouldRecord('catalog/product/massDelete', 'post'));
        self::assertFalse($policy->shouldRecord('catalog/product/edit', 'GET'));
        self::assertFalse($policy->shouldRecord('mui/index/render', 'POST'));
        self::assertFalse($policy->shouldRecord('catalog/product/validate', 'POST'));
        self::assertFalse(
            $policy->shouldRecord('Catalog/Product_Attribute/ValidateCode', 'POST'),
            'Patterns ignore case.'
        );
    }

    public function testRecordsPageViewsWhenAskedButNeverHeadOrOptions(): void
    {
        $policy = $this->policy(['enabled' => '1', 'get' => '1', 'ignored' => '']);

        self::assertTrue($policy->shouldRecord('sales/order/view', 'GET'));
        self::assertFalse($policy->shouldRecord('sales/order/view', 'HEAD'));
        self::assertFalse($policy->shouldRecord('sales/order/view', 'OPTIONS'));
    }

    public function testRecordsNothingWhenSwitchedOff(): void
    {
        $policy = $this->policy(['enabled' => '0', 'get' => '1', 'ignored' => '']);

        self::assertFalse($policy->shouldRecord('a/b/c', 'POST'));
    }

    /**
     * @param array{enabled:string,get:string,ignored:string} $settings
     * @return RequestPolicy
     */
    private function policy(array $settings): RequestPolicy
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $default = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $scopeConfig->method('isSetFlag')->willReturnMap([
            [Config::XML_PATH_ADMIN_ENABLED, $default, null, $settings['enabled'] === '1'],
            [Config::XML_PATH_ADMIN_LOG_GET, $default, null, $settings['get'] === '1'],
        ]);
        $scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_ADMIN_IGNORED_ROUTES, $default, null, $settings['ignored']],
        ]);

        return new RequestPolicy(new Config($scopeConfig, new Json()));
    }
}
