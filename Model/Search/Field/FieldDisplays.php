<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

/**
 * How the fields of each kind of tracked record are shown, keyed by the record kind's code
 *
 * A module adds one to the "displays" argument under the code of the tracked entity it describes. A kind without one
 * shows its fields by code and its values as stored.
 */
class FieldDisplays
{
    /**
     * @var array<string, FieldDisplayInterface>
     */
    private array $displays = [];

    /**
     * @param array<string,FieldDisplayInterface> $displays Keyed by tracked entity code
     */
    public function __construct(array $displays = [])
    {
        foreach ($displays as $entityType => $display) {
            $this->add((string) $entityType, $display);
        }
    }

    /**
     * How the fields of a kind of record are shown, or null when they are shown as stored
     *
     * @param string $entityType Tracked entity code
     * @return FieldDisplayInterface|null
     */
    public function forEntity(string $entityType): ?FieldDisplayInterface
    {
        return $this->displays[$entityType] ?? null;
    }

    /**
     * Register a display; anything else in the argument fails here, at construction
     *
     * @param string $entityType
     * @param FieldDisplayInterface $display
     * @return void
     */
    private function add(string $entityType, FieldDisplayInterface $display): void
    {
        $this->displays[$entityType] = $display;
    }
}
