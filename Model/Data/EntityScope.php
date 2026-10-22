<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Data;

use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;

/**
 * Immutable scope, created through EntityScopeInterfaceFactory
 *
 * A scope created without arguments is the global one, labelled "Global"; withValues() returns a copy with other
 * values, so a name read from the store's data never passes through a factory's arguments.
 */
class EntityScope implements EntityScopeInterface
{
    private const TYPES = [self::TYPE_DEFAULT, self::TYPE_WEBSITE, self::TYPE_STORE, self::TYPE_GLOBAL];
    public const LABEL_MAX_LENGTH = 255;

    /**
     * @param string $type
     * @param int $id
     * @param string $label
     * @throws \InvalidArgumentException When a value breaks the rules of a scope
     */
    public function __construct(
        private string $type = self::TYPE_GLOBAL,
        private int $id = 0,
        private string $label = 'Global'
    ) {
        $this->assertValid($type, $id, $label);
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritDoc
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * A copy with these values; this scope is left as it is
     *
     * @param string $type One of the TYPE_* constants
     * @param int $id Zero or greater
     * @param string $label 1 to LABEL_MAX_LENGTH characters
     * @return EntityScope
     * @throws \InvalidArgumentException When a value breaks the rules of a scope
     */
    public function withValues(string $type, int $id, string $label): EntityScope
    {
        $this->assertValid($type, $id, $label);
        $copy = clone $this;
        $copy->type = $type;
        $copy->id = $id;
        $copy->label = $label;

        return $copy;
    }

    /**
     * Refuse values that break the rules of a scope
     *
     * @param string $type
     * @param int $id
     * @param string $label
     * @return void
     * @throws \InvalidArgumentException When a value breaks the rules of a scope
     */
    private function assertValid(string $type, int $id, string $label): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown scope type "%s".', $type));
        }

        if ($id < 0) {
            throw new \InvalidArgumentException('A scope id is zero or greater.');
        }

        if (trim($label) === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('A scope label is 1 to %d characters long.', self::LABEL_MAX_LENGTH)
            );
        }
    }
}
