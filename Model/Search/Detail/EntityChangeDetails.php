<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Detail;

use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\FieldChangeInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\FieldChangeInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Search\EventDetailProviderInterface;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueKinds;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;

/**
 * Entity changes as search results describe them: what was done to which record, and each field before → after
 *
 * For a page of activities it reads the changes in one query, their field rows in two (counts first, then the
 * values of the rows shown), the labels of each kind's fields in one lookup per kind, the values of each field shown
 * in one lookup per field, and the records' existence in one query per kind (see SubjectLinks).
 *
 * - The verb follows the operation; "what" is the label of the one changed field of an update the admin asked for, or
 *   of the one field set automatically when nothing else changed; otherwise the sentence counts the changed fields.
 * - Fields set automatically (changed while an admin saved the record's form, although the form did not ask for it)
 *   are marked and counted apart, so the sentence counts only what was asked for.
 * - At most ROW_LIMIT rows per activity: the changed fields first, those asked for before those set automatically, then
 *   fields recorded with their value kept. Changed fields beyond the limit are counted, not listed.
 * - The fields that kept their value are counted by their rows when rows were recorded (a snapshot), so each can be
 *   opened; a change that kept only their number gives that number and says no rows exist.
 * - Fields are named as the admin sees them and values are shown as options name them, where a display for the kind
 *   of record knows how; anything else is shown as stored. Masked values are passed on as stored and never resolved.
 * - Values are cut for transport by the field rows themselves.
 * - The sentence names the kind of record before the record and lists the fields after it, so a recorded name that
 *   begins with the kind ("Product 24-MB01 · Bag", "Config value general/locale/code") is given without it, and one
 *   that ends with exactly the codes of the changed fields ("… · special_price, special_from_date") without them, for
 *   old and new rows alike; the journal keeps what it recorded.
 *
 * @phpstan-import-type ChangeSummary from EntityChangeStore
 * @phpstan-import-type FieldPage from EntityChangeStore
 * @phpstan-import-type FieldRow from EntityChangeStore
 */
class EntityChangeDetails implements EventDetailProviderInterface
{
    public const ROW_LIMIT = 20;

    /**
     * What a recorded name puts between the record and the codes of the fields the change changed
     */
    private const FIELD_LIST_SEPARATOR = ' · ';

