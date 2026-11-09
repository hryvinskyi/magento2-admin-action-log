<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\ViewModel;

use Hryvinskyi\ActivityLog\Api\ActivityRepositoryInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\ValueKind;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueKinds;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\SubjectLinks;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\JsonHexTag;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The entity change panel of the activity detail page: fields grouped by the scope they were saved in, those set
 * automatically marked and listed after those asked for, and the link to the changed record's page when the admin
 * may open it
 *
 * A changed value of the kind long (ValueKinds: long text, neither masked nor a JSON list; the timeline and the
 * settings history follow the same rule, except that a value the search cut is long text to them) is shown as a split
 * diff by the page's script. The script takes both values from a
 * JSON block, which carries each value exactly as it was stored; the cells' text does not, once the browser's HTML
 * parser has read it. Any long value, a JSON list included, is still clipped in its cells until expanded.
 *
 * @phpstan-type FieldView array{
 *     field: string,
 *     before: string|null,
 *     after: string|null,
 *     changed: bool,
 *     masked: bool,
 *     automatic: bool,
 *     long: bool,
 *     split: bool,
 *     splitValues: string
 * }
 * @phpstan-type ScopeGroup array{label: string, type: string, id: int, fields: list<FieldView>}
 * @phpstan-type ChangeView array{
 *     subject: string,
 *     link: string|null,
 *     operation: ChangeOperation,
 *     snapshot: bool,
 *     changedCount: int,
 *     unchangedCount: int,
 *     groups: list<ScopeGroup>
 * }
 */
class EntityChangePanel implements ArgumentInterface
{
    /**
     * @param RequestInterface $request
     * @param EntityChangeStore $store
     * @param ActivityRepositoryInterface $activityRepository
     * @param ValueKinds $valueKinds
     * @param SubjectLinks $subjectLinks
     * @param JsonHexTag $jsonHexTag
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly EntityChangeStore $store,
        private readonly ActivityRepositoryInterface $activityRepository,
        private readonly ValueKinds $valueKinds,
        private readonly SubjectLinks $subjectLinks,
        private readonly JsonHexTag $jsonHexTag
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
            $long = !$row['masked'] && $this->valueKinds->isLong($row['before'], $row['after']);
            $longKind = $this->valueKinds->kindOf($row['before'], $row['after'], $row['masked']) === ValueKind::Long;
            $splitValues = $row['changed'] && $longKind ? $this->splitValues($row['before'], $row['after']) : '';
            $groups[$key]['fields'][] = [
                'field' => $row['field'],
                'before' => $row['before'],
                'after' => $row['after'],
                'changed' => $row['changed'],
                'masked' => $row['masked'],
                'automatic' => $row['automatic'],
                'long' => $long,
                'split' => $splitValues !== '',
                'splitValues' => $splitValues,
            ];
            $changedCount += $row['changed'] ? 1 : 0;
        }

        $storedUnchanged = count($change['fields']) - $changedCount;

        return [
            'subject' => $this->subjectOf((int) $id),
            'link' => $this->subjectLinks->linksFor([
                0 => ['type' => $change['entityType'], 'id' => $change['entityId']],
            ])[0] ?? null,
            'operation' => $change['operation'],
            'snapshot' => $change['mode'] === DiffMode::Snapshot,
            'changedCount' => $changedCount,
            'unchangedCount' => max($change['unchangedCount'], $storedUnchanged),
            'groups' => array_values($groups),
        ];
    }

    /**
     * Both values of a field shown as a split diff, as JSON safe to print inside a script element
     *
     * A missing value is the empty text. A value JSON cannot hold (text that is not valid UTF-8) gives an empty string,
     * and the field keeps the server's drawing instead of failing the page.
     *
     * @param string|null $before
     * @param string|null $after
     * @return string
     */
    private function splitValues(?string $before, ?string $after): string
    {
        try {
            return (string) $this->jsonHexTag->serialize(['before' => $before ?? '', 'after' => $after ?? '']);
        } catch (\InvalidArgumentException) {
            return '';
        }
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
