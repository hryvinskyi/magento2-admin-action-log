<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Settings as Stores › Configuration shows them: config paths by the label of their field, and the other way round
 *
 * A setting is stored under its config path, which a field may declare apart from where it is shown. Searching by a
 * label returns that path; the labels of every path are read from the structure once, the first time a label is
 * searched, and kept for the rest of the request. Shown, a path is named "Group › Field" after the group the field
 * sits in, and a value of a field with options is named by its option ("1" → "Yes"; each of a multiselect's
 * comma-separated values in turn). The structure is read from the admin's configuration screens, so labels are the
 * admin's, in the admin's language; what was read is kept for the rest of the request.
 */
class ConfigFieldLabels implements FieldLabelSourceInterface, FieldDisplayInterface, ResetAfterRequestInterface
{
    private const MULTISELECT = 'multiselect';

    /**
     * Label of each path read so far, null when it has none
     *
     * @var array<string, string|null>
     */
    private array $labels = [];

    /**
     * Option labels by stored value of each path read so far, null when its field has no options
     *
     * @var array<string, array<string, string>|null>
     */
    private array $options = [];

    /**
     * The field label of every config path, in lower case; null until a label is searched
     *
     * @var array<string, string>|null
     */
    private ?array $labelList = null;

    /**
     * @param Structure $structure
     * @param OptionLabels $optionLabels
     */
    public function __construct(
        private readonly Structure $structure,
        private readonly OptionLabels $optionLabels
    ) {
    }

    /**
     * @inheritDoc
     */
    public function codesLabelled(string $label): array
    {
        $wanted = mb_strtolower(trim($label));

        return $this->paths(static fn (string $fieldLabel): bool => $fieldLabel === $wanted);
    }

    /**
     * @inheritDoc
     */
    public function codesLabelledWith(string $text): array
    {
        $wanted = mb_strtolower(trim($text));

        return $this->paths(
            static fn (string $fieldLabel): bool => $wanted !== '' && str_contains($fieldLabel, $wanted)
        );
    }

    /**
     * @inheritDoc
     */
    public function labelsOf(array $codes): array
    {
        $labels = [];
        foreach ($codes as $path) {
            if (!array_key_exists($path, $this->labels)) {
                $this->labels[$path] = $this->labelOf($path);
            }

            if ($this->labels[$path] !== null) {
                $labels[$path] = $this->labels[$path];
            }
        }

        return $labels;
    }

    /**
     * @inheritDoc
     */
    public function valuesOf(string $code, array $values): array
    {
        if (!array_key_exists($code, $this->options)) {
            $this->options[$code] = $this->optionsOf($code);
        }

        $options = $this->options[$code];
        if ($options === null) {
            return [];
        }

        $several = $this->field($code)?->getType() === self::MULTISELECT;
        $shown = [];
        foreach ($values as $value) {
            $name = $this->optionLabels->name($options, $value, $several);
            if ($name !== null) {
                $shown[$value] = $name;
            }
        }

        return $shown;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->labels = [];
        $this->options = [];
        $this->labelList = null;
    }

    /**
     * "Group › Field" for a path, the field's label alone outside a labelled group, or null without a field label
     *
     * @param string $path
     * @return string|null
     */
    private function labelOf(string $path): ?string
    {
        $field = $this->field($path);
        $label = $field === null ? '' : trim((string) $field->getLabel());
        if ($field === null || $label === '') {
            return null;
        }

        $fieldPath = (string) $field->getPath();
        $separator = strrpos($fieldPath, '/');
        $groupElement = $separator === false ? null : $this->structure->getElement(substr($fieldPath, 0, $separator));
        $group = $groupElement === null ? '' : trim((string) $groupElement->getLabel());

        return $group === '' ? $label : (string) __('%1 › %2', $group, $label);
    }

    /**
     * Option labels by stored value, or null when the path's field offers no options
     *
     * @param string $path
     * @return array<string, string>|null
     */
    private function optionsOf(string $path): ?array
    {
        $field = $this->field($path);
        if ($field === null || !$field->hasOptions()) {
            return null;
        }

        return $this->optionLabels->byValue($field->getOptions());
    }

    /**
     * The field a setting's path is shown by, following config_path declarations, or null
     *
     * @param string $path
     * @return Field|null
     */
    private function field(string $path): ?Field
    {
        $element = $this->structure->getElementByConfigPath($path);

        return $element instanceof Field ? $element : null;
    }

    /**
     * Config paths whose field label, in lower case, the test accepts
     *
     * @param \Closure(string):bool $accepts
     * @return list<string>
     */
    private function paths(\Closure $accepts): array
    {
        $paths = [];
        foreach ($this->labelList() as $path => $label) {
            if ($accepts($label)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * The field label of every config path the structure declares, in lower case; read once
     *
     * @return array<string, string>
     */
    private function labelList(): array
    {
        if ($this->labelList === null) {
            $this->labelList = [];
            foreach (array_keys($this->structure->getFieldPaths()) as $path) {
                $element = $this->structure->getElementByConfigPath((string) $path);
                if ($element !== null) {
                    $this->labelList[(string) $path] = mb_strtolower(trim((string) $element->getLabel()));
                }
            }
        }

        return $this->labelList;
    }
}
