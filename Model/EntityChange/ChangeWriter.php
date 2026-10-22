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
use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterface;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic\AutomaticFields;
use Hryvinskyi\AdminActionLog\Model\RequestSubjects;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;

/**
 * Writes one entity change into the journal and the change tables, the one place both changes of saved models and
 * changes recorded through the recorder are written
 *
 * The fields are compared first; a change in which no field changed is not written. The entity's mode decides
 * whether the unchanged fields are kept too. Secret fields are masked before anything is stored. The journal's
 * subject is the entity's name followed by the changed fields; the request remembers the entity as one it changed.
 * The name is asked for only when the change is written: naming a part's entity may read its record.
 *
 * Each changed field is marked when it was set automatically: changed while an admin saved the record's form although
 * the form did not ask for it (AutomaticFields). Secret fields are never marked. The mark is kept with the field; the
 * change and the journal are written the same either way.
 */
class ChangeWriter
{
    private const LABEL_MAX_LENGTH = 255;
    private const ID_MAX_LENGTH = 64;
    private const ELLIPSIS = '…';

    /**
     * @param EntityTypeSettings $settings
     * @param FieldDiffer $differ
     * @param ActivityBuilderInterface $activityBuilder
     * @param ActivityRecorderInterface $activityRecorder
     * @param SubjectInterfaceFactory $subjectFactory
     * @param EntityChangeStore $store
     * @param RequestSubjects $requestSubjects
     * @param AutomaticFields $automaticFields
     */
    public function __construct(
        private readonly EntityTypeSettings $settings,
        private readonly FieldDiffer $differ,
        private readonly ActivityBuilderInterface $activityBuilder,
        private readonly ActivityRecorderInterface $activityRecorder,
        private readonly SubjectInterfaceFactory $subjectFactory,
        private readonly EntityChangeStore $store,
        private readonly RequestSubjects $requestSubjects,
        private readonly AutomaticFields $automaticFields
    ) {
    }

    /**
     * Write the change, or nothing when no field changed
     *
     * @param TrackedEntityInterface $entity The entity whose change it is
     * @param string|null $entityId Id the change is recorded under
     * @param ChangeOperation $operation
     * @param array<string,string|null> $before The fields' values before, as comparable text
     * @param array<string,string|null> $after The fields' values after, as comparable text
     * @param callable $name Gives the entity's name: "Product 24-MB01 · Joust Duffle Bag"
     * @phpstan-param callable(): string $name
     * @param EntityScopeInterface $scope Where in the store the change applies
     * @param callable $isSecret Takes a field name, tells whether its values are masked
     * @phpstan-param callable(string): bool $isSecret
     * @return void
     */
    public function write(
        TrackedEntityInterface $entity,
        ?string $entityId,
        ChangeOperation $operation,
        array $before,
        array $after,
        callable $name,
        EntityScopeInterface $scope,
        callable $isSecret
    ): void {
        $code = $entity->getCode();
        $changes = $this->differ->diff($code, $before, $after, $operation);
        $changed = array_values(array_filter($changes, static fn (array $change): bool => $change['changed']));
        if ($changed === []) {
            return;
        }

        $mode = $this->settings->modeOf($code);
        $kept = $mode === DiffMode::Snapshot ? $changes : $changed;
        $entityId = $entityId === null || $entityId === '' ? null : mb_substr($entityId, 0, self::ID_MAX_LENGTH);
        $name = $this->clip($name());

        $activityId = $this->activityRecorder->record(
            $this->activityBuilder->build(
                ChannelCode::ENTITY,
                sprintf('%s %s', $entity->getLabel(), $operation->verb()),
                Outcome::Success,
                $this->subject($code, $entityId, $this->withFields($name, array_column($changed, 'field')))
            )
        );
        if ($activityId === null) {
            return;
        }

        $automatic = array_flip($this->automaticFields->of(
            $code,
            $entityId,
            array_values(array_filter($changed, static fn (array $change): bool => !$isSecret($change['field']))),
            $before
        ));
        $fields = [];
        foreach ($kept as $change) {
            $masked = $isSecret($change['field']);
            $fields[] = [
                'field' => $change['field'],
                'before' => $masked && $change['before'] !== null ? SanitizerInterface::MASK : $change['before'],
                'after' => $masked && $change['after'] !== null ? SanitizerInterface::MASK : $change['after'],
                'changed' => $change['changed'],
                'masked' => $masked,
                'automatic' => $change['changed'] && !$masked && isset($automatic[$change['field']]),
            ];
        }

        $this->store->save(
            $activityId,
            $code,
            $entityId,
            $operation,
            $mode,
            count($changes) - count($changed),
            $scope,
            $fields
        );
        $this->requestSubjects->add($this->subject($code, $entityId, $name));
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
