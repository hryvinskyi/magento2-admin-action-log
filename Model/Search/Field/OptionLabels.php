<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

/**
 * Stored values named by the options of a field, as option sources list them
 *
 * Option lists come as [['value' => …, 'label' => …], …], with option groups nesting such lists under "value", or as
 * a plain value => label map; labels may be phrases. A value of a field holding several options is stored as their
 * values joined by commas and is named option by option; an option no longer offered keeps its stored value.
 */
class OptionLabels
{
    private const VALUE_SEPARATOR = ',';
    private const LABEL_SEPARATOR = ', ';

    /**
     * Option labels by stored value; the first label of a value wins
     *
     * @param mixed $options An option list as a source gives it
     * @return array<string, string>
     */
    public function byValue(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $labels = [];
        foreach ($options as $key => $option) {
            if (!is_array($option)) {
                $label = $this->text($option);
                if ($label !== null) {
                    $labels[(string) $key] ??= $label;
                }
                continue;
            }

            $value = $option['value'] ?? null;
            if (is_array($value)) {
                $labels += $this->byValue($value);
                continue;
            }

            $label = $this->text($option['label'] ?? null);
            if (is_scalar($value) && $label !== null) {
                $labels[(string) $value] ??= $label;
            }
        }

        return $labels;
    }

    /**
     * A stored value named by the options, or null when no option names any part of it
     *
     * @param array<string,string> $labels Option labels by value
     * @param string $value
     * @param bool $several Whether the field holds several options, joined by commas
     * @return string|null
     */
    public function name(array $labels, string $value, bool $several): ?string
    {
        if ($value === '' || $labels === []) {
            return null;
        }

        $shown = [];
        $named = false;
        foreach ($several ? explode(self::VALUE_SEPARATOR, $value) : [$value] as $part) {
            $named = $named || isset($labels[$part]);
            $shown[] = $labels[$part] ?? $part;
        }

        return $named ? implode(self::LABEL_SEPARATOR, $shown) : null;
    }

    /**
     * An option label as text, or null when it is none
     *
     * @param mixed $label
     * @return string|null
     */
    private function text(mixed $label): ?string
    {
        if (is_scalar($label) || $label instanceof \Stringable) {
            $text = trim((string) $label);

            return $text === '' ? null : $text;
        }

        return null;
    }
}
