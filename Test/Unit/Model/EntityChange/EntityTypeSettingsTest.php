<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\Config;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityTypeSettings;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class EntityTypeSettingsTest extends TestCase
{
    public function testEachEntityKeepsItsOwnRowAndTheRestTheDefaults(): void
    {
        $settings = $this->settings('90', json_encode([
            '_1' => ['entity_type' => 'product', 'diff_mode' => 'snapshot', 'retention_days' => '160'],
            '_2' => ['entity_type' => 'config_value', 'diff_mode' => 'off', 'retention_days' => '30'],
            '_3' => ['entity_type' => 'cms_block', 'diff_mode' => 'bogus', 'retention_days' => '-4'],
        ]) ?: '');

        self::assertSame(DiffMode::Snapshot, $settings->modeOf('product'));
        self::assertSame(160, $settings->retentionDaysOf('product'));
        self::assertSame(DiffMode::Off, $settings->modeOf('config_value'));
        self::assertSame(30, $settings->retentionDaysOf('config_value'));
        self::assertSame(DiffMode::Changed, $settings->modeOf('cms_block'), 'An unknown mode records changed fields.');
        self::assertSame(90, $settings->retentionDaysOf('cms_block'), 'No valid number of days keeps the default.');
        self::assertSame(DiffMode::Changed, $settings->modeOf('customer'));
        self::assertSame(90, $settings->retentionDaysOf('customer'));
        self::assertSame(160, $settings->longestRetentionDays());
    }

    public function testUnreadableSettingsFallBackToTheDefaults(): void
    {
        $settings = $this->settings('', '{not json');

        self::assertSame(DiffMode::Changed, $settings->modeOf('product'));
        self::assertSame(90, $settings->retentionDaysOf('product'));
    }

    /**
     * @param string $defaultDays
     * @param string $rows
     * @return EntityTypeSettings
     */
    private function settings(string $defaultDays, string $rows): EntityTypeSettings
    {
        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            [Config::XML_PATH_ENTITY_RETENTION, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null, $defaultDays],
            [Config::XML_PATH_ENTITY_TYPES, ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null, $rows],
        ]);

        $entities = [];
        foreach (['product', 'config_value', 'cms_block', 'customer'] as $code) {
            $entity = $this->createStub(TrackedEntityInterface::class);
            $entity->method('getCode')->willReturn($code);
            $entities[$code] = $entity;
        }

        return new EntityTypeSettings(new Config($scopeConfig, new Json()), new TrackedEntityPool($entities));
    }
}
