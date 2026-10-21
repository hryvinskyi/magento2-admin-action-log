<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

/**
 * Compares an entity's fields before and after a change
 *
 * A created entity had no values; a deleted one has none left. An update compares the fields the entity has now: a
 * field missing from the state after is not known to have been emptied, so it is not reported. No value and an
 * empty text are the same, and decimals compare by value ("52.00" equals "52"), while other numeric text compares as
 * text so "0123" still differs from "123".
 *
 * @phpstan-type FieldChange array{field: string, before: string|null, after: string|null, changed: bool}
 */
class FieldDiffer
{
    /**
     * Each compared field with its values and whether it changed
     *
     * @param array<string,string|null> $before
     * @param array<string,string|null> $after
     * @param ChangeOperation $operation
     * @return list<FieldChange>
     */
    public function diff(array $before, array $after, ChangeOperation $operation): array
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
                'changed' => !$this->same($old, $new),
            ];
        }

        return $changes;
    }

    /**
     * Whether two stored values mean the same
     *
     * @param string|null $before
     * @param string|null $after
     * @return bool
     */
    private function same(?string $before, ?string $after): bool
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
