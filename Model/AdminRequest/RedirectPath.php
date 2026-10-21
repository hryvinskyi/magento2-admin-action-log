<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

/**
 * Shortens a redirect target to the admin path people recognise: "catalog/product/edit/id/1188"
 *
 * The host, the entry script, the admin area's front name and the secret URL key are dropped. A target outside the
 * admin area is kept whole.
 */
class RedirectPath
{
    private const KEY_SEGMENT = '#/key/[0-9A-Za-z]+(?=/|$)#';
    private const PATH_PATTERN = '#^(?:[a-z][a-z0-9+.-]*://[^/?\#]*)?([^?\#]*)#i';
    private const MAX_LENGTH = 1024;

    /**
     * The admin path of a redirect target
     *
     * @param string $location Value of the Location header
     * @param string $areaFrontName The admin area's front name, e.g. "admin"
     * @return string
     */
    public function fromLocation(string $location, string $areaFrontName): string
    {
        $location = trim($location);
        if (preg_match(self::PATH_PATTERN, $location, $match) !== 1) {
            return mb_substr($location, 0, self::MAX_LENGTH);
        }

        $path = trim($match[1], '/');
        if (str_starts_with($path, 'index.php/') || $path === 'index.php') {
            $path = ltrim(substr($path, strlen('index.php')), '/');
        }

        $prefix = trim($areaFrontName, '/');
        if ($prefix === '' || ($path !== $prefix && !str_starts_with($path, $prefix . '/'))) {
            return mb_substr($location, 0, self::MAX_LENGTH);
        }

        $path = (string) preg_replace(self::KEY_SEGMENT, '', '/' . substr($path, strlen($prefix)));

        return mb_substr(trim($path, '/'), 0, self::MAX_LENGTH);
    }
}
