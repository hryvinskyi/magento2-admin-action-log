<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

use Hryvinskyi\AdminActionLog\Model\Search\Field\Value\ValueNamesInterface;

/**
 * Fields a kind of record keeps outside its attributes, shown as the admin sees them: "Attribute Set" Top → Bottom,
 * "Quantity" 100 → 95
 *
 * Declared per kind of record in di.xml, one entry per field code:
 * - "label": the admin's name for the field, translated when shown; without one, the field is labelled elsewhere;
 * - "names": where the ids it stores are named, or how its values are written (ValueNamesInterface);
 * - "list": true when the field stores a JSON list of ids. Each id is named and the list stays a JSON list, so the
 *   items a change added and removed are told by name; an id that names nothing any more is written "#42".
 *
 * A single id that names nothing is shown as stored. All ids of the values asked together are named in one lookup.
 * A field with a label is also found by it, as the admin sees it or as declared, case aside ("field: Attribute Set").
 *
 * Another module adds the fields it records for a kind of record to that kind's virtual type of this class
 * (Model\Search\Field\ProductStaticFields for products), with an item in its "fields" argument.
 *
 * @api
 * @since 1.1.0
 */
class StaticFields implements FieldDisplayInterface, FieldLabelSourceInterface
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    private const MISSING = '#%s';

    /**
     * @var array<string, string>
     */
    private array $labels = [];

    /**
     * @var array<string, ValueNamesInterface>
     */
    private array $names = [];

    /**
     * @var array<string, true>
     */
    private array $lists = [];

    /**
     * @param array<string,array<string,mixed>> $fields Keyed by field code: label, names, list
     * @throws \InvalidArgumentException When a field has no names or a malformed label
     */
    public function __construct(array $fields = [])
    {
        foreach ($fields as $code => $field) {
            $this->add((string) $code, $field);
        }
    }

    /**
     * @inheritDoc
     */
    public function labelsOf(array $codes): array
    {
        $labels = [];
        foreach ($codes as $code) {
            if (isset($this->labels[$code])) {
                $labels[$code] = (string) __($this->labels[$code]);
            }
        }

        return $labels;
    }

    /**
     * @inheritDoc
     */
    public function codesLabelled(string $label): array
    {
        $wanted = mb_strtolower(trim($label));

        return $this->codesWhere(static fn (string $fieldLabel): bool => $fieldLabel === $wanted);
    }

    /**
     * @inheritDoc
     */
    public function codesLabelledWith(string $text): array
    {
        $wanted = mb_strtolower(trim($text));

        return $this->codesWhere(
            static fn (string $fieldLabel): bool => $wanted !== '' && str_contains($fieldLabel, $wanted)
        );
    }

    /**
     * @inheritDoc
     */
    public function valuesOf(string $code, array $values): array
    {
        $names = $this->names[$code] ?? null;
        if ($names === null || $values === []) {
            return [];
        }

        return isset($this->lists[$code]) ? $this->lists($names, $values) : $names->namesOf($values);
    }

    /**
     * Codes of the labelled fields one of whose labels, translated or as declared, in lower case, passes the test
     *
     * @param callable $matches Takes a label in lower case, tells whether it matches
     * @phpstan-param callable(string): bool $matches
     * @return list<string>
     */
    private function codesWhere(callable $matches): array
    {
        $codes = [];
        foreach ($this->labels as $code => $label) {
            $translated = mb_strtolower((string) __($label));
            if ($matches($translated) || $matches(mb_strtolower($label))) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /**
     * JSON lists of ids as JSON lists of names
     *
     * @param ValueNamesInterface $names
     * @param list<string> $values
     * @return array<string, string>
     */
    private function lists(ValueNamesInterface $names, array $values): array
    {
        $lists = [];
        foreach ($values as $value) {
            $ids = $this->ids($value);
            if ($ids !== null && $ids !== []) {
                $lists[$value] = $ids;
            }
        }

        $named = $lists === [] ? [] : $names->namesOf(array_values(array_unique(array_merge(...array_values($lists)))));
        $shown = [];
        foreach ($lists as $value => $ids) {
            $json = json_encode(
                array_map(static fn (string $id): string => $named[$id] ?? sprintf(self::MISSING, $id), $ids),
                self::JSON_FLAGS
            );
            if ($json !== false) {
                $shown[(string) $value] = $json;
            }
        }

        return $shown;
    }

    /**
     * The ids of a JSON list of ids, or null when the value is not one
     *
     * @param string $value
     * @return list<string>|null
     */
    private function ids(string $value): ?array
    {
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || !array_is_list($decoded)) {
            return null;
        }

        $ids = [];
        foreach ($decoded as $id) {
            if (!is_int($id) && !is_string($id)) {
                return null;
            }

            $ids[] = (string) $id;
        }

        return $ids;
    }

    /**
     * Register one field
     *
     * @param string $code
     * @param array<string,mixed> $field
     * @return void
     * @throws \InvalidArgumentException When it has no names or a malformed label
     */
    private function add(string $code, array $field): void
    {
        $names = $field['names'] ?? null;
        $label = $field['label'] ?? null;
        if (!$names instanceof ValueNamesInterface || ($label !== null && !is_string($label))) {
            throw new \InvalidArgumentException(sprintf('Field "%s" needs names and, if any, a text label.', $code));
        }

        $this->names[$code] = $names;
        if ($label !== null && trim($label) !== '') {
            $this->labels[$code] = $label;
        }

        if (($field['list'] ?? false) === true) {
            $this->lists[$code] = true;
        }
    }
}
