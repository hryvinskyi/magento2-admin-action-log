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
 * Links to the edit pages of the records a page of changes is about
 *
 * A record gets a link only when its kind has an edit page in "editPages", the admin may open that page, and the
 * record still exists. Existence is checked in one query per kind for the whole page, and not at all for a kind the
 * admin may not open. Ids are compared as text.
 *
 * @phpstan-type RecordReference array{type: string, id: string|null}
 */
class SubjectLinks
{
    /**
     * @var array<string, EditPage>
     */
    private array $editPages = [];

    /**
     * @param UrlInterface $url
     * @param AuthorizationInterface $authorization
     * @param array<string,EditPage> $editPages Keyed by tracked entity code
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

        $existing = [];
        foreach ($idsByType as $type => $ids) {
            $editPage = $this->openable((string) $type);
            if ($editPage !== null) {
                $existing[$type] = array_flip($editPage->existing(array_values($ids)));
            }
        }

        $links = [];
        foreach ($records as $key => $record) {
            $id = $record['id'];
            $editPage = $this->editPages[$record['type']] ?? null;
            if ($id === null || $editPage === null || !isset($existing[$record['type']][$id])) {
                continue;
            }

            $links[$key] = $this->url->getUrl($editPage->getRoute(), $editPage->paramsFor($id));
        }

        return $links;
    }

    /**
     * The edit page of a kind of record, or null when it has none or the admin may not open it
     *
     * @param string $type
     * @return EditPage|null
     */
    private function openable(string $type): ?EditPage
    {
        $editPage = $this->editPages[$type] ?? null;

        return $editPage !== null && $this->authorization->isAllowed($editPage->getAclResource()) ? $editPage : null;
    }

    /**
     * Register an edit page; anything else in the argument fails here, at construction
     *
     * @param string $entityType
     * @param EditPage $editPage
     * @return void
     */
    private function add(string $entityType, EditPage $editPage): void
    {
        $this->editPages[$entityType] = $editPage;
    }
}
