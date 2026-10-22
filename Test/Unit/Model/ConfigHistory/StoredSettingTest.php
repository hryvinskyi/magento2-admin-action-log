<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\ConfigHistory;

use Hryvinskyi\AdminActionLog\Model\ConfigHistory\StoredSetting;
use PHPUnit\Framework\TestCase;

/**
 * Which configuration fields store a value, under which config path and in which section
 */
class StoredSettingTest extends TestCase
{
    public function testAFieldIsStoredUnderItsShownPathAndIdOrUnderTheConfigPathItDeclares(): void
    {
        $settings = new StoredSetting(['button' => 'button', 'label' => 'label', 'note' => 'note']);

        self::assertSame(
            ['path' => 'carriers/freeshipping/active', 'section' => 'carriers'],
            $settings->of(['id' => 'active', 'path' => 'carriers/freeshipping', 'type' => 'select'])
        );
        self::assertSame(
            ['path' => 'general/locale/code', 'section' => 'web'],
            $settings->of([
                'id' => 'locale',
                'path' => 'web/default',
                'type' => 'select',
                'config_path' => '/general/locale/code/',
            ]),
            'A field shown in one section may store its value under another path'
        );
        self::assertSame(
            ['path' => 'a/b/c', 'section' => 'a'],
            $settings->of(['id' => 'c', 'path' => 'a/b', 'config_path' => '  ']),
            'A blank config path and a missing type change nothing'
        );
    }

    public function testDisplayOnlyFieldsAndFieldsWithoutAPathStoreNothing(): void
    {
        $settings = new StoredSetting(['button', 'label', 'note']);

        self::assertNull($settings->of(['id' => 'flush', 'path' => 'dev/cache', 'type' => 'button']));
        self::assertNull($settings->of(['id' => 'version', 'path' => 'dev/info', 'type' => 'label']));
        self::assertNull($settings->of(['id' => 'hint', 'path' => 'dev/info', 'type' => 'note']));
        self::assertNull($settings->of(['path' => 'dev/info', 'type' => 'text']));
        self::assertNull($settings->of(['id' => 'x', 'path' => ' / ', 'type' => 'text']));
        self::assertNull($settings->of(['id' => ['x'], 'path' => 'dev/info']));
        self::assertNull($settings->of([]));
    }
}
