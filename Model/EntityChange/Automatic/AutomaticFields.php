<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldInputTypesInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldDiffer;

/**
 * Which changed fields of a record were set automatically: changed while an admin saved the record's form, although
 * the form did not ask for it
 *
 * "forms" describes, per tracked entity code, where that kind of record's admin form puts what it submits. Each entry
 * is a request parameter name written the way a form posts it, "{field}" standing for the field's code:
 * - "id": the parameters naming the record the form saves ("id", "customer[entity_id]"); a change of another record,
 *   or of a record the form names nowhere (a new one), is never automatic;
 * - "values": where the form puts a field's value ("product[{field}]"), tried in order;
 * - "useConfig": boxes that keep a field on the configuration's value ("product[use_config_{field}]");
 * - "useDefault": boxes that keep a field of a store view on the default value ("use_default[{field}]").
 * "recordFields" names, per tracked entity code, where the fields that kind of record keeps itself are read (its
 * attributes). A kind of record without a form is never changed automatically.
 *
 * A changed field is automatic when:
 * - a use-config or use-default box of the field was submitted ticked and stayed ticked: the field held no value
 *   before (it inherited one), or the box is a field of the record of its own ("use_config_min_qty") that was ticked
 *   before. A box named by the field's own code ("use_default[status]") keeps no state apart from the field, so the
 *   field's old value never reads as the box's. A box the admin just ticked asked for the change, whatever the rest of
 *   the form says;
 * - the form submitted the value the field had before (compared the way changes are compared). The first of the
 *   "values" names the form submits decides: a form may send a field twice, the value it edits under one name and the
 *   value it loaded under another;
 * - the field has a use-config box, held no value before, and the form submitted it blank ("" or "0"): such a field
 *   follows the configuration, and the form shows it with the box clear and the field off, so it sent back what it
 *   showed;
 * - the form submitted no value for it anywhere, it is a field the record keeps itself, and it holds no list. A field
 *   the record does not keep, such as one another store keeps for the record, may be submitted under another name, so
 *   its absence says nothing; nor does the absence of a list (a value held as a JSON list before or after, or a
 *   multiple-choice field), which a form leaves out when it is emptied.
 * Anything else is not automatic: a value the form submitted as a list, or a field the form submitted with another
 * value, was asked for. Outside an admin action nothing is automatic.
 *
 * @phpstan-type FieldChange array{field: string, before: string|null, after: string|null, changed: bool}
 * @phpstan-type Path list<string>
 * @phpstan-type Form array{id: list<Path>, values: list<Path>, useConfig: list<Path>, useDefault: list<Path>}
 */
class AutomaticFields
{
    public const FIELD = '{field}';
    private const PARTS = ['id', 'values', 'useConfig', 'useDefault'];
    private const TICKED = '1';
    private const BLANK = ['', '0'];
    private const NAME = '/^[^\[\]]+(\[[^\[\]]+\])*$/D';
    private const LIST_INPUT = 'multiselect';

    /**
     * @var array<string, Form>
     */
    private array $forms = [];

    /**
     * @var array<string, FieldInputTypesInterface>
     */
    private array $recordFields = [];

    /**
     * @param SubmittedForm $submitted
     * @param FieldDiffer $differ
     * @param array<string,mixed> $forms Keyed by tracked entity code
     * @param array<string,FieldInputTypesInterface> $recordFields Keyed by tracked entity code
     * @throws \InvalidArgumentException When a form is described wrongly
     */
    public function __construct(
        private readonly SubmittedForm $submitted,
        private readonly FieldDiffer $differ,
        array $forms = [],
        array $recordFields = []
    ) {
        foreach ($forms as $entityCode => $form) {
            $this->forms[(string) $entityCode] = $this->form((string) $entityCode, $form);
        }

        foreach ($recordFields as $entityCode => $fields) {
            $this->addRecordFields((string) $entityCode, $fields);
        }
    }

