<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison;

/**
 * Which stored values of a field mean the same: by rules that hold for every field, and by the input type the admin
 * edits the field with
 *
 * "everyField" holds the rules for the values of any field (line endings that differ say the same text);
 * "inputTypes" names, per tracked entity code, where the input types of that kind of record's fields are read;
 * "equivalences" holds, per input type, the rule for its values. A field of a kind without input types, or of an
 * input type without a rule, has only the rules every field has.
 *
 * "followedSettings" names, per tracked entity code, the fields a box of the record can keep on a setting of the
 * configuration, each with that box's field ("max_sale_qty" => "use_config_max_sale_qty"). While the box is ticked
 * the record's own value is not used, so a field whose box is ticked before and after a change did not change, whatever
 * its stored value became: the configuration answered for it both times.
 */
class FieldEquivalences
{
    private const TICKED = '1';

    /**
     * @var array<string, FieldInputTypesInterface>
     */
    private array $inputTypes = [];

    /**
     * @var array<string, ValueEquivalenceInterface>
     */
    private array $equivalences = [];

    /**
     * @var list<ValueEquivalenceInterface>
     */
    private array $everyField = [];

    /**
     * @var array<string, array<string, string>>
     */
    private array $followedSettings = [];

    /**
     * @param array<string,FieldInputTypesInterface> $inputTypes Keyed by tracked entity code
     * @param array<string,ValueEquivalenceInterface> $equivalences Keyed by input type
     * @param array<string,ValueEquivalenceInterface> $everyField Rules for the values of every field, by name
     * @param array<string,mixed> $followedSettings Keyed by tracked entity code: field => the field of its box
     * @throws \InvalidArgumentException When a field or its box is not named
     */
    public function __construct(
        array $inputTypes = [],
        array $equivalences = [],
        array $everyField = [],
        array $followedSettings = []
    ) {
        foreach ($inputTypes as $entityCode => $types) {
            $this->addInputTypes((string) $entityCode, $types);
        }

        foreach ($equivalences as $inputType => $equivalence) {
            $this->addEquivalence((string) $inputType, $equivalence);
        }

        foreach ($everyField as $equivalence) {
            $this->addRuleForEveryField($equivalence);
        }

        foreach ($followedSettings as $entityCode => $boxes) {
            $this->addFollowedSettings((string) $entityCode, $boxes);
        }
    }

    /**
     * Whether a field followed the configuration through a change: its box was ticked before and after
     *
     * @param string $entityCode Tracked entity code
     * @param string $field
     * @param array<string,string|null> $before Every field's value before
     * @param array<string,string|null> $after Every field's value after
     * @return bool
     */
    public function followedTheSetting(string $entityCode, string $field, array $before, array $after): bool
    {
        $box = $this->followedSettings[$entityCode][$field] ?? null;

        return $box !== null && ($before[$box] ?? null) === self::TICKED && ($after[$box] ?? null) === self::TICKED;
    }

    /**
     * Whether two values of a field of a kind of record mean the same
     *
     * They do by a rule every field has, or by the rule of the field's input type.
     *
     * @param string $entityCode Tracked entity code
     * @param string $field
     * @param string|null $before
     * @param string|null $after
     * @return bool
     */
    public function same(string $entityCode, string $field, ?string $before, ?string $after): bool
    {
        foreach ($this->everyField as $equivalence) {
            if ($equivalence->same($before, $after)) {
                return true;
            }
        }

        $inputType = ($this->inputTypes[$entityCode] ?? null)?->inputTypeOf($field);
        $equivalence = $inputType === null ? null : ($this->equivalences[$inputType] ?? null);

        return $equivalence !== null && $equivalence->same($before, $after);
    }

    /**
     * Register where a kind of record's input types are read; anything else in the argument fails here
     *
     * @param string $entityCode
     * @param FieldInputTypesInterface $types
     * @return void
     */
    private function addInputTypes(string $entityCode, FieldInputTypesInterface $types): void
    {
        $this->inputTypes[$entityCode] = $types;
    }

    /**
     * Register a rule every field has; anything else in the argument fails here
     *
     * @param ValueEquivalenceInterface $equivalence
     * @return void
     */
    private function addRuleForEveryField(ValueEquivalenceInterface $equivalence): void
    {
        $this->everyField[] = $equivalence;
    }

    /**
     * Register the rule of an input type; anything else in the argument fails here
     *
     * @param string $inputType
     * @param ValueEquivalenceInterface $equivalence
     * @return void
     */
    private function addEquivalence(string $inputType, ValueEquivalenceInterface $equivalence): void
    {
        $this->equivalences[$inputType] = $equivalence;
    }

    /**
     * Register the boxes of a kind of record that keep fields on the configuration
     *
     * @param string $entityCode
     * @param mixed $boxes
     * @return void
     * @throws \InvalidArgumentException When a field or its box is not named
     */
    private function addFollowedSettings(string $entityCode, mixed $boxes): void
    {
        if (!is_array($boxes)) {
            throw new \InvalidArgumentException(
                sprintf('The followed settings of "%s" must map fields to the fields of their boxes.', $entityCode)
            );
        }

        foreach ($boxes as $field => $box) {
            if (!is_string($box) || trim($box) === '' || trim((string) $field) === '') {
                throw new \InvalidArgumentException(
                    sprintf('A followed setting of "%s" must name a field and the field of its box.', $entityCode)
                );
            }

            $this->followedSettings[$entityCode][(string) $field] = trim($box);
        }
    }
}