    /**
     * @param EntityChangeStore $store
     * @param TrackedEntityPool $entities
     * @param FieldDisplays $displays
     * @param SubjectLinks $subjectLinks
     * @param ValueKinds $valueKinds
     * @param EventDetailInterfaceFactory $detailFactory
     * @param FieldChangeInterfaceFactory $fieldFactory
     */
    public function __construct(
        private readonly EntityChangeStore $store,
        private readonly TrackedEntityPool $entities,
        private readonly FieldDisplays $displays,
        private readonly SubjectLinks $subjectLinks,
        private readonly ValueKinds $valueKinds,
        private readonly EventDetailInterfaceFactory $detailFactory,
        private readonly FieldChangeInterfaceFactory $fieldFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function supports(string $channel): bool
    {
        return $channel === ChannelCode::ENTITY;
    }

    /**
     * @inheritDoc
     */
    public function detailsFor(array $activityIds, array $subjectLabels = []): array
    {
        $changes = $this->store->changesOf($activityIds);
        if ($changes === []) {
            return [];
        }

        $fields = $this->store->fieldsOf(array_keys($changes), self::ROW_LIMIT);
        $labels = $this->labels($changes, $fields);
        $shown = $this->shownValues($changes, $fields);
        $links = $this->subjectLinks->linksFor(array_map(
            static fn (array $change): array => ['type' => $change['entityType'], 'id' => $change['entityId']],
            $changes
        ));

        $details = [];
        foreach ($changes as $activityId => $change) {
            $page = $fields[$activityId] ?? [
                'rows' => [],
                'changed' => 0,
                'automatic' => 0,
                'unchanged' => 0,
                'changedFields' => [],
            ];
            $kindLabels = $labels[$change['entityType']] ?? [];
            $rows = [];
            foreach ($page['rows'] as $row) {
                $rows[] = $this->fieldChange(
                    $row,
                    $kindLabels[$row['field']] ?? null,
                    $shown[$change['entityType']][$row['field']] ?? []
                );
            }

            $details[$activityId] = $this->detailFactory->create()
                ->withVerb($change['operation']->sentenceVerb())
                ->withWhat(
                    $this->what($change['operation'], $rows, $page['changed'], $page['automatic']),
                    $page['changed']
                )
                ->withAutomaticCount($page['automatic'])
                ->withSubject($this->typeLabel($change['entityType']), $links[$activityId] ?? null)
                ->withSubjectLabel($this->subjectLabel(
                    $change['entityType'],
                    $subjectLabels[$activityId] ?? null,
                    $page['changedFields']
                ))
                ->withFields($rows, max(0, $page['changed'] - $this->changedRows($rows)))
                ->withUnchangedCount(
                    $page['unchanged'] > 0 ? $page['unchanged'] : $change['unchangedCount'],
                    $page['unchanged'] > 0
                );
        }

        return $details;
    }

    /**
     * Labels of the fields on the page, by kind of record and code, one lookup per kind
     *
     * @param array<int,ChangeSummary> $changes
     * @param array<int,FieldPage> $fields
     * @return array<string, array<string, string>>
     */
    private function labels(array $changes, array $fields): array
    {
        $codesByType = [];
        foreach ($fields as $activityId => $page) {
            $type = $changes[$activityId]['entityType'] ?? null;
            if ($type === null) {
                continue;
            }

            foreach ($page['rows'] as $row) {
                $codesByType[$type][$row['field']] = $row['field'];
            }
        }

        $labels = [];
        foreach ($codesByType as $type => $codes) {
            $display = $this->displays->forEntity((string) $type);
            if ($display !== null) {
                $labels[$type] = $display->labelsOf(array_values($codes));
            }
        }

        return $labels;
    }

    /**
     * The values of the fields on the page as the admin sees them, by kind of record, code and stored value
     *
     * One lookup per field of a kind with a display; masked values are never asked.
     *
     * @param array<int,ChangeSummary> $changes
     * @param array<int,FieldPage> $fields
     * @return array<string, array<string, array<string, string>>>
     */
    private function shownValues(array $changes, array $fields): array
    {
        $values = [];
        foreach ($fields as $activityId => $page) {
            $type = $changes[$activityId]['entityType'] ?? null;
            foreach ($type === null ? [] : $page['rows'] as $row) {
                foreach ($row['masked'] ? [] : [$row['before'], $row['after']] as $value) {
                    if ($value !== null) {
                        $values[$type][$row['field']][$value] = $value;
                    }
                }
            }
        }

        $shown = [];
        foreach ($values as $type => $byField) {
            $display = $this->displays->forEntity((string) $type);
            if ($display === null) {
                continue;
            }

            foreach ($byField as $field => $stored) {
                $shown[$type][$field] = $display->valuesOf((string) $field, array_map('strval', array_values($stored)));
            }
        }

        return $shown;
    }

    /**
     * One field row as a search result shows it
     *
     * @param FieldRow $row
     * @param string|null $label
     * @param array<string,string> $shown The field's values as the admin sees them, by stored value
     * @return FieldChangeInterface
     */
    private function fieldChange(array $row, ?string $label, array $shown): FieldChangeInterface
    {
        $before = $row['masked'] || $row['before'] === null ? $row['before'] : $shown[$row['before']] ?? $row['before'];
        $after = $row['masked'] || $row['after'] === null ? $row['after'] : $shown[$row['after']] ?? $row['after'];

        return $this->fieldFactory->create()
            ->withField($row['field'], $label)
            ->withScope($row['scopeLabel'])
            ->withValues($before, $after)
            ->withKind($this->valueKinds->kindOf($before, $after, $row['masked']))
            ->withChanged($row['changed'])
            ->withAutomatic($row['automatic']);
    }

    /**
     * The label of the one field an update is about, or null when the sentence counts fields instead
     *
     * It is the one changed field the admin asked for; when every changed field was set automatically, the one of
     * them.
     *
     * @param ChangeOperation $operation
     * @param list<FieldChangeInterface> $rows
     * @param int $changedCount
     * @param int $automaticCount
     * @return string|null
     */
    private function what(ChangeOperation $operation, array $rows, int $changedCount, int $automaticCount): ?string
    {
        $asked = $changedCount - $automaticCount;
        if ($operation !== ChangeOperation::Update || ($asked !== 1 && $changedCount !== 1)) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row->isChanged() && ($asked === 0 || !$row->isAutomatic())) {
                return $row->getLabel();
            }
        }

