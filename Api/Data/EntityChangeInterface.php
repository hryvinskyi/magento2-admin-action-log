<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Api\Data;

/**
 * A change of a tracked entity to record: which entity, what happened to it, where in the store, and each field's
 * value before and after
 *
 * Immutable and valid by construction. Create one through EntityChangeInterfaceFactory with no arguments, then set
 * each part with its with…() method, each of which returns a valid copy. The values are never factory arguments:
 * Magento's object manager reads an array handed to a factory as DI configuration, and the values come from the
 * store's data. A change straight from the factory is blank (no entity, an update, the global scope, no fields), and
 * the recorder records nothing for a blank change.
 *
 * Values are the text the change stores: a decimal as its digits, a Yes/No as "1" or "0", no value as null. Before
 * values are ignored for a creation, after values for a deletion. A field set from nothing on an entity that exists
 * is an update whose before value is null.
 *
 * @api
 * @since 1.1.0
 */
interface EntityChangeInterface
{
    public const OPERATION_CREATE = 'create';
    public const OPERATION_UPDATE = 'update';
    public const OPERATION_DELETE = 'delete';

    /**
     * What an entity code looks like: the code of a tracked entity
     */
    public const ENTITY_CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/D';

    /**
     * Longest entity id, in characters, after trimming
     */
    public const ENTITY_ID_MAX_LENGTH = 64;

    /**
     * Longest field name, in characters, after trimming
     */
    public const FIELD_MAX_LENGTH = 255;

    /**
     * Most fields one change carries
     */
    public const MAX_FIELDS = 500;

    /**
     * Code of the tracked entity, such as "product"; empty only on a blank change
     *
     * @return string
     */
    public function getEntityCode(): string;

    /**
     * Id of the entity, trimmed; empty only on a blank change
     *
     * @return string
     */
    public function getEntityId(): string;

    /**
     * One of the OPERATION_* constants
     *
     * @return string
     */
    public function getOperation(): string;

    /**
     * Where in the store the change applies
     *
     * @return EntityScopeInterface
     */
    public function getScope(): EntityScopeInterface;

    /**
     * The fields' names, in the order they were given
     *
     * @return list<string>
     */
    public function getFields(): array;

    /**
     * Each field's value before the change, by field name
     *
     * @return array<string, string|null>
     */
    public function getBefore(): array;

    /**
     * Each field's value after the change, by field name
     *
     * @return array<string, string|null>
     */
    public function getAfter(): array;

    /**
     * A copy about this entity; this change is left as it is
     *
     * @param string $code Matches ENTITY_CODE_PATTERN
     * @param string $id Trimmed, then 1 to ENTITY_ID_MAX_LENGTH characters
     * @return EntityChangeInterface
     * @throws \InvalidArgumentException When the code is malformed or the trimmed id is empty or too long
     */
    public function withEntity(string $code, string $id): EntityChangeInterface;

    /**
     * A copy with this operation; this change is left as it is
     *
     * @param string $operation One of the OPERATION_* constants
     * @return EntityChangeInterface
     * @throws \InvalidArgumentException When the operation is unknown
     */
    public function withOperation(string $operation): EntityChangeInterface;

    /**
     * A copy applying to this scope; this change is left as it is
     *
     * @param string $type One of EntityScopeInterface's TYPE_* constants
     * @param int $id Website or store id; 0 for a scope without one
     * @param string $label Name shown to people, 1 to 255 characters
     * @return EntityChangeInterface
     * @throws \InvalidArgumentException When a value breaks the rules of a scope
     */
    public function withScope(string $type, int $id, string $label): EntityChangeInterface;

    /**
     * A copy with this field's values; a field given again keeps its place and takes the new values
     *
     * @param string $field Trimmed, then 1 to FIELD_MAX_LENGTH characters
     * @param string|null $before
     * @param string|null $after
     * @return EntityChangeInterface
     * @throws \InvalidArgumentException When the trimmed name is empty or too long, or the change has MAX_FIELDS
     *     fields already
     */
    public function withField(string $field, ?string $before, ?string $after): EntityChangeInterface;
}
