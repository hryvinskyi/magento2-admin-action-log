<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Test\Integration\Controller\Fixture\ActivityLogMenu;
use Magento\TestFramework\Response;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The entries this module puts into the Activity Log menu, as the Activity Log dashboard prints the menu
 *
 * Each test dispatches once: the test application serves a second dispatch of one test from the first one's state.
 */
class ActivityLogMenuTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_ActivityLog::dashboard';

    /**
     * @var string
     */
    protected $uri = 'backend/activitylog/dashboard/index';

    public function testAdminRequestsAndEntityChangesAreLogsOfTheirChannels(): void
    {
        $menu = $this->menu();

        $requests = $menu->entry('Admin requests');
        self::assertSame('Logs', $requests['heading']);
        self::assertStringContainsString('/activitylog/activity/index/', $requests['href']);
        self::assertStringContainsString('channel=admin', $requests['href']);
        self::assertTrue($requests['icon'], 'Admin requests has an icon');

        $changes = $menu->entry('Entity changes');
        self::assertSame('Logs', $changes['heading']);
        self::assertStringContainsString('/activitylog/activity/index/', $changes['href']);
        self::assertStringContainsString('channel=entity', $changes['href']);
        self::assertTrue($changes['icon'], 'Entity changes has an icon');
    }

    public function testTheAdminLoggingSettingsAreInSettings(): void
    {
        $settings = $this->menu()->entry('Admin logging');

        self::assertSame('Settings', $settings['heading']);
        self::assertStringContainsString(
            '/system_config/edit/section/hryvinskyi_admin_action_log/',
            $settings['href']
        );
        self::assertTrue($settings['icon'], 'Admin logging has an icon');
    }

    /**
     * The Activity Log menu of the dashboard, which must have been answered
     *
     * @return ActivityLogMenu
     */
    private function menu(): ActivityLogMenu
    {
        $this->dispatch($this->uri);
        $response = $this->getResponse();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return new ActivityLogMenu((string) $response->getBody());
    }
}
