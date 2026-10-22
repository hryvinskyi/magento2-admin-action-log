<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Detail;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * The Stores › Configuration section a stored setting belongs to, opened in the setting's scope
 *
 * A setting is a row of the configuration table: its path names the section ("web" of "web/secure/base_url"), its
 * scope the website or store view the page switches to. The rows are read in one query for a page of settings; a row
 * removed since (the setting went back to its inherited value) opens nothing.
 */
class ConfigSectionPage implements RecordPageInterface
{
    private const ROUTE = 'adminhtml/system_config/edit';
    private const ACL_RESOURCE = 'Magento_Config::config';
    private const SCOPE_PARAMS = ['websites' => 'website', 'stores' => 'store'];

    /**
     * @param AbstractDb $resource The configuration table's resource model
     */
    public function __construct(
        private readonly AbstractDb $resource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getAclResource(): string
    {
        return self::ACL_RESOURCE;
    }

    /**
     * @inheritDoc
     */
    public function getRoute(): string
    {
        return self::ROUTE;
    }

    /**
     * @inheritDoc
     */
    public function paramsOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $connection = $this->resource->getConnection();
        if (!$connection instanceof AdapterInterface) {
            throw new \LogicException(sprintf('%s has no database connection.', $this->resource::class));
        }

        $idField = $this->resource->getIdFieldName();
        $params = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resource->getMainTable(), [$idField, 'path', 'scope', 'scope_id'])
                ->where($connection->quoteIdentifier($idField) . ' IN (?)', $ids)
        ) as $row) {
            $id = is_array($row) ? ($row[$idField] ?? null) : null;
            if (is_array($row) && is_scalar($id)) {
                $params[(string) $id] = $this->paramsOfRow($row);
            }
        }

        return array_filter($params, static fn (array $routeParams): bool => $routeParams !== []);
    }

    /**
     * The section and scope parameters of one stored setting; none when its path names no section
     *
     * @param array<mixed> $row
     * @return array<string, string>
     */
    private function paramsOfRow(array $row): array
    {
        $path = $row['path'] ?? null;
        $section = is_string($path) ? strstr($path, '/', true) : false;
        if ($section === false || $section === '') {
            return [];
        }

        $scope = $row['scope'] ?? null;
        $scopeParam = is_string($scope) ? (self::SCOPE_PARAMS[$scope] ?? null) : null;
        $scopeId = $row['scope_id'] ?? null;

        return $scopeParam !== null && is_scalar($scopeId)
            ? ['section' => $section, $scopeParam => (string) $scopeId]
            : ['section' => $section];
    }
}
