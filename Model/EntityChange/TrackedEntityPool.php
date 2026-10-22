<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\SecretFieldsInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * Entities registered through the "entities" argument, and the models keeping part of their data registered through
 * the "parts" argument, each asked in sortOrder
 *
 * The kinds of entity are the entities alone: a part is recorded as its entity, so it is never listed, configured or
 * purged on its own. An entity has at most one part per model class: a module replacing a part replaces its item, and
 * a second part kept by the same class fails the pool's construction instead of recording beside the first.
 *
 * The pool also holds the one rule telling a secret field, the same for changes of saved models and changes recorded
 * without a model: a field whose name reads as a secret, a field the entity's "secretFields" item names secret from
 * the field alone, or, when the model is at hand, a field the entity calls secret for that model.
 */
class TrackedEntityPool
{
    /**
     * @var array<string, TrackedEntityInterface>
     */
    private array $byCode = [];

    /**
     * @var list<TrackedEntityPartInterface>
     */
    private array $parts = [];

    /**
     * The registered parts' item names by entity code and model class
     *
     * @var array<string, array<string, string>>
     */
    private array $partNames = [];

    /**
     * @var array<string, SecretFieldsInterface>
     */
    private array $secretFields = [];

    /**
     * @param SanitizerInterface $sanitizer
     * @param array<string,TrackedEntityInterface> $entities
     * @param array<string,TrackedEntityPartInterface> $parts
     * @param array<string,SecretFieldsInterface> $secretFields Keyed by tracked entity code
     * @throws \InvalidArgumentException When a part belongs to no registered entity, or repeats another one's class
     */
    public function __construct(
        private readonly SanitizerInterface $sanitizer,
        array $entities = [],
        array $parts = [],
        array $secretFields = []
    ) {
        foreach ($entities as $entity) {
            $this->byCode[$entity->getCode()] = $entity;
        }

        foreach ($parts as $name => $part) {
            $this->addPart((string) $name, $part);
        }

        foreach ($secretFields as $code => $fields) {
            $this->addSecretFields((string) $code, $fields);
        }
    }

    /**
     * The first entity kind the model belongs to, or null when its changes are not tracked
     *
     * @param AbstractModel $model
     * @return TrackedEntityInterface|null
     */
    public function match(AbstractModel $model): ?TrackedEntityInterface
    {
        foreach ($this->byCode as $entity) {
            if ($entity->supports($model)) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * The first part of an entity the model is, or null when it is none
     *
     * @param AbstractModel $model
     * @return TrackedEntityPartInterface|null
     */
    public function matchPart(AbstractModel $model): ?TrackedEntityPartInterface
    {
        foreach ($this->parts as $part) {
            if ($part->supports($model)) {
                return $part;
            }
        }

        return null;
    }

    /**
     * The entity kind with the code, or null
     *
     * @param string $code
     * @return TrackedEntityInterface|null
     */
    public function get(string $code): ?TrackedEntityInterface
    {
        return $this->byCode[$code] ?? null;
    }

    /**
     * Every entity kind
     *
     * @return list<TrackedEntityInterface>
     */
    public function all(): array
    {
        return array_values($this->byCode);
    }

    /**
     * Whether a field of the entity (or of the part of it) is secret and its values must be masked
     *
     * @param TrackedEntityInterface $entity The entity, or the part of it, whose change it is
     * @param string $field
     * @param AbstractModel|null $model The model saved, when the change was made through one
     * @return bool
     */
    public function isSecretField(TrackedEntityInterface $entity, string $field, ?AbstractModel $model = null): bool
    {
        return $this->sanitizer->isSecretField($field)
            || ($this->secretFields[$entity->getCode()] ?? null)?->isSecretField($field) === true
            || ($model !== null && $entity->isSecretField($model, $field));
    }

    /**
     * Register a part; anything else in the argument fails here
     *
     * So does a part of an entity not registered, or a second part of an entity kept by the same model class.
     *
     * @param string $name
     * @param TrackedEntityPartInterface $part
     * @return void
     * @throws \InvalidArgumentException When the part's entity is not registered, or already has a part of its class
     */
    private function addPart(string $name, TrackedEntityPartInterface $part): void
    {
        $code = $part->getCode();
        if (!isset($this->byCode[$code])) {
            throw new \InvalidArgumentException(sprintf(
                'The tracked entity part "%s" belongs to "%s", which is not a tracked entity.',
                $name,
                $code
            ));
        }

        $class = ltrim($part->getModelClass(), '\\');
        $registered = $this->partNames[$code][$class] ?? null;
        if ($registered !== null) {
            throw new \InvalidArgumentException(sprintf(
                'The tracked entity parts "%s" and "%s" both keep "%s" of "%s"; replace the item "%s" instead of '
                . 'adding another.',
                $registered,
                $name,
                $class,
                $code,
                $registered
            ));
        }

        $this->partNames[$code][$class] = $name;
        $this->parts[] = $part;
    }

    /**
     * Register where an entity's secret fields are told; anything else in the argument fails here
     *
     * @param string $code
     * @param SecretFieldsInterface $fields
     * @return void
     */
    private function addSecretFields(string $code, SecretFieldsInterface $fields): void
    {
        $this->secretFields[$code] = $fields;
    }
}
