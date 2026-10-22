<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\ViewModel;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\ActivityActorsInterface;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivityAccess;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivitySummary;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecordPage;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecordHistory;
use Hryvinskyi\AdminActionLog\Model\Search\Condition\ScopeCondition;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Store\Model\StoreManagerInterface;

/**
 * What a record's activity panel prints: the config its script reads to search the record's changes the way the
 * activity log page does
 *
 * The config carries the search endpoint and the activity log page (built with the URL builder, so they hold the admin
 * secret key; the script only appends a query string), the store's timezone and the admin's locale, the server's time
 * to measure the browser clock against, the record (its exact search value, its id, its name as the page heading shows
 * it and its natural key), the count of the last 30 days with the newest change, the fields and actors of the record's
 * changes for the panel's choices, the scopes "All store views" switched off searches, and the panel's wording. Editing
 * a store view, those are the store view (by its code) and the default scope its values fall back to; editing the
 * default scope, the default scope alone. Nothing is built for a new record or for an admin who may not read the
 * activity log.
 *
 * Declared per kind of record as a virtual type that names its RecordPage.
 */
class RecordActivityPanel implements ArgumentInterface
{
    /**
     * The activity log's admin route id: admin links name the route by its id, which the secret key is computed from
     */
    private const LOG_ROUTE = 'hryvinskyi_activitylog';

    /**
     * How long the script waits for a page of results, in ms
     */
    private const TIMEOUT_MS = 20000;

    /**
     * Request parameter of the store view an edit page shows
     */
    private const STORE_PARAM = 'store';

    /**
     * @param RecordPage $page
     * @param RequestInterface $request
     * @param ActivityAccess $access
     * @param ActivitySummary $summary
     * @param RecordHistory $history
     * @param ActivityActorsInterface $actors
     * @param FieldDisplays $fieldDisplays
     * @param StoreManagerInterface $storeManager
     * @param PageConfig $pageConfig
     * @param UrlInterface $urlBuilder
     * @param TimezoneInterface $timezone
     * @param ResolverInterface $localeResolver
     * @param MillisecondClockInterface $clock
     * @param JsonHexTag $jsonHexTag
     */
    public function __construct(
        private readonly RecordPage $page,
        private readonly RequestInterface $request,
        private readonly ActivityAccess $access,
        private readonly ActivitySummary $summary,
        private readonly RecordHistory $history,
        private readonly ActivityActorsInterface $actors,
        private readonly FieldDisplays $fieldDisplays,
        private readonly StoreManagerInterface $storeManager,
        private readonly PageConfig $pageConfig,
        private readonly UrlInterface $urlBuilder,
        private readonly TimezoneInterface $timezone,
        private readonly ResolverInterface $localeResolver,
        private readonly MillisecondClockInterface $clock,
        private readonly JsonHexTag $jsonHexTag
    ) {
    }

    /**
     * Whether the page shows the panel: an existing record, and an admin who may read the activity log
     *
     * @return bool
     */
    public function isShown(): bool
    {
        return $this->recordId() !== null;
    }

    /**
     * Whether the record's changes are made per store view, so the panel offers "All store views"
     *
     * @return bool
     */
    public function isScoped(): bool
    {
        return $this->page->getDefaultScopeLabel() !== '';
    }

    /**
     * The panel's config as JSON safe to print inside a script element, or null when the page shows no panel
     *
     * @return string|null
     */
    public function configJson(): ?string
    {
        $config = $this->config();

        return $config === null ? null : (string) $this->jsonHexTag->serialize($config);
    }

