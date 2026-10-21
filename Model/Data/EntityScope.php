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
        private readonly string $type,
        private readonly int $id,
        private readonly string $label
    ) {
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
}
