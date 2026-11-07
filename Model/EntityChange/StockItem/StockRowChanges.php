<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\StockItem;

use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityPartInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\RecordingRules;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueNormalizer;
use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\AbstractModel;
use Psr\Log\LoggerInterface;

/**
 * Records how a write of many products' stock item rows, made past the stock item model, changed each product
 *
 * The rows are read before the write and again after it, one query each way. A quantity correction is read once,
 * after it: its rows before are told from those rows and the correction's own quantities (CorrectedRows). Every row
 * that differs is recorded through the part of the tracked entity pool a stock item is: a stock item built from the
 * row tells the part's fields, the product's id, the scope and the entity the change belongs to, so the write is
 * recorded exactly as a saved stock item would be, by whichever part the pool holds. A part that leaves fields out
 * (because another source records them) leaves them out here too, and a change is recorded once. Before the fields of
 * the changed rows are told, the pool's read-aheads of the part's entity read what they need for all those products
 * in one go.
 *
 * A row that did not exist before is set from nothing: its product existed, it only had no stock row yet. A row gone
 * after the write is not recorded. Nothing is read when the pool tracks no stock item part or its changes are not
 * recorded now. Reading and recording never fail the write: a failure is logged.
 */
class StockRowChanges
{
    /**
     * @param TrackedEntityPool $pool
     * @param RecordingRules $rules
     * @param StockRows $rows
     * @param CorrectedRows $correctedRows
     * @param StockItemInterfaceFactory $stockItems
     * @param ValueNormalizer $normalizer
     * @param EntityChangeInterfaceFactory $changeFactory
     * @param EntityChangeRecorderInterface $recorder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly TrackedEntityPool $pool,
        private readonly RecordingRules $rules,
        private readonly StockRows $rows,
        private readonly CorrectedRows $correctedRows,
        private readonly StockItemInterfaceFactory $stockItems,
        private readonly ValueNormalizer $normalizer,
        private readonly EntityChangeInterfaceFactory $changeFactory,
        private readonly EntityChangeRecorderInterface $recorder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether stock item changes are recorded now
     *
     * @return bool
     */
    public function isRecorded(): bool
    {
        return $this->recordedPart() !== null;
    }

