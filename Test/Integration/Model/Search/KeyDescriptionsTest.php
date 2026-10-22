<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model\Search;

use Hryvinskyi\ActivityLog\Api\Data\Search\SearchKeyGroup;
use Hryvinskyi\ActivityLog\Api\Search\SearchKeyDescriptionInterface;
use Hryvinskyi\ActivityLog\Model\Search\KeyDescriptionPool;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The keys of entity changes, as the real container registers them, say which suggestion group their values join
 * and which of them name fields
 */
class KeyDescriptionsTest extends TestCase
{
    public function testEachKeyOfEntityChangesIsSuggestedInItsGroup(): void
    {
        $byKey = $this->descriptions();
        $expected = [
            'field' => SearchKeyGroup::Fields,
            'config' => SearchKeyGroup::Fields,
            'product' => SearchKeyGroup::Records,
            'category' => SearchKeyGroup::Records,
            'page' => SearchKeyGroup::Records,
            'block' => SearchKeyGroup::Records,
            'customer' => SearchKeyGroup::Records,
            'order' => SearchKeyGroup::Records,
            'rule' => SearchKeyGroup::Records,
            'entity' => SearchKeyGroup::Types,
            'value' => SearchKeyGroup::Other,
            'store' => SearchKeyGroup::Other,
        ];

        foreach ($expected as $key => $group) {
            self::assertArrayHasKey($key, $byKey);
            self::assertSame($group, $byKey[$key]->getGroup(), $key);
        }
    }

    public function testOnlyTheFieldAndSettingKeysNameFields(): void
    {
        $naming = [];
        foreach ($this->descriptions() as $key => $description) {
            if ($description->matchesFieldNames()) {
                $naming[] = $key;
            }
        }
        sort($naming);

        self::assertSame(['config', 'field'], $naming);
    }

    public function testTheRecordKeysAreReachedThroughTheEntityTypeAndItsIdBox(): void
    {
        $byKey = $this->descriptions();

        foreach (['product', 'category', 'config', 'page', 'block', 'customer', 'order', 'rule'] as $key) {
            self::assertFalse($byKey[$key]->isInPanel(), $key . ' is typed into the id box, not a box of its own');
        }
        foreach (['entity', 'field', 'value', 'store'] as $key) {
            self::assertTrue($byKey[$key]->isInPanel(), $key);
        }

        $entity = $byKey['entity'];
        self::assertSame('Entity ID / SKU / config path', $entity->getCompanionLabel());
        self::assertNotSame('', $entity->getCompanionHint());
        $targets = $entity->getOptionTargets();
        self::assertSame('product', $targets['product'] ?? null);
        self::assertSame('config', $targets['config_value'] ?? null);
        self::assertSame('block', $targets['cms_block'] ?? null);
        self::assertSame('page', $targets['cms_page'] ?? null);
        self::assertSame('rule', $targets['cart_price_rule'] ?? null);
        self::assertSame('order', $targets['order_comment'] ?? null);

        $options = array_column($entity->getOptions(), 'value');
        foreach (array_keys($targets) as $code) {
            self::assertContains($code, $options, 'every target belongs to a kind the select offers');
        }
        foreach (array_unique($targets) as $key) {
            self::assertArrayHasKey($key, $byKey, 'every target is a key the search knows');
        }
    }

    /**
     * Every registered description by key
     *
     * @return array<string, SearchKeyDescriptionInterface>
     */
    private function descriptions(): array
    {
        $pool = Bootstrap::getObjectManager()->get(KeyDescriptionPool::class);
        self::assertInstanceOf(KeyDescriptionPool::class, $pool);

        $byKey = [];
        foreach ($pool->all() as $description) {
            $byKey[$description->getKey()] = $description;
        }

        return $byKey;
    }
}
