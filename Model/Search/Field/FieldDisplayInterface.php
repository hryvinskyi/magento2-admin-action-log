<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

/**
 * Fields of one kind of record as the admin sees them: their labels, and stored values as the options they name
 *
 * The reverse of FieldLabelSourceInterface: that one finds codes by a label typed in a search, this one names the
 * codes and values a recorded change holds. A code or value without another form is left out, and is shown as
 * stored. Masked values are never given to it. Only what is shown changes: what is stored and searched stays as
 * recorded.
 */
interface FieldDisplayInterface
{
    /**
     * Labels of the fields, keyed by code; read together, so a page of changes costs one lookup
     *
     * @param list<string> $codes
     * @return array<string, string>
     */
    public function labelsOf(array $codes): array;

    /**
     * Stored values of a field as the admin sees them, keyed by the stored value
     *
     * A value the field shows as stored is left out. A value is named by its option's label, by several labels for a
     * list of options, or written as the admin's forms write it (a date, a decimal). The values of a page of changes
     * are given together, so naming them costs one lookup.
     *
     * @param string $code
     * @param list<string> $values
     * @return array<string, string>
     */
    public function valuesOf(string $code, array $values): array;
}