    /**
     * The panel's config, or null when the page shows no panel
     *
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        $id = $this->recordId();
        if ($id === null) {
            return null;
        }

        $type = $this->page->getEntityType();
        $naturalKey = $this->page->naturalKeyOf($id);

        return [
            'searchUrl' => $this->urlBuilder->getUrl(self::LOG_ROUTE . '/activity/search'),
            'logUrl' => $this->urlBuilder->getUrl(self::LOG_ROUTE . '/activity/index'),
            'timeZone' => $this->timezone->getConfigTimezone(),
            'locale' => $this->localeResolver->getLocale(),
            'serverNowMs' => $this->clock->nowMs(),
            'timeoutMs' => self::TIMEOUT_MS,
            'days' => ActivitySummary::DAYS,
            'record' => [
                'key' => $this->page->getSearchKey(),
                'value' => $this->page->criterionValue($id),
                'id' => $id,
                'title' => $this->title(),
                'naturalKey' => $naturalKey,
            ],
            'summary' => $this->summary->summaryOf($this->page, $id),
            'fields' => $this->fieldChoices($type, $id),
            'actors' => $this->actors->namesOf($this->history->recentActivityIds($type, $id)),
            'scope' => $this->isScoped() ? $this->scopeValue() : null,
            'wording' => $this->wording(),
        ];
    }

    /**
     * The id of the record the page edits, when the panel is shown at all
     *
     * @return string|null
     */
    private function recordId(): ?string
    {
        return $this->access->isAllowed() ? $this->page->recordIdIn($this->request) : null;
    }

    /**
     * The record's name as the page heading shows it, or null when the page has no heading
     *
     * @return string|null
     */
    private function title(): ?string
    {
        $heading = $this->pageConfig->getTitle()->getShortHeading();

        return is_scalar($heading) && trim((string) $heading) !== '' ? trim((string) $heading) : null;
    }

    /**
     * The fields the record's changes changed, labelled as the admin sees them, by label
     *
     * @param string $type
     * @param string $id
     * @return list<array{value: string, label: string}>
     */
    private function fieldChoices(string $type, string $id): array
    {
        $codes = $this->history->changedFields($type, $id);
        $labels = $codes === [] ? [] : $this->fieldDisplays->forEntity($type)?->labelsOf($codes) ?? [];
        $choices = array_map(
            static fn (string $code): array => ['value' => $code, 'label' => $labels[$code] ?? $code],
            $codes
        );
        usort($choices, static fn (array $one, array $other): int => strcasecmp($one['label'], $other['label']));

        return $choices;
    }

    /**
     * The "store" filter for the scope the page edits
     *
     * Editing a store view, the store view by its code with the default scope; otherwise the default scope alone.
     *
     * @return string
     */
    private function scopeValue(): string
    {
        $default = $this->page->getDefaultScopeLabel();
        $storeId = $this->request->getParam(self::STORE_PARAM);
        if (!is_numeric($storeId) || (int) $storeId < 1) {
            return $default;
        }

        try {
            $code = (string) $this->storeManager->getStore((int) $storeId)->getCode();
        } catch (NoSuchEntityException) {
            return $default;
        }

        return $code === '' ? $default : $code . ' ' . ScopeCondition::ALTERNATIVES . ' ' . $default;
    }

    /**
     * The panel's wording by name; "%1", "%2"… stand for the numbers and names the script puts in
     *
     * @return array<string, string|array<string, string>>
     */
    private function wording(): array
    {
        return [
            'idLine' => (string) __('ID %1'),
            'keyLine' => (string) __('%1 %2'),
            'summaryOne' => (string) __('%1 change in the last %2 days · last by %3 %4'),
            'summaryMany' => (string) __('%1 changes in the last %2 days · last by %3 %4'),
            'summaryNone' => (string) __('No changes in the last %1 days'),
            'countOne' => (string) __('%1 of %2 change'),
            'countMany' => (string) __('%1 of %2 changes'),
            'atLeast' => (string) __('%1+'),
            'badge' => (string) __('%1 changes in the last %2 days'),
            'periods' => [
                '7d' => (string) __('Last 7 days'),
                '30d' => (string) __('Last 30 days'),
                'all' => (string) __('All time'),
            ],
            'allTime' => (string) __('everything recorded'),
            'noMatchTitle' => (string) __('No changes match'),
            'noMatchText' => (string) __('Try "All fields", "Anyone" or a longer period.'),
        ];
    }
}
