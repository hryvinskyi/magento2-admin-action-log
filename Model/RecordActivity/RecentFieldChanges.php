<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\RecordActivity;

use Hryvinskyi\ActivityLog\Api\Data\Search\ActivityFactInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\ValueKind;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\ActivityFactsInterface;
use Hryvinskyi\ActivityLog\Model\Search\RecentDays;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueKinds;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecordHistory;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplayInterface;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The newest change of each field of a record changed in the last 7 days: when, by whom, and the values before and
 * after as the admin sees them
 *
 * A field set automatically (changed while an admin saved the form, although the form did not ask for it) gets no
 * hint from that change: the hint says who changed the field, and nobody did. Every field any change of those days
 * changed as asked gets its hint, however many fields one change touched: the record's
 * newest changes (RecordHistory::CHANGES of them) are timed through the journal in one read, those of the last DAYS
 * days are kept, the fields each changed are read without their values, and then only the values of each field's
 * newest change. "The last N days" runs from the store's midnight N−1 days ago to now, as the activity log's presets
 * count them. Values are data, never markup: the page writes them as text. Only short values travel: a long text, a
 * JSON value or a masked one is described by its kind alone, and a short value is cut at VALUE_CHARS. One read per
 * record per request.
 *
 * @phpstan-type FieldHint array{
 *     field: string,
 *     label: string,
 *     ms: int,
 *     actor: string,
 *     before: string|null,
 *     after: string|null,
 *     kind: string
 * }
 * @phpstan-import-type ChangedValue from RecordHistory
 */
class RecentFieldChanges implements ResetAfterRequestInterface
{
    /**
     * The days looked back, ending now
     */
    public const DAYS = 7;

    /**
     * Longest value passed on, in characters
     */
    public const VALUE_CHARS = 200;

    /**
     * @var array<string, list<FieldHint>>
     */
    private array $hints = [];

    /**
     * @param RecordHistory $history
     * @param ActivityFactsInterface $facts
     * @param FieldDisplays $displays
     * @param ValueKinds $valueKinds
     * @param RecentDays $recentDays
     * @param MillisecondClockInterface $clock
     */
    public function __construct(
        private readonly RecordHistory $history,
        private readonly ActivityFactsInterface $facts,
        private readonly FieldDisplays $displays,
        private readonly ValueKinds $valueKinds,
        private readonly RecentDays $recentDays,
        private readonly MillisecondClockInterface $clock
    ) {
    }

    /**
     * The newest change of each field changed in the last DAYS days
     *
     * The most recently changed field first; fields changed together in code order.
     *
     * @param RecordPage $page The kind of record
     * @param string $id The record's id
     * @return list<FieldHint>
     */
    public function of(RecordPage $page, string $id): array
    {
        $type = $page->getEntityType();
        $key = $type . ':' . $id;
        if (!isset($this->hints[$key])) {
            $this->hints[$key] = $this->read($type, $id);
        }

        return $this->hints[$key];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->hints = [];
    }

    /**
     * The hints of one record, read from its changes
     *
     * @param string $type
     * @param string $id
     * @return list<FieldHint>
     */
    private function read(string $type, string $id): array
    {
        $recent = $this->recent($this->history->recentActivityIds($type, $id));
        $newest = [];
        foreach ($this->history->changedFieldsOf(array_keys($recent)) as $activityId => $fields) {
            foreach ($fields as $field) {
                $current = $newest[$field] ?? null;
                if ($current === null || $this->isNewer($recent[$activityId], $recent[$current])) {
                    $newest[$field] = $activityId;
                }
            }
        }
        if ($newest === []) {
            return [];
        }

        $display = $this->displays->forEntity($type);
        $labels = $display?->labelsOf(array_map('strval', array_keys($newest))) ?? [];
        $hints = [];
        foreach ($this->history->changedValues($newest) as $value) {
            $hints[] = $this->hint($value, $recent[$value['activityId']], $labels, $display);
        }
        usort(
            $hints,
            static fn (array $one, array $other): int => [$other['ms'], $one['field']] <=> [$one['ms'], $other['field']]
        );

        return $hints;
    }

    /**
     * The facts of the changes made in the last DAYS days, by activity id
     *
     * @param list<int> $activityIds
     * @return array<int, ActivityFactInterface>
     */
    private function recent(array $activityIds): array
    {
        $sinceMs = $this->recentDays->startMs(self::DAYS, $this->clock->nowMs());

        return array_filter(
            $activityIds === [] ? [] : $this->facts->of($activityIds),
            static fn (ActivityFactInterface $fact): bool => $fact->getOccurredAtMs() >= $sinceMs
        );
    }

    /**
     * Whether one change was made after another; of two made in the same millisecond, the one recorded later
     *
     * @param ActivityFactInterface $one
     * @param ActivityFactInterface $other
     * @return bool
     */
    private function isNewer(ActivityFactInterface $one, ActivityFactInterface $other): bool
    {
        return [$one->getOccurredAtMs(), $one->getActivityId()] > [$other->getOccurredAtMs(), $other->getActivityId()];
    }

    /**
     * One field's newest change
     *
     * @param ChangedValue $value
     * @param ActivityFactInterface $fact
     * @param array<string,string> $labels
     * @param FieldDisplayInterface|null $display
     * @return FieldHint
     */
    private function hint(
        array $value,
        ActivityFactInterface $fact,
        array $labels,
        ?FieldDisplayInterface $display
    ): array {
        $before = $value['masked'] ? null : $this->shown($display, $value['field'], $value['before']);
        $after = $value['masked'] ? null : $this->shown($display, $value['field'], $value['after']);
        $kind = $this->valueKinds->kindOf($before, $after, $value['masked']);
        $short = $kind === ValueKind::Short;

        return [
            'field' => $value['field'],
            'label' => $labels[$value['field']] ?? $value['field'],
            'ms' => $fact->getOccurredAtMs(),
            'actor' => $fact->getActorName(),
            'before' => $short ? $this->cut($before) : null,
            'after' => $short ? $this->cut($after) : null,
            'kind' => $kind->value,
        ];
    }

    /**
     * A stored value as the admin sees it, or as stored when the kind of record has no other form for it
     *
     * @param FieldDisplayInterface|null $display
     * @param string $field
     * @param string|null $value
     * @return string|null
     */
    private function shown(?FieldDisplayInterface $display, string $field, ?string $value): ?string
    {
        return $value === null ? null : ($display?->valuesOf($field, [$value])[$value] ?? $value);
    }

    /**
     * A value cut at VALUE_CHARS characters
     *
     * @param string|null $value
     * @return string|null
     */
    private function cut(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::VALUE_CHARS);
    }
}
