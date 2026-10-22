<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ConfigHistory;

/**
 * Which Stores › Configuration field stores a value, and under which config path and section
 *
 * A field is read from its declaration as the configuration screen hands it to the field's renderer (its "field
 * config"): the path it is shown under ("section/group"), its id, its type and an optional config path it stores its
 * value under instead. A field stores a value unless:
 * - its declaration carries no id or path, so no config path can be named;
 * - its type is one of the display-only types ("displayOnlyTypes": a button, a label, a note), which show something
 *   but save nothing.
 *
 * A field whose renderer replaces the control with markup of its own (a button that runs an action, a heading)
 * never prints the control's trailing markup, so it shows no history either way.
 */
class StoredSetting
{
    /**
     * @var array<string, true>
     */
    private array $displayOnlyTypes = [];

    /**
     * @param array<string|int,string> $displayOnlyTypes Field types that show something but store no value
     */
    public function __construct(array $displayOnlyTypes = [])
    {
        foreach ($displayOnlyTypes as $type) {
            $this->displayOnlyTypes[$type] = true;
        }
    }

    /**
     * The config path the field stores its value under and the section it is shown in, or null when it stores none
     *
     * @param array<mixed> $fieldConfig
     * @return array{path: string, section: string}|null
     */
    public function of(array $fieldConfig): ?array
    {
        $id = $this->text($fieldConfig['id'] ?? null);
        $shownUnder = trim($this->text($fieldConfig['path'] ?? null), '/');
        $type = $this->text($fieldConfig['type'] ?? null);
        if ($id === '' || $shownUnder === '' || isset($this->displayOnlyTypes[$type])) {
            return null;
        }

        $configPath = trim($this->text($fieldConfig['config_path'] ?? null), '/');

        return [
            'path' => $configPath !== '' ? $configPath : $shownUnder . '/' . $id,
            'section' => explode('/', $shownUnder)[0],
        ];
    }

    /**
     * A declaration's value as trimmed text, or empty
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
