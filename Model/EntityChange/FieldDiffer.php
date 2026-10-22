<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldEquivalences;

/**
 * Compares an entity's fields before and after a change
 *
 * A created entity had no values; a deleted one has none left. An update compares the fields the entity has now: a
 * field missing from the state after is not known to have been emptied, so it is not reported. No value and an
 * empty text are the same, and decimals compare by value ("52.00" equals "52"), while other numeric text compares as
 * text so "0123" still differs from "123". Beyond that, the rules FieldEquivalences holds may make two values the
 * same: for every field (texts differing only in line endings) or by the field's input type (a Yes/No field holding no
 * value reads as No). A field kept on the configuration by a box ticked before and after did not change either.
 *
 * @phpstan-type FieldChange array{field: string, before: string|null, after: string|null, changed: bool}
 */
class FieldDiffer
{
    /**
     * @param FieldEquivalences $equivalences
     */
    public function __construct(
        private readonly FieldEquivalences $equivalences
    ) {
    }

    /**
     * Each compared field with its values and whether it changed
     *
     * @param string $entityCode Tracked entity code of the record compared
     * @param array<string,string|null> $before
     * @param array<string,string|null> $after
     * @param ChangeOperation $operation
     * @return list<FieldChange>
     */
    public function diff(string $entityCode, array $before, array $after, ChangeOperation $operation): array
    {
        $fields = $operation === ChangeOperation::Delete ? array_keys($before) : array_keys($after);

        $changes = [];
        foreach ($fields as $field) {
            $old = $operation === ChangeOperation::Create ? null : ($before[$field] ?? null);
            $new = $operation === ChangeOperation::Delete ? null : ($after[$field] ?? null);
            $changes[] = [
                'field' => (string) $field,
                'before' => $old,
                'after' => $new,
                'changed' => !$this->same($entityCode, (string) $field, $old, $new)
                    && !($operation === ChangeOperation::Update
                        && $this->equivalences->followedTheSetting($entityCode, (string) $field, $before, $after)),
            ];
        }

        return $changes;
    }

    /**
     * Whether two values of a field mean the same, by the rules changes are compared with
     *
     * @param string $entityCode Tracked entity code of the record compared
     * @param string $field
     * @param string|null $before
     * @param string|null $after
     * @return bool
     */
    public function same(string $entityCode, string $field, ?string $before, ?string $after): bool
    {
        return $this->sameText($before, $after) || $this->equivalences->same($entityCode, $field, $before, $after);
    }

    /**
     * Whether two stored values are the same text, or the same decimal
     *
     * @param string|null $before
     * @param string|null $after
     * @return bool
     */
    private function sameText(?string $before, ?string $after): bool
    {
        if ($before === $after || (($before ?? '') === '' && ($after ?? '') === '')) {
            return true;
        }

        if ($before === null || $after === null || !is_numeric($before) || !is_numeric($after)) {
            return false;
        }

        if (!str_contains($before, '.') && !str_contains($after, '.')) {
            return false;
        }

        return (float) $before === (float) $after;
    }
}
