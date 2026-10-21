<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldSource\FieldSourceInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Scope\ScopeResolverInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\EntityStorageInterface;
use Magento\Framework\Model\AbstractModel;

/**
 * A tracked entity described in di.xml
 *
 * A module declares a virtual type of this class with the model class, a storage over the entity's resource model,
 * a scope resolver and a label. Timestamps the database keeps by itself are never compared: the journal has its own
 * time. "onlyFields" narrows an entity to the fields that matter (an order's status, not its totals).
 */
class TrackedEntity implements TrackedEntityInterface
{
    private const ALWAYS_IGNORED = ['created_at', 'updated_at', 'creation_time', 'update_time', 'form_key'];
    private const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /**
     * @var array<string, true>
     */
    private readonly array $ignored;

    /**
     * @var array<string, true>
     */
    private readonly array $only;

    /**
     * @param EntityStorageInterface $storage
     * @param ScopeResolverInterface $scopeResolver
     * @param FieldSourceInterface $fieldSource
     * @param SanitizerInterface $sanitizer
     * @param string $code
     * @param string $label
     * @param string $modelClass
     * @param array<string> $ignoredFields
     * @param array<string> $onlyFields
     * @param string $labelField Field naming the entity in text; the id when empty
     * @param string $labelPattern sprintf pattern of the entity's name: %1$s the label, %2$s the naming value
     * @throws \InvalidArgumentException When the declaration is malformed
     */
    public function __construct(
        private readonly EntityStorageInterface $storage,
        private readonly ScopeResolverInterface $scopeResolver,
        private readonly FieldSourceInterface $fieldSource,
        private readonly SanitizerInterface $sanitizer,
        private readonly string $code,
        private readonly string $label,
        private readonly string $modelClass,
        array $ignoredFields = [],
        array $onlyFields = [],
        private readonly string $labelField = '',
        private readonly string $labelPattern = '%1$s #%2$s'
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(sprintf('Malformed tracked entity code "%s".', $code));
        }

        if (trim($label) === '' || $modelClass === '') {
            throw new \InvalidArgumentException(sprintf('Tracked entity "%s" needs a label and a model class.', $code));
        }

        $this->ignored = array_fill_keys([...self::ALWAYS_IGNORED, ...$ignoredFields], true);
        $this->only = array_fill_keys($onlyFields, true);
    }

    /**
     * @inheritDoc
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritDoc
     */
    public function supports(AbstractModel $model): bool
    {
        return $model instanceof $this->modelClass;
    }

    /**
     * @inheritDoc
     */
    public function stateBefore(AbstractModel $model): ?array
    {
        $original = $model->getOrigData();
        if (is_array($original) && $original !== []) {
            $state = [];
            foreach ($original as $field => $value) {
                $state[(string) $field] = $value;
            }

            return $state;
        }

        $id = $model->getId();
        if (!is_scalar($id) || (string) $id === '') {
            return null;
        }

        return $this->storage->load($model, (string) $id);
    }

    /**
     * @inheritDoc
     */
    public function fields(AbstractModel $model, array $data): array
    {
        $fields = [];
        foreach ($this->fieldSource->read($this->storage, $model, $data) as $field => $value) {
            if (isset($this->ignored[$field]) || ($this->only !== [] && !isset($this->only[$field]))) {
                continue;
            }

            $fields[$field] = $value;
        }

        return $fields;
    }

    /**
     * @inheritDoc
     */
    public function scope(AbstractModel $model): EntityScopeInterface
    {
        return $this->scopeResolver->resolve($model);
    }

    /**
     * @inheritDoc
     */
    public function isSecretField(AbstractModel $model, string $field): bool
    {
        return $this->sanitizer->isSecretField($field) || $this->fieldSource->isSecret($model, $field);
    }

    /**
     * @inheritDoc
     */
    public function describe(AbstractModel $model): string
    {
        $value = $this->labelField === '' ? $model->getId() : $model->getData($this->labelField);
        if (!is_scalar($value) || (string) $value === '') {
            $value = $model->getId();
        }

        return is_scalar($value) && (string) $value !== ''
            ? sprintf($this->labelPattern, $this->label, (string) $value)
            : $this->label;
    }
}
