<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Condition;

use Hryvinskyi\ActivityLog\Api\Data\Search\SearchCriterionInterface;
use Hryvinskyi\ActivityLog\Api\Search\SearchConditionInterface;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldLabelSourceInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Helper as DbHelper;
use Magento\Framework\DB\Select;

/**
 * A plain word searched in what changed: in a changed field's code, in the label an admin sees for it, or in its
 * value before or after
 *
 * The search asks about one word at a time and requires every word, so different words may sit in different fields
 * of one change, or in the journal. The word is matched as typed — case aside, "%" and "_" taken literally. A word
 * of at least LABEL_MIN_CHARS characters also finds the fields whose label contains it, at most LABEL_CODES of them:
 * a shorter word would name nearly every field. Only changed fields count, as for "field:" and "value:": a full
 * snapshot also records fields that kept their value. A masked field is found by its name only, never by its value.
 * No index serves this, so it is residual: it runs only among the newest candidates.
 */
class TextCondition implements SearchConditionInterface
{
    /**
     * Shortest word also looked up among field labels
     */
    public const LABEL_MIN_CHARS = 3;

    /**
     * Most field codes a label lookup adds to the search
     */
    public const LABEL_CODES = 50;

    private const ALIAS = 'texted';

    /**
     * @param ResourceConnection $resourceConnection
     * @param DbHelper $dbHelper
     * @param FieldLabelSourceInterface $labels
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly DbHelper $dbHelper,
        private readonly FieldLabelSourceInterface $labels
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKey(): string
    {
        return SearchCriterionInterface::TEXT_KEY;
    }

    /**
     * @inheritDoc
     */
    public function isResidual(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function activityIds(string $value): ?Select
    {
        $word = trim($value);
        if ($word === '') {
            return null;
        }

        $connection = $this->resourceConnection->getConnection();
        $like = $this->dbHelper->escapeLikeValue($word, ['position' => 'any']);
        $column = static fn (string $name): string => self::ALIAS . '.' . $name;

        $matches = [$connection->quoteInto($column('field') . ' LIKE ?', $like)];
        $labelled = mb_strlen($word) >= self::LABEL_MIN_CHARS
            ? array_slice($this->labels->codesLabelledWith($word), 0, self::LABEL_CODES)
            : [];
        if ($labelled !== []) {
            $matches[] = $connection->quoteInto($column('field') . ' IN (?)', $labelled);
        }
        $matches[] = '(' . $column('is_masked') . ' = 0 AND ('
            . $connection->quoteInto($column('before_value') . ' LIKE ?', $like) . ' OR '
            . $connection->quoteInto($column('after_value') . ' LIKE ?', $like) . '))';

        return $connection->select()
            ->from(
                [self::ALIAS => $this->resourceConnection->getTableName(EntityChangeStore::FIELD_TABLE)],
                ['activity_id']
            )
            ->where($column('is_changed') . ' = ?', 1)
            ->where(implode(' OR ', $matches), null, Select::TYPE_CONDITION);
    }
}
