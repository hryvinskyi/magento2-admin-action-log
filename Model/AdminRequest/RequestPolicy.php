<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

use Hryvinskyi\AdminActionLog\Model\Config;

/**
 * Decides which admin requests are worth a journal entry
 *
 * Requests that change something are recorded; requests that only read are recorded when the settings ask for it.
 * A route matching an ignored pattern ("mui/*") is never recorded: those are the screens' own background calls.
 * Routes and patterns are compared without regard to case, however the pattern was typed.
 */
class RequestPolicy
{
    private const READ_METHODS = ['GET'];
    private const NEVER_RECORDED_METHODS = ['HEAD', 'OPTIONS'];

    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Whether a request to the route with the method is recorded
     *
     * @param string $route As route/controller/action
     * @param string $method HTTP method
     * @return bool
     */
    public function shouldRecord(string $route, string $method): bool
    {
        if (!$this->config->isAdminEnabled()) {
            return false;
        }

        $method = strtoupper($method);
        if (in_array($method, self::NEVER_RECORDED_METHODS, true)) {
            return false;
        }

        if (in_array($method, self::READ_METHODS, true) && !$this->config->recordsReadRequests()) {
            return false;
        }

        $route = strtolower($route);
        foreach ($this->config->getIgnoredRoutes() as $pattern) {
            if (fnmatch(strtolower($pattern), $route)) {
                return false;
            }
        }

        return true;
    }
}
