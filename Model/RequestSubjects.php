<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model;

use Hryvinskyi\ActivityLog\Api\Data\SubjectInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The entities the current request changed, in the order their changes were recorded
 *
 * An admin request is recorded after its response is ready, when the entities it saved are known: the first of them
 * is what the request acted on.
 */
class RequestSubjects implements ResetAfterRequestInterface
{
    /**
     * @var SubjectInterface|null
     */
    private ?SubjectInterface $first = null;

    /**
     * Remember an entity the request changed
     *
     * @param SubjectInterface $subject
     * @return void
     */
    public function add(SubjectInterface $subject): void
    {
        $this->first ??= $subject;
    }

    /**
     * The first entity the request changed, or null
     *
     * @return SubjectInterface|null
     */
    public function first(): ?SubjectInterface
    {
        return $this->first;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->first = null;
    }
}
