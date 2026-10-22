<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\RecordActivity;

use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordKeysInterface;
use Magento\Framework\App\RequestInterface;

/**
 * An admin page that edits one kind of record and shows that record's activity: the search key that names the
 * record, the tracked entity code its changes are recorded under, the request parameter carrying its id, and the
 * layout handle that adds the activity panel to the page
 *
 * A record is named by its exact form, "#<id>", so the count on the page and the panel's list never take in another
 * record whose id or name merely contains the number. A request without a positive id is a new record: it has no
 * activity, and the page gets neither the button nor the panel.
 *
 * Optional: the record's natural key shown beside its id (a product's SKU) and, for records edited per store view,
 * the name changes in the default scope are recorded under, which the panel searches when "All store views" is off
 * on the default scope. Declared in di.xml as a virtual type per kind.
 */
class RecordPage
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*$/D';
    private const KEY_PATTERN = '/^[a-z][a-z_]{0,31}$/D';
    private const ID_PATTERN = '/^[1-9]\d{0,17}$/D';

    /**
     * @param string $searchKey The search key that names one record of the kind, such as "product"
     * @param string $entityType Tracked entity code of the kind
     * @param string $idParam Request parameter of the edit page that carries the record's id
     * @param string $layoutHandle Layout handle that adds the activity panel to the edit page
     * @param string $defaultScopeLabel Scope name of changes made on the default scope; empty for a kind whose
     *     changes have no store view
     * @param RecordKeysInterface|null $keys Where the record's natural key is read, if it has one worth showing
     * @param string $keyLabel The natural key's name, untranslated, such as "SKU"
     * @throws \InvalidArgumentException When the declaration is malformed
     */
    public function __construct(
        private readonly string $searchKey,
        private readonly string $entityType,
        private readonly string $idParam,
        private readonly string $layoutHandle,
        private readonly string $defaultScopeLabel = '',
        private readonly ?RecordKeysInterface $keys = null,
        private readonly string $keyLabel = ''
    ) {
        if (preg_match(self::KEY_PATTERN, $searchKey) !== 1) {
            throw new \InvalidArgumentException(sprintf('Malformed search key "%s".', $searchKey));
        }

        foreach ([$entityType, $idParam, $layoutHandle] as $name) {
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw new \InvalidArgumentException(sprintf('Malformed name "%s" of the %s page.', $name, $searchKey));
            }
        }

        if (($keys === null) !== ($keyLabel === '')) {
            throw new \InvalidArgumentException(
                sprintf('The %s page names a natural key without a source, or the other way round.', $searchKey)
            );
        }
    }

    /**
     * The search key that names one record of the kind
     *
     * @return string
     */
    public function getSearchKey(): string
    {
        return $this->searchKey;
    }

    /**
     * Tracked entity code the kind's changes are recorded under
     *
     * @return string
     */
    public function getEntityType(): string
    {
        return $this->entityType;
    }

    /**
     * Layout handle that adds the activity panel to the page
     *
     * @return string
     */
    public function getLayoutHandle(): string
    {
        return $this->layoutHandle;
    }

    /**
     * Scope name of changes made on the default scope, or an empty string for a kind without store views
     *
     * @return string
     */
    public function getDefaultScopeLabel(): string
    {
        return $this->defaultScopeLabel;
    }

    /**
     * The id of the record the request edits, or null for a new record
     *
     * @param RequestInterface $request
     * @return string|null
     */
    public function recordIdIn(RequestInterface $request): ?string
    {
        $id = $request->getParam($this->idParam);

        return is_scalar($id) && preg_match(self::ID_PATTERN, (string) $id) === 1 ? (string) $id : null;
    }

    /**
     * The search value that names exactly this record
     *
     * @param string $id
     * @return string
     */
    public function criterionValue(string $id): string
    {
        return '#' . $id;
    }

    /**
     * The record's natural key with its name, or null when the kind shows none or the record has none
     *
     * @param string $id
     * @return array{label: string, value: string}|null
     */
    public function naturalKeyOf(string $id): ?array
    {
        $value = $this->keys?->keysOf([$id])[$id] ?? null;

        return $value === null ? null : ['label' => (string) __($this->keyLabel), 'value' => $value];
    }
}
