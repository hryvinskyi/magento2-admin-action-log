<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\ViewModel;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivityAccess;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecentFieldChanges;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecordPage;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * What the hints under a record's recently changed fields print: the config their script reads, as JSON safe inside a
 * script element
 *
 * The config carries the newest change of each field changed in the last days — field code, label, time, actor,
 * values and their kind — the server's time to measure the browser clock against, the admin's locale and the hints'
 * wording. It is printed in a JSON script element and nowhere else: the edit form's component config runs every
 * string through the admin's template engine, which would turn a stored "${…}" into code. Values are data the page
 * writes as text. Nothing is built for a new record, for an admin who may not read the activity log, or for a record
 * with no recent change.
 *
 * Declared per kind of record as a virtual type that names its RecordPage.
 *
 * @phpstan-import-type FieldHint from RecentFieldChanges
 */
class RecordFieldHints implements ArgumentInterface
{
    /**
     * @param RecordPage $page
     * @param RequestInterface $request
     * @param ActivityAccess $access
     * @param RecentFieldChanges $changes
     * @param MillisecondClockInterface $clock
     * @param ResolverInterface $localeResolver
     * @param JsonHexTag $jsonHexTag
     */
    public function __construct(
        private readonly RecordPage $page,
        private readonly RequestInterface $request,
        private readonly ActivityAccess $access,
        private readonly RecentFieldChanges $changes,
        private readonly MillisecondClockInterface $clock,
        private readonly ResolverInterface $localeResolver,
        private readonly JsonHexTag $jsonHexTag
    ) {
    }

    /**
     * The hints' config as JSON safe to print inside a script element, or null when the page shows no hint
     *
     * @return string|null
     */
    public function configJson(): ?string
    {
        $config = $this->config();

        return $config === null ? null : (string) $this->jsonHexTag->serialize($config);
    }

    /**
     * The hints' config, or null when the page shows no hint
     *
     * @return array{hints: list<FieldHint>, serverNowMs: int, locale: string, wording: array<string, string>}|null
     */
    public function config(): ?array
    {
        $id = $this->access->isAllowed() ? $this->page->recordIdIn($this->request) : null;
        $hints = $id === null ? [] : $this->changes->of($this->page, $id);
        if ($hints === []) {
            return null;
        }

        return [
            'hints' => $hints,
            'serverNowMs' => $this->clock->nowMs(),
            'locale' => $this->localeResolver->getLocale(),
            'wording' => $this->wording(),
        ];
    }

    /**
     * The hints' wording by name; "%1", "%2" stand for the time and the actor
     *
     * @return array<string, string>
     */
    private function wording(): array
    {
        return [
            'changed' => (string) __('Changed %1 by %2'),
            'empty' => (string) __('(empty)'),
            'masked' => (string) __('masked value'),
            'history' => (string) __('History'),
            'historyOf' => (string) __('History of %1'),
        ];
    }
}