    /**
     * The fields among the changed ones that were set automatically
     *
     * @param string $entityCode Tracked entity code
     * @param string|null $entityId Id the change is recorded under
     * @param list<FieldChange> $changed The changed fields, secrets left out
     * @param array<string,string|null> $before Every field's value before, as comparable text
     * @return list<string> Their codes, in the order given
     */
    public function of(string $entityCode, ?string $entityId, array $changed, array $before): array
    {
        $form = $this->forms[$entityCode] ?? null;
        $parameters = $this->submitted->parameters();
        if ($form === null || $parameters === null || !$this->savesRecord($form, $parameters, $entityId)) {
            return [];
        }

        $automatic = [];
        foreach ($changed as $change) {
            if ($this->isAutomatic($entityCode, $form, $parameters, $change, $before)) {
                $automatic[] = $change['field'];
            }
        }

        return $automatic;
    }

    /**
     * Whether the form did not ask for the field's change
     *
     * @param string $entityCode
     * @param Form $form
     * @param array<mixed> $parameters
     * @param FieldChange $change
     * @param array<string,string|null> $before
     * @return bool
     */
    private function isAutomatic(string $entityCode, array $form, array $parameters, array $change, array $before): bool
    {
        $field = $change['field'];
        $old = $before[$field] ?? null;
        foreach (array_merge($form['useConfig'], $form['useDefault']) as $box) {
            $path = $this->resolve($box, $field);
            if ($this->scalarAt($parameters, $path) === self::TICKED) {
                return $this->stayedTicked($field, $old, $path, $before);
            }
        }

        foreach ($form['values'] as $template) {
            $path = $this->resolve($template, $field);
            if (!$this->exists($parameters, $path)) {
                continue;
            }

            $submitted = $this->scalarAt($parameters, $path);

            return $submitted !== null
                && ($this->differ->same($entityCode, $field, $old, $submitted)
                    || $this->sentBackBlank($form, $parameters, $field, $old, $submitted));
        }

        return $this->isUnsentRecordField($entityCode, $change);
    }

    /**
     * Whether a field the form did not submit is one whose absence says the form did not ask for its change
     *
     * It is a field the record keeps itself, holding no list: a list the form emptied is left out of what it submits.
     *
     * @param string $entityCode
     * @param FieldChange $change
     * @return bool
     */
    private function isUnsentRecordField(string $entityCode, array $change): bool
    {
        $inputType = ($this->recordFields[$entityCode] ?? null)?->inputTypeOf($change['field']);

        return $inputType !== null
            && $inputType !== self::LIST_INPUT
            && !$this->isList($change['before'])
            && !$this->isList($change['after']);
    }

