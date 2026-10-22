<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Detail;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;

/**
 * Links to the pages of the records a page of changes is about
 *
 * A record gets a link only when its kind has a page in "editPages", the admin may open that page, and the record can
 * still be opened there. The pages are asked once per kind for the whole page of changes, and not at all for a kind
 * the admin may not open. Ids are compared as text.
 *
 * @phpstan-type RecordReference array{type: string, id: string|null}
 */
class SubjectLinks
{
    /**
     * @var array<string, RecordPageInterface>
     */
    private array $editPages = [];

    /**
     * @param UrlInterface $url
     * @param AuthorizationInterface $authorization
     * @param array<string,RecordPageInterface> $editPages Keyed by tracked entity code
     */
    public function __construct(
        private readonly UrlInterface $url,
        private readonly AuthorizationInterface $authorization,
        array $editPages = []
    ) {
        foreach ($editPages as $entityType => $editPage) {
            $this->add((string) $entityType, $editPage);
        }
    }

    /**
     * Edit page URLs of the records, keyed like the records; a record without a link is left out
     *
     * @param array<int,RecordReference> $records Kind and id of each record
     * @return array<int, string>
     */
    public function linksFor(array $records): array
    {
        $idsByType = [];
        foreach ($records as $record) {
            if ($record['id'] !== null && $record['id'] !== '') {
                $idsByType[$record['type']][$record['id']] = $record['id'];
            }
        }

        $paramsByType = [];
        foreach ($idsByType as $type => $ids) {
            $page = $this->openable((string) $type);
            if ($page !== null) {
                $paramsByType[$type] = $page->paramsOf(array_values($ids));
            }
        }

        $links = [];
        foreach ($records as $key => $record) {
            $page = $this->editPages[$record['type']] ?? null;
            $params = $record['id'] === null ? null : ($paramsByType[$record['type']][$record['id']] ?? null);
            if ($page === null || $params === null) {
                continue;
            }

            $links[$key] = $this->url->getUrl($page->getRoute(), $params);
        }

        return $links;
    }

    /**
     * The page of a kind of record, or null when it has none or the admin may not open it
     *
     * @param string $type
     * @return RecordPageInterface|null
     */
    private function openable(string $type): ?RecordPageInterface
    {
        $page = $this->editPages[$type] ?? null;

        return $page !== null && $this->authorization->isAllowed($page->getAclResource()) ? $page : null;
    }

    /**
     * Register a page; anything else in the argument fails here, at construction
     *
     * @param string $entityType
     * @param RecordPageInterface $editPage
     * @return void
     */
    private function add(string $entityType, RecordPageInterface $editPage): void
    {
        $this->editPages[$entityType] = $editPage;
    }
}
