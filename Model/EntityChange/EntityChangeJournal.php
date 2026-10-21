<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\ActivityBuilderInterface;
use Hryvinskyi\ActivityLog\Api\ActivityRecorderInterface;
use Hryvinskyi\ActivityLog\Api\ActorContextInterface;
use Hryvinskyi\ActivityLog\Api\ConfigInterface as ActivityLogConfig;
use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterface;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\Config;
use Hryvinskyi\AdminActionLog\Model\RequestSubjects;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

/**
 * Records entity changes: the state before is taken when a save or delete starts, the change is journaled once its
 * transaction commits
 *
 * A change in which no field changed is not recorded. Secret fields are masked before anything is stored. Recording
 * never breaks the save: a failure to record is logged and the save goes on.
 */
class EntityChangeJournal
{
    private const LABEL_MAX_LENGTH = 255;
    private const ID_MAX_LENGTH = 64;
    private const ELLIPSIS = '…';

    /**
     * @param Config $config
     * @param ActivityLogConfig $activityLogConfig
     * @param TrackedEntityPool $pool
     * @param EntityTypeSettings $settings
     * @param ChangeBuffer $buffer
     * @param ValueNormalizer $normalizer
     * @param FieldDiffer $differ
     * @param ActorContextInterface $actorContext
     * @param ActivityBuilderInterface $activityBuilder
     * @param ActivityRecorderInterface $activityRecorder
     * @param SubjectInterfaceFactory $subjectFactory
     * @param EntityChangeStore $store
     * @param RequestSubjects $requestSubjects
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActivityLogConfig $activityLogConfig,
        private readonly TrackedEntityPool $pool,
        private readonly EntityTypeSettings $settings,
        private readonly ChangeBuffer $buffer,
        private readonly ValueNormalizer $normalizer,
        private readonly FieldDiffer $differ,
        private readonly ActorContextInterface $actorContext,
        private readonly ActivityBuilderInterface $activityBuilder,
        private readonly ActivityRecorderInterface $activityRecorder,
        private readonly SubjectInterfaceFactory $subjectFactory,
        private readonly EntityChangeStore $store,
        private readonly RequestSubjects $requestSubjects,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Take the state of an entity before it is saved
     *
     * @param AbstractModel $model
     * @return void
     */
    public function beforeSave(AbstractModel $model): void
    {
        $this->snapshot($model, false);
    }

    /**
     * Take the state of an entity before it is deleted
     *
     * @param AbstractModel $model
     * @return void
     */
    public function beforeDelete(AbstractModel $model): void
    {
        $this->snapshot($model, true);
    }

    /**
     * Journal the change of an entity whose transaction just committed
     *
     * @param AbstractModel $model
     * @return void
     */
    public function committed(AbstractModel $model): void
    {
        $pending = $this->buffer->take($model);
        if ($pending === null) {
            return;
        }

        $entity = $this->pool->get($pending['code']);
        if ($entity === null || ($pending['operation'] !== ChangeOperation::Delete && !$model->isSaveAllowed())) {
            return;
        }

        try {
            $this->record($entity, $model, $pending['operation'], $pending['before']);
        } catch (\Throwable $exception) {
            $this->logger->error(
                sprintf('The change of %s could not be recorded.', $entity->getCode()),
                ['exception' => $exception]
            );
        }
    }

    /**
     * Take the state of the model, never failing the save
     *
     * @param AbstractModel $model
     * @param bool $deleting
     * @return void
     */
    private function snapshot(AbstractModel $model, bool $deleting): void
    {
        $this->buffer->forget($model);
        try {
            $this->takeState($model, $deleting);
        } catch (\Throwable $exception) {
            $this->logger->error(
                'The state of an entity before its change could not be read.',
                ['exception' => $exception]
            );
        }
    }

    /**
     * Hold the state of a tracked model whose changes are recorded
     *
     * @param AbstractModel $model
     * @param bool $deleting
     * @return void
     */
    private function takeState(AbstractModel $model, bool $deleting): void
    {
        if (!$this->config->isEntityEnabled() || !$this->activityLogConfig->isEnabled()) {
            return;
        }

        $entity = $this->pool->match($model);
        if ($entity === null
            || $this->settings->modeOf($entity->getCode()) === DiffMode::Off
            || !in_array($this->actorContext->current()->getType(), $this->config->getEntityActorTypes(), true)
        ) {
            return;
        }

        $state = $entity->stateBefore($model);
        if ($deleting && $state === null) {
            return;
        }

        $operation = match (true) {
            $deleting => ChangeOperation::Delete,
            $state === null => ChangeOperation::Create,
            default => ChangeOperation::Update,
        };
        $this->buffer->put(
            $model,
            $entity->getCode(),
            $operation,
            $state === null ? [] : $this->comparable($entity, $model, $state)
        );
    }