    /**
     * Whether a stored value is a JSON list ("[]", ["42"])
     *
     * @param string|null $value
     * @return bool
     */
    private function isList(?string $value): bool
    {
        if ($value === null || !str_starts_with(ltrim($value), '[')) {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && array_is_list($decoded);
    }

    /**
     * Whether a box submitted ticked was ticked before
     *
     * It was when the field held no value of its own, or when the box is a field of the record of its own that held a
     * ticked value. A box whose last key is the field's code is no field of its own: what the record holds under that
     * code is the field's value.
     *
     * @param string $field
     * @param string|null $old The field's value before
     * @param list<string> $box The box's parameter path
     * @param array<string,string|null> $before
     * @return bool
     */
    private function stayedTicked(string $field, ?string $old, array $box, array $before): bool
    {
        $boxField = (string) end($box);

        return ($old ?? '') === '' || ($boxField !== $field && ($before[$boxField] ?? null) === self::TICKED);
    }

    /**
     * Whether a field that follows the configuration was sent back blank, as the form shows it
     *
     * @param Form $form
     * @param array<mixed> $parameters
     * @param string $field
     * @param string|null $old
     * @param string $submitted
     * @return bool
     */
    private function sentBackBlank(array $form, array $parameters, string $field, ?string $old, string $submitted): bool
    {
        if (($old ?? '') !== '' || !in_array($submitted, self::BLANK, true)) {
            return false;
        }

        foreach ($form['useConfig'] as $box) {
            if ($this->exists($parameters, $this->resolve($box, $field))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the form saves the record the change is recorded under
     *
     * @param Form $form
     * @param array<mixed> $parameters
     * @param string|null $entityId
     * @return bool
     */
    private function savesRecord(array $form, array $parameters, ?string $entityId): bool
    {
        if ($entityId === null || trim($entityId) === '') {
            return false;
        }

        foreach ($form['id'] as $path) {
            $id = $this->scalarAt($parameters, $path);
            if ($id !== null && trim($id) === trim($entityId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A parameter path with the field's code put in
     *
     * @param list<string> $template
     * @param string $field
     * @return list<string>
     */
    private function resolve(array $template, string $field): array
    {
        return array_map(static fn (string $part): string => str_replace(self::FIELD, $field, $part), $template);
    }

    /**
     * Whether the parameters hold anything at the path
     *
     * @param array<mixed> $parameters
     * @param list<string> $path
     * @return bool
     */
    private function exists(array $parameters, array $path): bool
    {
        $value = $parameters;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return false;
            }
            $value = $value[$key];
        }

        return true;
    }

    /**
     * The single value at the path as text, or null when there is none or it is a list
     *
     * @param array<mixed> $parameters
     * @param list<string> $path
     * @return string|null
     */
    private function scalarAt(array $parameters, array $path): ?string
    {
        $value = $parameters;
        foreach ($path as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => null,
        };
    }

    /**
     * A form description checked and split into parameter paths
     *
     * @param string $entityCode
     * @param mixed $form
     * @return Form
     * @throws \InvalidArgumentException When it is not a map of the known parts holding parameter names
     */
    private function form(string $entityCode, mixed $form): array
    {
        if (!is_array($form) || array_diff(array_map('strval', array_keys($form)), self::PARTS) !== []) {
            throw new \InvalidArgumentException(sprintf(
                'The form of "%s" must name only %s.',
                $entityCode,
                implode(', ', self::PARTS)
            ));
        }

        $parsed = ['id' => [], 'values' => [], 'useConfig' => [], 'useDefault' => []];
        foreach (self::PARTS as $part) {
            $names = $form[$part] ?? [];
            if (!is_array($names)) {
                throw new \InvalidArgumentException(
                    sprintf('The "%s" of the form of "%s" must list parameter names.', $part, $entityCode)
                );
            }

            foreach ($names as $name) {
                $parsed[$part][] = $this->path($entityCode, $name, $part !== 'id');
            }
        }

        if ($parsed['id'] === [] || $parsed['values'] === []) {
            throw new \InvalidArgumentException(
                sprintf('The form of "%s" must name the record it saves and where it puts values.', $entityCode)
            );
        }

        return $parsed;
    }

    /**
     * A parameter name split into its keys ("product[stock_data][{field}]" into product, stock_data, {field})
     *
     * @param string $entityCode
     * @param mixed $name
     * @param bool $perField Whether the name holds the field's code, in its last key
     * @return list<string>
     * @throws \InvalidArgumentException When the name is malformed
     */
    private function path(string $entityCode, mixed $name, bool $perField): array
    {
        $text = is_string($name) ? trim($name) : '';
        $keys = preg_match(self::NAME, $text) === 1 ? preg_split('/[\[\]]+/', $text, -1, PREG_SPLIT_NO_EMPTY) : false;
        if ($keys === false) {
            throw new \InvalidArgumentException(
                sprintf('"%s" in the form of "%s" is not a parameter name.', $text, $entityCode)
            );
        }

        $last = (string) end($keys);
        if ($perField !== str_contains($last, self::FIELD)
            || substr_count($text, self::FIELD) !== ($perField ? 1 : 0)
        ) {
            throw new \InvalidArgumentException(sprintf(
                $perField
                    ? '"%s" in the form of "%s" must hold "{field}" once, in its last key.'
                    : '"%s" in the form of "%s" names the record and cannot hold "{field}".',
                $text,
                $entityCode
            ));
        }

        return $keys;
    }

    /**
     * Register where a kind of record's own fields are read; anything else in the argument fails here
     *
     * @param string $entityCode
     * @param FieldInputTypesInterface $fields
     * @return void
     */
    private function addRecordFields(string $entityCode, FieldInputTypesInterface $fields): void
    {
        $this->recordFields[$entityCode] = $fields;
    }
}
