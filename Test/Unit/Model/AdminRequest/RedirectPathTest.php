<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\AdminRequest;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\RedirectPath;
use PHPUnit\Framework\TestCase;

class RedirectPathTest extends TestCase
{
    public function testKeepsTheAdminPathPeopleRecognise(): void
    {
        $path = new RedirectPath();

        self::assertSame(
            'catalog/product/edit/id/1188',
            $path->fromLocation('https://shop.test/admin/catalog/product/edit/id/1188/key/9f8e7d6c5b4a/', 'admin')
        );
        self::assertSame(
            'admin/system_config/edit/section/web',
            $path->fromLocation(
                'https://shop.test/index.php/backoffice/admin/system_config/edit/section/web/key/ab12/',
                'backoffice'
            )
        );
        self::assertSame('', $path->fromLocation('https://shop.test/admin/', 'admin'));
    }

    public function testKeepsATargetOutsideTheAdminAreaWhole(): void
    {
        self::assertSame(
            'https://shop.test/customer/account/',
            (new RedirectPath())->fromLocation('https://shop.test/customer/account/', 'admin')
        );
    }
}
