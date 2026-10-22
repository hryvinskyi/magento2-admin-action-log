<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Field;

/**
 * Every field label source, asked in turn; a module adds one to the "sources" argument
 */
class FieldLabels implements FieldLabelSourceInterface
{
    /**
     * @var list<FieldLabelSourceInterface>
     */
    private array $sources = [];

    /**
     * @param array<string,FieldLabelSourceInterface> $sources
     */
    public function __construct(array $sources = [])
    {
        foreach ($sources as $source) {
            $this->add($source);
        }
    }

    /**
     * @inheritDoc
     */
    public function codesLabelled(string $label): array
    {
        $codes = [];
        foreach ($this->sources as $source) {
            $codes[] = $source->codesLabelled($label);
        }

        return array_values(array_unique(array_merge(...$codes)));
    }

    /**
     * @inheritDoc
     */
    public function codesLabelledWith(string $text): array
    {
        $codes = [];
        foreach ($this->sources as $source) {
            $codes[] = $source->codesLabelledWith($text);
        }

        return array_values(array_unique(array_merge(...$codes)));
    }

    /**
     * Register a source; anything else in the argument fails here, at construction
     *
     * @param FieldLabelSourceInterface $source
     * @return void
     */
    private function add(FieldLabelSourceInterface $source): void
    {
        $this->sources[] = $source;
    }
}
