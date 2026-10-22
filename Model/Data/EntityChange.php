<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Data;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;

/**
 * Immutable change to record, created blank through EntityChangeInterfaceFactory and filled through its with…()
 * methods
 *
 * Its scope starts as the global scope it is given and changes through that scope's own withValues(), which holds the
 * rules of a scope.
 */
class EntityChange implements EntityChangeInterface
{
    private const OPERATIONS = [self::OPERATION_CREATE, self::OPERATION_UPDATE, self::OPERATION_DELETE];

    /**
     * @var string
     */
    private string $entityCode = '';

    /**
     * @var string
     */
    private string $entityId = '';

    /**
     * @var string
     */
    private string $operation = self::OPERATION_UPDATE;

    /**
     * @var array<string, string|null>
     */
    private array $before = [];

    /**
     * @var array<string, string|null>
     */
    private array $after = [];

    /**
     * @param EntityScope $scope The scope of a change that names none
     */
    public function __construct(
        private EntityScope $scope
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getEntityCode(): string
    {
        return $this->entityCode;
    }

    /**
     * @inheritDoc
     */
    public function getEntityId(): string
    {
        return $this->entityId;
    }

    /**
     * @inheritDoc
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * @inheritDoc
     */
    public function getScope(): EntityScopeInterface
    {
        return $this->scope;
    }

    /**
     * @inheritDoc
     */
    public function getFields(): array
    {
        return array_map('strval', array_keys($this->after));
    }

    /**
     * @inheritDoc
     */
    public function getBefore(): array
    {
        return $this->before;
    }

    /**
     * @inheritDoc
     */
    public function getAfter(): array
    {
        return $this->after;
    }

    /**
     * @inheritDoc
     */
    public function withEntity(string $code, string $id): EntityChangeInterface
    {
        if (preg_match(self::ENTITY_CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(sprintf('Malformed tracked entity code "%s".', $code));
        }

        $id = trim($id);
        $length = mb_strlen($id);
        if ($length === 0 || $length > self::ENTITY_ID_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('An entity id is 1 to %d characters long, not %d.', self::ENTITY_ID_MAX_LENGTH, $length)
            );
        }

        $copy = clone $this;
        $copy->entityCode = $code;
        $copy->entityId = $id;

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withOperation(string $operation): EntityChangeInterface
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown entity change operation "%s".', $operation));
        }

        $copy = clone $this;
        $copy->operation = $operation;

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withScope(string $type, int $id, string $label): EntityChangeInterface
    {
        $copy = clone $this;
        $copy->scope = $this->scope->withValues($type, $id, $label);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withField(string $field, ?string $before, ?string $after): EntityChangeInterface
    {
        $field = trim($field);
        $length = mb_strlen($field);
        if ($length === 0 || $length > self::FIELD_MAX_LENGTH) {
            throw new \InvalidArgumentException(
                sprintf('A field name is 1 to %d characters long, not %d.', self::FIELD_MAX_LENGTH, $length)
            );
        }

        if (!array_key_exists($field, $this->after) && count($this->after) >= self::MAX_FIELDS) {
            throw new \InvalidArgumentException(sprintf('A change carries at most %d fields.', self::MAX_FIELDS));
        }

        $copy = clone $this;
        $copy->before[$field] = $before;
        $copy->after[$field] = $after;

        return $copy;
    }
}