    /**
     * Journal the change with its fields
     *
     * @param TrackedEntityInterface $entity
     * @param AbstractModel $model
     * @param ChangeOperation $operation
     * @param array<string,string|null> $before
     * @return void
     */
    private function record(
        TrackedEntityInterface $entity,
        AbstractModel $model,
        ChangeOperation $operation,
        array $before
    ): void {
        $after = $operation === ChangeOperation::Delete
            ? []
            : $this->comparable($entity, $model, $this->dataOf($model));
        $changes = $this->differ->diff($before, $after, $operation);
        $changed = array_values(array_filter($changes, static fn (array $change): bool => $change['changed']));
        if ($changed === []) {
            return;
        }

        $code = $entity->getCode();
        $mode = $this->settings->modeOf($code);
        $kept = $mode === DiffMode::Snapshot ? $changes : $changed;
        $entityId = $this->entityId($model);
        $name = $this->clip($entity->describe($model));
        $subject = $this->subject($code, $entityId, $this->withFields($name, array_column($changed, 'field')));

        $activityId = $this->activityRecorder->record(
            $this->activityBuilder->build(
                ChannelCode::ENTITY,
                sprintf('%s %s', $entity->getLabel(), $operation->verb()),
                Outcome::Success,
                $subject
            )
        );
        if ($activityId === null) {
            return;
        }

        $fields = [];
        foreach ($kept as $change) {
            $masked = $entity->isSecretField($model, $change['field']);
            $fields[] = [
                'field' => $change['field'],
                'before' => $masked && $change['before'] !== null ? SanitizerInterface::MASK : $change['before'],
                'after' => $masked && $change['after'] !== null ? SanitizerInterface::MASK : $change['after'],
                'changed' => $change['changed'],
                'masked' => $masked,
            ];
        }

        $this->store->save(
            $activityId,
            $code,
            $entityId,
            $operation,
            $mode,
            count($changes) - count($changed),
            $entity->scope($model),
            $fields
        );
        $this->requestSubjects->add($this->subject($code, $entityId, $name));
    }

    /**
     * The entity's fields as comparable text
     *
     * @param TrackedEntityInterface $entity
     * @param AbstractModel $model
     * @param array<string,mixed> $data
     * @return array<string, string|null>
     */
    private function comparable(TrackedEntityInterface $entity, AbstractModel $model, array $data): array
    {
        $fields = [];
        foreach ($entity->fields($model, $data) as $field => $value) {
            if ($this->normalizer->isComparable($value)) {
                $fields[$field] = $this->normalizer->normalize($value);
            }
        }

        return $fields;
    }

    /**
     * The model data keyed by field name
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
     * The model id as a subject id, or null
     *
     * @param AbstractModel $model
     * @return string|null
     */
    private function entityId(AbstractModel $model): ?string
    {
        $id = $model->getId();

        return is_scalar($id) && (string) $id !== '' ? mb_substr((string) $id, 0, self::ID_MAX_LENGTH) : null;
    }

    /**
     * "Product #1245 · price, special_price", cut to what a subject label holds; a field the name already shows
     * (a setting named by its path) is not repeated
     *
     * @param string $name
     * @param list<string> $fields
     * @return string
     */
    private function withFields(string $name, array $fields): string
    {
        $fields = array_values(array_filter($fields, static fn (string $field): bool => !str_contains($name, $field)));

        return $fields === [] ? $name : $this->clip($name . ' · ' . implode(', ', $fields));
    }

    /**
     * Text cut to what a subject label holds
     *
     * @param string $text
     * @return string
     */
    private function clip(string $text): string
    {
        return mb_strlen($text) <= self::LABEL_MAX_LENGTH
            ? $text
            : mb_substr($text, 0, self::LABEL_MAX_LENGTH - 1) . self::ELLIPSIS;
    }

    /**
     * A subject through its factory
     *
     * @param string $type
     * @param string|null $id
     * @param string $label
     * @return SubjectInterface
     */
    private function subject(string $type, ?string $id, string $label): SubjectInterface
    {
        return $this->subjectFactory->create(['type' => $type, 'label' => $label, 'id' => $id]);
    }
}
