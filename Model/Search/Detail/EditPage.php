<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Detail;

use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordPresenceInterface;

/**
 * The admin page that edits one kind of tracked record: its route, the request parameter carrying the record's id,
 * the ACL resource an admin needs to open it, and where to check that a record still exists
 *
 * Declared in di.xml as a virtual type per kind and added to SubjectLinks' "editPages" under the kind's code.
 */
class EditPage implements RecordPageInterface
{
    private const ROUTE_PATTERN = '#^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*/[a-z][a-zA-Z0-9_]*$#';
    private const PARAM_PATTERN = '/^[a-z][a-z0-9_]*$/';
    private const ACL_PATTERN = '/^[A-Z][A-Za-z0-9]*_[A-Z][A-Za-z0-9]*::[a-z][a-z0-9_]*$/';

    /**
     * @param RecordPresenceInterface $records
     * @param string $route Admin route path: front name, controller, action
     * @param string $idParam
     * @param string $aclResource
     * @throws \InvalidArgumentException When the declaration is malformed
     */
    public function __construct(
        private readonly RecordPresenceInterface $records,
        private readonly string $route,
        private readonly string $idParam,
        private readonly string $aclResource
    ) {
        if (preg_match(self::ROUTE_PATTERN, $route) !== 1) {
            throw new \InvalidArgumentException(sprintf('Malformed edit page route "%s".', $route));
        }

        if (preg_match(self::PARAM_PATTERN, $idParam) !== 1) {
            throw new \InvalidArgumentException(sprintf('Malformed id parameter "%s" of %s.', $idParam, $route));
        }

        if (preg_match(self::ACL_PATTERN, $aclResource) !== 1) {
            throw new \InvalidArgumentException(sprintf('Malformed ACL resource "%s" of %s.', $aclResource, $route));
        }
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return $this->aclResource;
    }

    /**
     * @inheritDoc
     */
    public function getRoute(): string
    {
        return $this->route;
    }

    /**
     * @inheritDoc
     */
    public function paramsOf(array $ids): array
    {
        $params = [];
        foreach ($this->records->existing($ids) as $id) {
            $params[$id] = [$this->idParam => $id];
        }

        return $params;
    }
}
