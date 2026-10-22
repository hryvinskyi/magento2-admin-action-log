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
 *
 * An entity is named in text by "labelPattern", a sprintf pattern given the label, then the value of each naming
 * field, then the id. With one "labelField" an empty value is replaced by the id; with several "labelFields" an empty
 * value names the entity by its id alone ("Product #1245"), so a pattern never prints a half-filled name.
 *
 * A model without an id is new: it had no state before, whatever original data its own save filled in beforehand (a
 * product notes its websites as original data while it saves). The state before a change is otherwise the model's
 * original data when it has some, else what is stored. "readsStoredState" always reads what is stored: for a model a
 * request may keep and reuse while its row is changed underneath it by other means, whose original data can be out of
 * date. Such a model's state after its change is read from storage as well, once the change committed: the model may
 * hold values a rolled-back transaction never wrote, and a row that is not stored has no state.
 */
class TrackedEntity implements TrackedModelInterface
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
     * @var list<string>
     */
    private readonly array $labelFields;

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
     * @param string $labelPattern sprintf pattern of the entity's name: %1$s the label, then the naming values, then
     *     the id
     * @param array<string> $labelFields Fields naming the entity together, in pattern order; replaces "labelField"
     * @param bool $readsStoredState Whether the state before always comes from storage, never the original data
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
        private readonly string $labelPattern = '%1$s #%2$s',
        array $labelFields = [],
        private readonly bool $readsStoredState = false
    ) {
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException(sprintf('Malformed tracked entity code "%s".', $code));
        }

        if (trim($label) === '' || $modelClass === '') {
            throw new \InvalidArgumentException(sprintf('Tracked entity "%s" needs a label and a model class.', $code));
        }

        $this->ignored = array_fill_keys([...self::ALWAYS_IGNORED, ...$ignoredFields], true);
        $this->only = array_fill_keys($onlyFields, true);
        $this->labelFields = array_values($labelFields);
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
        $id = $model->getId();
        if (!is_scalar($id) || (string) $id === '') {
            return null;
        }

        $original = $this->readsStoredState ? null : $model->getOrigData();
        if (is_array($original) && $original !== []) {
            $state = [];
            foreach ($original as $field => $value) {
                $state[(string) $field] = $value;
            }

            return $state;
        }

        return $this->storage->load($model, (string) $id);
    }

    /**
     * @inheritDoc
     */
    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    /**
     * @inheritDoc
     */
    public function stateAfter(AbstractModel $model): array
    {
        if (!$this->readsStoredState) {
            return $this->dataOf($model);
        }

        $id = $model->getId();

        return is_scalar($id) && (string) $id !== '' ? ($this->storage->load($model, (string) $id) ?? []) : [];
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
        $id = $this->text($model->getId());
        if ($this->labelFields !== []) {
            return $this->describeByFields($model, $id);
        }

        $value = $this->labelField === '' ? $id : $this->text($model->getData($this->labelField));
        if ($value === '') {
            $value = $id;
        }

        return $value !== '' ? sprintf($this->labelPattern, $this->label, $value, $id) : $this->label;
    }

    /**
     * The name from several naming fields, or the label and the id when one of them is empty
     *
     * @param AbstractModel $model
     * @param string $id
     * @return string
     */
    private function describeByFields(AbstractModel $model, string $id): string
    {
        $values = [];
        foreach ($this->labelFields as $field) {
            $value = $this->text($model->getData($field));
            if ($value === '') {
                return $id === '' ? $this->label : sprintf('%1$s #%2$s', $this->label, $id);
            }

            $values[] = $value;
        }

        return sprintf($this->labelPattern, $this->label, ...[...$values, $id]);
    }

    /**
     * The model's data keyed by field name
     *
     * @param AbstractModel $model
     * @return array<string, mixed>
     */
    private function dataOf(AbstractModel $model): array
    {
        $data = [];
        $values = $model->getData();
        foreach (is_array($values) ? $values : [] as $field => $value) {
            $data[(string) $field] = $value;
        }

        return $data;
    }

    /**
     * A model value as text; empty when it is not a scalar
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
