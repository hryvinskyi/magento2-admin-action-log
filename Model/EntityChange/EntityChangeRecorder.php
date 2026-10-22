<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\StoredRecordsInterface;
use Psr\Log\LoggerInterface;

/**
 * Records changes made without a model through the rules and the writer changes of saved models go through
 *
 * "records" names, per tracked entity code, where the fields naming its records are read: the entity is then named as
 * its own changes name it. The records named by the changes of one call are read together, and only when a change is
 * written. An entity without one, or whose record is gone, is named by its label and id. A field is secret by the
 * tracked entity pool's rule, told from the field alone, so no record is read to mask a value.
 *
 * Each change is written at once, through the default connection, so a change made inside a transaction is recorded
 * inside it and leaves no record when that transaction rolls back. Each change is recorded on its own: one that fails
 * is logged and the others are still recorded.
 */
class EntityChangeRecorder implements EntityChangeRecorderInterface
{
    /**
     * @var array<string, StoredRecordsInterface>
     */
    private array $records = [];

    /**
     * @param TrackedEntityPool $pool
     * @param RecordingRules $rules
     * @param ChangeWriter $writer
     * @param LoggerInterface $logger
     * @param array<string,StoredRecordsInterface> $records Keyed by tracked entity code
     */
    public function __construct(
        private readonly TrackedEntityPool $pool,
        private readonly RecordingRules $rules,
        private readonly ChangeWriter $writer,
        private readonly LoggerInterface $logger,
        array $records = []
    ) {
        foreach ($records as $code => $source) {
            $this->addRecords((string) $code, $source);
        }
    }

    /**
     * @inheritDoc
     */
    public function record(EntityChangeInterface $change): void
    {
        $this->recordAll([$change]);
    }

    /**
     * @inheritDoc
     */
    public function recordAll(array $changes): void
    {
        $ids = [];
        foreach ($changes as $change) {
            $ids[$change->getEntityCode()][] = $change->getEntityId();
        }

        foreach ($changes as $change) {
            try {
                $this->write($change, $ids[$change->getEntityCode()] ?? []);
            } catch (\Throwable $exception) {
                $this->logger->error(
                    sprintf('The change of %s could not be recorded.', $change->getEntityCode()),
                    ['exception' => $exception]
                );
            }
        }
    }

    /**
     * Write the change of a tracked entity when the rules record it
     *
     * @param EntityChangeInterface $change
     * @param list<string> $named The ids every change of the call names for the change's entity, read together
     * @return void
     */
    private function write(EntityChangeInterface $change, array $named): void
    {
        $code = $change->getEntityCode();
        $entity = $code === '' ? null : $this->pool->get($code);
        if ($entity === null || !$this->rules->records($code)) {
            return;
        }

        $id = $change->getEntityId();
        $records = $this->records[$code] ?? null;
        $this->writer->write(
            $entity,
            $id,
            ChangeOperation::from($change->getOperation()),
            $change->getBefore(),
            $change->getAfter(),
            static function () use ($entity, $id, $records, $named): string {
                $found = $records?->records($named)[$id] ?? null;

                return $found === null ? sprintf('%s #%s', $entity->getLabel(), $id) : $entity->describe($found);
            },
            $change->getScope(),
            fn (string $field): bool => $this->pool->isSecretField($entity, $field)
        );
    }

    /**
     * Register where an entity's records are read; anything else in the argument fails here, at construction
     *
     * @param string $code
     * @param StoredRecordsInterface $records
     * @return void
     */
    private function addRecords(string $code, StoredRecordsInterface $records): void
    {
        $this->records[$code] = $records;
    }
}
