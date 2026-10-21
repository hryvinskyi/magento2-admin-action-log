<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\ViewModel;

use Hryvinskyi\ActivityLog\Api\ActivityRepositoryInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The entity change panel of the activity detail page: fields grouped by the scope they were saved in
 *
 * @phpstan-type FieldView array{
 *     field: string,
 *     before: string|null,
 *     after: string|null,
 *     changed: bool,
 *     masked: bool,
 *     long: bool
 * }
 * @phpstan-type ScopeGroup array{label: string, type: string, id: int, fields: list<FieldView>}
 * @phpstan-type ChangeView array{
 *     subject: string,
 *     operation: ChangeOperation,
 *     snapshot: bool,
 *     changedCount: int,
 *     unchangedCount: int,
 *     groups: list<ScopeGroup>
 * }
 */
class EntityChangePanel implements ArgumentInterface
{
    private const LONG_LENGTH = 160;
    private const LONG_LINES = 3;

    /**
     * @param RequestInterface $request
     * @param EntityChangeStore $store
     * @param ActivityRepositoryInterface $activityRepository
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly EntityChangeStore $store,
        private readonly ActivityRepositoryInterface $activityRepository
    ) {
    }

    /**
     * The change of the activity on the page, or null when none was stored
     *
     * @return ChangeView|null
     */
    public function change(): ?array
    {
        $id = $this->request->getParam('id');
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }

        $change = $this->store->find((int) $id);
        if ($change === null) {
            return null;
        }

        $groups = [];
        $changedCount = 0;
        foreach ($change['fields'] as $row) {
            $key = $row['scopeType'] . ':' . $row['scopeId'];
            $groups[$key] ??= [
                'label' => $row['scopeLabel'],
                'type' => $row['scopeType'],
                'id' => $row['scopeId'],
                'fields' => [],
            ];
            $groups[$key]['fields'][] = [
                'field' => $row['field'],
                'before' => $row['before'],
                'after' => $row['after'],
                'changed' => $row['changed'],
                'masked' => $row['masked'],
                'long' => !$row['masked'] && ($this->isLong($row['before']) || $this->isLong($row['after'])),
            ];
            $changedCount += $row['changed'] ? 1 : 0;
        }

        $storedUnchanged = count($change['fields']) - $changedCount;

        return [
            'subject' => $this->subjectOf((int) $id),
            'operation' => $change['operation'],
            'snapshot' => $change['mode'] === DiffMode::Snapshot,
            'changedCount' => $changedCount,
            'unchangedCount' => max($change['unchangedCount'], $storedUnchanged),
            'groups' => array_values($groups),
        ];
    }

    /**
     * Whether a value is long enough to be clipped until expanded
     *
     * @param string|null $value
     * @return bool
     */
    private function isLong(?string $value): bool
    {
        return $value !== null
            && (mb_strlen($value) > self::LONG_LENGTH || substr_count($value, "\n") >= self::LONG_LINES);
    }

    /**
     * Label of what the activity changed
     *
     * @param int $activityId
     * @return string
     */
    private function subjectOf(int $activityId): string
    {
        try {
            return $this->activityRepository->get($activityId)->getSubject()?->getLabel() ?? '';
        } catch (NoSuchEntityException) {
            return '';
        }
    }
}
