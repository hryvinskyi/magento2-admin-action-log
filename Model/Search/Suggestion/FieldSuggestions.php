<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Suggestion;

use Hryvinskyi\ActivityLog\Api\Data\Search\SuggestionInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Search\SuggestionProviderInterface;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecentChanges;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;

/**
 * Fields recent changes changed, by code, under the label the admin sees, the most changed first
 *
 * Declared twice: as "field" over every kind of record, and as "config" over settings, where the code is the
 * setting's path. A field that several kinds of record share is offered once, with all its changes, under the label of
 * the kind that changed it most; the code is the hint when the label differs from it. Unchanged fields of a snapshot
 * do not count, so the counts stay bounded by the changes themselves.
 */
class FieldSuggestions implements SuggestionProviderInterface
{
    /**
     * @var list<string>
     */
    private readonly array $entityTypes;

    /**
     * @param RecentChanges $recentChanges
     * @param FieldDisplays $fieldDisplays
     * @param SuggestionInterfaceFactory $suggestionFactory
     * @param string $key
     * @param array<string,string> $entityTypes Tracked entity codes whose fields are offered; all when empty
     */
    public function __construct(
        private readonly RecentChanges $recentChanges,
        private readonly FieldDisplays $fieldDisplays,
        private readonly SuggestionInterfaceFactory $suggestionFactory,
        private readonly string $key = 'field',
        array $entityTypes = []
    ) {
        $this->entityTypes = array_values(array_unique(array_map('strval', $entityTypes)));
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
        $fields = [];
        $codesByType = [];
        foreach ($this->recentChanges->changedFields($this->entityTypes) as $row) {
            $field = $row['field'];
            $fields[$field] ??= ['count' => 0, 'type' => $row['entity_type']];
            $fields[$field]['count'] += $row['count'];
            $codesByType[$row['entity_type']][] = $field;
        }

        $labels = $this->labels($codesByType);
        uksort(
            $fields,
            static fn (string $a, string $b): int => [$fields[$b]['count'], $a] <=> [$fields[$a]['count'], $b]
        );

        $suggestions = [];
        foreach ($fields as $code => $field) {
            $label = $labels[$field['type']][$code] ?? '';
            $suggestions[] = $this->suggestionFactory->create()
                ->withValue($code, $label)
                ->withHint($label !== '' && $label !== $code ? $code : '')
                ->withCount($field['count']);
        }

        return $suggestions;
    }

    /**
     * Labels of the fields, kind by kind, read once per kind
     *
     * @param array<string,list<string>> $codesByType
     * @return array<string, array<string, string>>
     */
    private function labels(array $codesByType): array
    {
        $labels = [];
        foreach ($codesByType as $entityType => $codes) {
            $display = $this->fieldDisplays->forEntity($entityType);
            $labels[$entityType] = $display === null ? [] : $display->labelsOf($codes);
        }

        return $labels;
    }
}
