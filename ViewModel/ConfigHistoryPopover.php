<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\ViewModel;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\LoggingStart;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivityAccess;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * What the settings' history popover prints: the config its script reads to search a setting's changes the way the
 * activity log page does
 *
 * The config carries the search endpoint and the activity log page (built with the URL builder, so they hold the
 * admin secret key; the script only appends a query string), the store's timezone and the admin's locale, the
 * server's time to measure the browser clock against, how many changes the popover lists, since when the log knows
 * of changes, and the popover's wording. Nothing is built for an admin who may not read the activity log.
 */
class ConfigHistoryPopover implements ArgumentInterface
{
    /**
     * The activity log's admin route id: admin links name the route by its id, which the secret key is computed from
     */
    private const LOG_ROUTE = 'hryvinskyi_activitylog';

    /**
     * How long the script waits for a setting's history, in ms
     */
    private const TIMEOUT_MS = 20000;

    /**
     * The newest changes the popover lists
     */
    public const LIMIT = 50;

    /**
     * @param ActivityAccess $access
     * @param LoggingStart $loggingStart
     * @param UrlInterface $urlBuilder
     * @param TimezoneInterface $timezone
     * @param ResolverInterface $localeResolver
     * @param MillisecondClockInterface $clock
     * @param JsonHexTag $jsonHexTag
     */
    public function __construct(
        private readonly ActivityAccess $access,
        private readonly LoggingStart $loggingStart,
        private readonly UrlInterface $urlBuilder,
        private readonly TimezoneInterface $timezone,
        private readonly ResolverInterface $localeResolver,
        private readonly MillisecondClockInterface $clock,
        private readonly JsonHexTag $jsonHexTag
    ) {
    }

    /**
     * The popover's config as JSON safe to print inside a script element, or null when the page shows no history
     *
     * @return string|null
     */
    public function configJson(): ?string
    {
        $config = $this->config();

        return $config === null ? null : (string) $this->jsonHexTag->serialize($config);
    }

    /**
     * The popover's config, or null when the page shows no history
     *
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        if (!$this->access->isAllowed()) {
            return null;
        }

        return [
            'searchUrl' => $this->urlBuilder->getUrl(self::LOG_ROUTE . '/activity/search'),
            'logUrl' => $this->urlBuilder->getUrl(self::LOG_ROUTE . '/activity/index'),
            'timeZone' => $this->timezone->getConfigTimezone(),
            'locale' => $this->localeResolver->getLocale(),
            'serverNowMs' => $this->clock->nowMs(),
            'timeoutMs' => self::TIMEOUT_MS,
            'limit' => self::LIMIT,
            'loggingStartedMs' => $this->loggingStart->ms(),
            'wording' => $this->wording(),
        ];
    }

    /**
     * The popover's wording by name; "%1", "%2"… stand for the numbers and names the script puts in
     *
     * @return array<string, string>
     */
    private function wording(): array
    {
        return [
            'title' => (string) __('History · %1'),
            'copy' => (string) __('Copy'),
            'copied' => (string) __('Copied'),
            'changesOne' => (string) __('%1 change'),
            'changesMany' => (string) __('%1 changes'),
            'noChanges' => (string) __('no changes'),
            'atLeast' => (string) __('%1+'),
            'countLine' => (string) __('%1 · all scopes'),
            'neverSince' => (string) __(
                'Never changed since logging started (%1). The value is the system default.'
            ),
            'never' => (string) __('Never changed since logging started. The value is the system default.'),
            'buttonOne' => (string) __('%1 change · last %2 by %3'),
            'buttonMany' => (string) __('%1 changes · last %2 by %3'),
            'buttonNever' => (string) __('Never changed since logging started'),
        ];
    }
}