    /**
     * The stored stock item rows of the products, or null when nothing is to be recorded
     *
     * Null also when the rows cannot be read: a change whose state on either side is unknown is not recorded.
     *
     * @param list<int> $productIds
     * @param AdapterInterface|null $connection The connection the write goes through, when the rows must be read
     *     inside its transaction; the stock item resource's own when null
     * @return array<string,array<string,mixed>>|null
     */
    public function rows(array $productIds, ?AdapterInterface $connection = null): ?array
    {
        if ($productIds === []) {
            return null;
        }

        try {
            return $this->recordedPart() === null ? null : $this->rows->read($productIds, $connection);
        } catch (\Throwable $exception) {
            $this->logger->error('The stock of products could not be read.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * Record how the write changed the products' stock item rows read before it
     *
     * @param list<int> $productIds
     * @param array<string,array<string,mixed>> $before What rows() returned for the same products before the write
     * @return void
     */
    public function recordSince(array $productIds, array $before): void
    {
        try {
            $part = $this->recordedPart();
            if ($part === null) {
                return;
            }

            $this->recorder->recordAll($this->changes($part, $before, $this->rows->read($productIds)));
        } catch (\Throwable $exception) {
            $this->logException($exception);
        }
    }

    /**
     * Record how a quantity correction changed the products' stock item rows, from the rows it left
     *
     * @param array<string,array<string,mixed>> $after What rows() returned for the corrected products after it
     * @param array<int,float|int|string> $quantities The correction's quantities, keyed by product id
     * @param int $websiteId The website whose rows the correction moved
     * @param string $operator `-` for a quantity taken, `+` for a quantity put back
     * @return void
     */
    public function recordCorrection(array $after, array $quantities, int $websiteId, string $operator): void
    {
        try {
            $part = $this->recordedPart();
            $before = $part === null ? null : $this->correctedRows->before($after, $quantities, $websiteId, $operator);
            if ($part === null || $before === null) {
                return;
            }

            $this->recorder->recordAll($this->changes($part, $before, $after));
        } catch (\Throwable $exception) {
            $this->logException($exception);
        }
    }

    /**
     * Log a change of products' stock that could not be recorded
     *
     * @param \Throwable $exception
     * @return void
     */
    private function logException(\Throwable $exception): void
    {
        $this->logger->error('The change of products\' stock could not be recorded.', [
            'exception' => $exception,
        ]);
    }

    /**
     * A change of the part's entity for each row the write changed
     *
     * @param TrackedEntityPartInterface $part
     * @param array<string,array<string,mixed>> $before
     * @param array<string,array<string,mixed>> $after
     * @return list<EntityChangeInterface>
     */
    private function changes(TrackedEntityPartInterface $part, array $before, array $after): array
    {
        $models = [];
        $entityIds = [];
        foreach ($after as $key => $row) {
            $model = ($before[$key] ?? null) === $row ? null : $this->stockItem($row);
            $entityId = $model === null ? null : $part->entityId($model);
            if ($model !== null && $entityId !== null) {
                $models[$key] = $model;
                $entityIds[$entityId] = $entityId;
            }
        }

        $this->pool->readAhead($part->getCode(), array_values($entityIds));

        $changes = [];
        foreach ($models as $key => $model) {
            $changes[] = $this->change($part, $model, $before[$key] ?? null, $after[$key]);
        }

        return $changes;
    }

    /**
     * The change of the part's entity a row's write made
     *
     * @param TrackedEntityPartInterface $part
     * @param AbstractModel $model The stock item as written
     * @param array<string,mixed>|null $before The row before the write; null when there was none
     * @param array<string,mixed> $after
     * @return EntityChangeInterface
     */
    private function change(
        TrackedEntityPartInterface $part,
        AbstractModel $model,
        ?array $before,
        array $after
    ): EntityChangeInterface {
        $scope = $part->scope($model);
        $change = $this->changeFactory->create()
            ->withEntity($part->getCode(), (string) $part->entityId($model))
            ->withScope($scope->getType(), $scope->getId(), $scope->getLabel());

        $was = $before === null ? [] : $this->comparable($part, $model, $before);
        $now = $this->comparable($part, $model, $after);
        foreach (array_keys($now + $was) as $field) {
            $change = $change->withField((string) $field, $was[$field] ?? null, $now[$field] ?? null);
        }

        return $change;
    }

    /**
     * The part's fields of a row as comparable text
     *
     * @param TrackedEntityPartInterface $part
     * @param AbstractModel $model
     * @param array<string,mixed> $row
     * @return array<string,string|null>
     */
    private function comparable(TrackedEntityPartInterface $part, AbstractModel $model, array $row): array
    {
        $fields = [];
        foreach ($part->fields($model, $row) as $field => $value) {
            if ($this->normalizer->isComparable($value)) {
                $fields[$field] = $this->normalizer->normalize($value);
            }
        }

        return $fields;
    }

    /**
     * The part a stock item is, when its entity's changes are recorded now
     *
     * @return TrackedEntityPartInterface|null
     */
    private function recordedPart(): ?TrackedEntityPartInterface
    {
        if (!$this->rules->isOn()) {
            return null;
        }

        $probe = $this->stockItem([]);
        $part = $probe === null ? null : $this->pool->matchPart($probe);

        return $part !== null && $this->rules->records($part->getCode()) ? $part : null;
    }

    /**
     * A stock item holding the row, or null when the stock item is not a model
     *
     * @param array<string,mixed> $row
     * @return AbstractModel|null
     */
    private function stockItem(array $row): ?AbstractModel
    {
        $item = $this->stockItems->create();
        if (!$item instanceof AbstractModel) {
            return null;
        }

        $item->setData($row);

        return $item;
    }
}
