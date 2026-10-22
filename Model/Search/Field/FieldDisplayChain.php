<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

/**
 * Several displays of one kind of record asked in order: the first that labels a code or names a value wins
 *
 * Lets a few fields a kind of record keeps outside its attributes (its attribute set, its type) be shown by a display
 * of their own, in front of the one that shows the attributes.
 */
class FieldDisplayChain implements FieldDisplayInterface
{
    /**
     * @var list<FieldDisplayInterface>
     */
    private array $displays = [];

    /**
     * @param array<FieldDisplayInterface> $displays In the order they are asked
     */
    public function __construct(array $displays = [])
    {
        foreach ($displays as $display) {
            $this->add($display);
        }
    }

    /**
     * @inheritDoc
     */
    public function labelsOf(array $codes): array
    {
        $labels = [];
        foreach ($this->displays as $display) {
            $missing = array_values(array_diff($codes, array_keys($labels)));
            if ($missing === []) {
                break;
            }

            $labels += $display->labelsOf($missing);
        }

        return $labels;
    }

    /**
     * @inheritDoc
     */
    public function valuesOf(string $code, array $values): array
    {
        $shown = [];
        foreach ($this->displays as $display) {
            $missing = array_values(array_diff($values, array_map('strval', array_keys($shown))));
            if ($missing === []) {
                break;
            }

            $shown += $display->valuesOf($code, $missing);
        }

        return $shown;
    }

    /**
     * Register a display; anything else in the argument fails here, at construction
     *
     * @param FieldDisplayInterface $display
     * @return void
     */
    private function add(FieldDisplayInterface $display): void
    {
        $this->displays[] = $display;
    }
}