        return null;
    }

    /**
     * Rows of changed fields among those shown
     *
     * @param list<FieldChangeInterface> $rows
     * @return int
     */
    private function changedRows(array $rows): int
    {
        return count(array_filter($rows, static fn (FieldChangeInterface $row): bool => $row->isChanged()));
    }

    /**
     * A recorded subject name without the kind it begins with and the changed fields it ends with, or null as recorded
     *
     * @param string $entityType
     * @param string|null $recorded
     * @param list<string> $changedFields Codes of every field the change changed
     * @return string|null
     */
    private function subjectLabel(string $entityType, ?string $recorded, array $changedFields): ?string
    {
        if ($recorded === null) {
            return null;
        }

        $name = $this->withoutFieldList($recorded, $changedFields);
        $entity = $this->entities->get($entityType);
        $shown = ($entity === null ? null : $this->withoutKind($entity->getLabel(), $name)) ?? $name;

        return $shown === $recorded ? null : $shown;
    }

    /**
     * A name without the kind of record it begins with, or null when it does not begin with it
     *
     * Names are recorded with the kind's untranslated label; the translated one is dropped too, should a name have
     * been recorded with it.
     *
     * @param string $kindLabel
     * @param string $name
     * @return string|null
     */
    private function withoutKind(string $kindLabel, string $name): ?string
    {
        foreach (array_unique([$kindLabel, (string) __($kindLabel)]) as $kind) {
            $prefix = $kind . ' ';
            $rest = str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : '';
            if ($kind !== '' && trim($rest) !== '') {
                return $rest;
            }
        }

        return null;
    }

    /**
     * A name without the " · code, code" it ends with when those are exactly the changed fields' codes
     *
     * A change is recorded under "<record> · <changed field codes>", leaving out a code the record's name already
     * shows. The sentence lists the fields itself, so the list is dropped from the name; a tail that lists other
     * words, or was cut short, is part of the name and stays.
     *
     * @param string $name
     * @param list<string> $changedFields
     * @return string
     */
    private function withoutFieldList(string $name, array $changedFields): string
    {
        $separator = strrpos($name, self::FIELD_LIST_SEPARATOR);
        if ($separator === false || $changedFields === []) {
            return $name;
        }

        $head = substr($name, 0, $separator);
        $listed = explode(', ', substr($name, $separator + strlen(self::FIELD_LIST_SEPARATOR)));
        $expected = array_values(array_unique(array_filter(
            $changedFields,
            static fn (string $field): bool => $field !== '' && !str_contains($head, $field)
        )));
        sort($listed);
        sort($expected);

        return trim($head) !== '' && $listed === $expected ? $head : $name;
    }

    /**
     * The admin's name for a kind of record, or null for a kind no longer tracked
     *
     * @param string $entityType
     * @return string|null
     */
    private function typeLabel(string $entityType): ?string
    {
        $entity = $this->entities->get($entityType);

        return $entity === null ? null : (string) __($entity->getLabel());
    }
}
