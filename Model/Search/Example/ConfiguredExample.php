<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Example;

use Hryvinskyi\ActivityLog\Api\Data\Search\RangePreset;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchCriterionInterface;
use Hryvinskyi\ActivityLog\Api\Search\SearchExampleInterface;

/**
 * An example search declared in di.xml: a translated title (translate="true"), its filters and its time range
 */
class ConfiguredExample implements SearchExampleInterface
{
    /**
     * @var list<array{key: string, value: string}>
     */
    private readonly array $chips;

    /**
     * @var RangePreset
     */
    private readonly RangePreset $preset;

    /**
     * @param string $title
     * @param array<string,array{key?:mixed,value?:mixed}> $chips
     * @param string $preset A RangePreset value, such as "30d"
     * @throws \InvalidArgumentException When the title is empty, a filter is malformed or the range is unknown
     */
    public function __construct(
        private readonly string $title,
        array $chips,
        string $preset = '30d'
    ) {
        if (trim($title) === '') {
            throw new \InvalidArgumentException('An example search needs a title.');
        }

        $valid = [];
        foreach ($chips as $name => $chip) {
            $key = $chip['key'] ?? null;
            $value = $chip['value'] ?? null;
            if (!is_string($key) || preg_match(SearchCriterionInterface::KEY_PATTERN, $key) !== 1
                || !is_string($value) || trim($value) === ''
            ) {
                throw new \InvalidArgumentException(sprintf('Filter "%s" of "%s" is malformed.', $name, $title));
            }

            $valid[] = ['key' => $key, 'value' => trim($value)];
        }

        if ($valid === []) {
            throw new \InvalidArgumentException(sprintf('Example "%s" has no filter.', $title));
        }

        $this->chips = $valid;
        $this->preset = RangePreset::tryFrom($preset)
            ?? throw new \InvalidArgumentException(sprintf('Unknown range "%s" for "%s".', $preset, $title));
    }

    /**
     * @inheritDoc
     */
    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * @inheritDoc
     */
    public function getChips(): array
    {
        return $this->chips;
    }

    /**
     * @inheritDoc
     */
    public function getPreset(): RangePreset
    {
        return $this->preset;
    }
}
