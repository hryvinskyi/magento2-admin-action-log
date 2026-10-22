<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Suggestion;

use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Search\RecentSubjectsInterface;
use Hryvinskyi\ActivityLog\Api\Search\SuggestionProviderInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordKeysInterface;

/**
 * A record key's recently changed records ("product", "order", …), the one changed last first
 *
 * Each is offered under the name its last recorded change gave it, with the kind of record as hint and its number of
 * recent changes. The value picks exactly that record: "#<id>", or its current natural key where the kind has one
 * (a product's SKU), unless the key is all digits and would also read as an id. Declared per key as a virtual type
 * with the tracked entity codes the key covers.
 *
 * @phpstan-type Subject array{type: string, id: string, label: string, count: int}
 */
class RecordSuggestions implements SuggestionProviderInterface
{
    /**
     * @var list<string>
     */
    private readonly array $entityTypes;

    /**
     * @var array<string, RecordKeysInterface>
     */
    private array $naturalKeys = [];

    /**
     * @param RecentSubjectsInterface $recentSubjects
     * @param SuggestionInterfaceFactory $suggestionFactory
     * @param TrackedEntityPool $trackedEntityPool
     * @param string $key
     * @param array<string,string> $entityTypes Tracked entity codes the key covers
     * @param array<string,RecordKeysInterface> $naturalKeys Natural keys of a kind, keyed by its code
     * @throws \InvalidArgumentException When the key covers no kind of record
     */
    public function __construct(
        private readonly RecentSubjectsInterface $recentSubjects,
        private readonly SuggestionInterfaceFactory $suggestionFactory,
        private readonly TrackedEntityPool $trackedEntityPool,
        private readonly string $key,
        array $entityTypes,
        array $naturalKeys = []
    ) {
        $this->entityTypes = array_values(array_unique(array_map('strval', $entityTypes)));
        if ($this->entityTypes === []) {
            throw new \InvalidArgumentException(sprintf('Search key "%s" covers no kind of record.', $key));
        }
        foreach ($naturalKeys as $entityType => $keys) {
            $this->addNaturalKeys((string) $entityType, $keys);
        }
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @inheritDoc
     */
    public function suggestions(): array
    {
        $subjects = $this->recentSubjects->recentlyChanged($this->entityTypes);
        $keys = $this->naturalKeysOf($subjects);

        $suggestions = [];
        foreach ($subjects as $subject) {
            $suggestions[] = $this->suggestion(
                $subject,
                $keys[$subject['type']][$subject['id']] ?? null
            );
        }

        return $suggestions;
    }

    /**
     * One record as a suggestion
     *
     * @param Subject $subject
     * @param string|null $naturalKey
     * @return SuggestionInterface
     */
    private function suggestion(array $subject, ?string $naturalKey): SuggestionInterface
    {
        $kind = $this->trackedEntityPool->get($subject['type']);
        $kindLabel = $kind === null ? $subject['type'] : (string) __($kind->getLabel());
        $value = $naturalKey === null || preg_match('/^\d+$/D', $naturalKey) === 1
            ? '#' . $subject['id']
            : $naturalKey;
        $label = $subject['label'] !== '' ? $subject['label'] : $kindLabel . ' #' . $subject['id'];

        return $this->suggestionFactory->create()
            ->withValue($value, $label)
            ->withHint($kindLabel)
            ->withCount($subject['count']);
    }

    /**
     * Current natural keys of the subjects whose kind has them, by kind, then id
     *
     * @param list<Subject> $subjects
     * @return array<string, array<string, string>>
     */
    private function naturalKeysOf(array $subjects): array
    {
        $ids = [];
        foreach ($subjects as $subject) {
            if (isset($this->naturalKeys[$subject['type']])) {
                $ids[$subject['type']][] = $subject['id'];
            }
        }

        $keys = [];
        foreach ($ids as $entityType => $idsOfType) {
            $keys[$entityType] = $this->naturalKeys[$entityType]->keysOf($idsOfType);
        }

        return $keys;
    }

    /**
     * Register the natural keys of a kind; anything else in the argument fails here, at construction
     *
     * @param string $entityType
     * @param RecordKeysInterface $keys
     * @return void
     */
    private function addNaturalKeys(string $entityType, RecordKeysInterface $keys): void
    {
        $this->naturalKeys[$entityType] = $keys;
    }
}
