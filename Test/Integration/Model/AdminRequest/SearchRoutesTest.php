<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model\AdminRequest;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\RequestPolicy;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The activity log's own search calls are never journaled, even while reading requests are
 */
class SearchRoutesTest extends TestCase
{
    #[Config('hryvinskyi_admin_action_log/admin/log_get_requests', '1')]
    public function testSearchingTheLogIsNotRecorded(): void
    {
        $policy = Bootstrap::getObjectManager()->get(RequestPolicy::class);
        self::assertInstanceOf(RequestPolicy::class, $policy);

        self::assertFalse($policy->shouldRecord('hryvinskyi_activitylog/activity/search', 'GET'));
        self::assertFalse($policy->shouldRecord('hryvinskyi_activitylog/activity/suggest', 'GET'));
        self::assertFalse($policy->shouldRecord('hryvinskyi_activitylog/activity/savePreference', 'POST'));
        self::assertTrue(
            $policy->shouldRecord('hryvinskyi_activitylog/activity/index', 'GET'),
            'Opening the log page is still an admin request'
        );
    }
}
