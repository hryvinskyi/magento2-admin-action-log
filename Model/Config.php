<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model;

use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Admin action log settings
 */
class Config
{
    public const XML_PATH_ADMIN_ENABLED = 'hryvinskyi_admin_action_log/admin/enabled';
    public const XML_PATH_ADMIN_LOG_GET = 'hryvinskyi_admin_action_log/admin/log_get_requests';
    public const XML_PATH_ADMIN_IGNORED_ROUTES = 'hryvinskyi_admin_action_log/admin/ignored_routes';
    public const XML_PATH_ADMIN_RETENTION = 'hryvinskyi_admin_action_log/admin/retention_days';
    public const XML_PATH_ENTITY_ENABLED = 'hryvinskyi_admin_action_log/entity/enabled';
    public const XML_PATH_ENTITY_ACTOR_TYPES = 'hryvinskyi_admin_action_log/entity/actor_types';
    public const XML_PATH_ENTITY_RETENTION = 'hryvinskyi_admin_action_log/entity/default_retention_days';
    public const XML_PATH_ENTITY_TYPES = 'hryvinskyi_admin_action_log/entity/types';

    private const DEFAULT_RETENTION_DAYS = 90;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $json
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $json
    ) {
    }

    /**
     * Whether admin requests, sign-ins and sign-outs are recorded
     *
     * @return bool
     */
    public function isAdminEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ADMIN_ENABLED);
    }

    /**
     * Whether requests that only read (GET) are recorded
     *
     * @return bool
     */
    public function recordsReadRequests(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ADMIN_LOG_GET);
    }

    /**
     * Route patterns never recorded, lowercase
     *
     * @return list<string>
     */
    public function getIgnoredRoutes(): array
    {
        return $this->readList(self::XML_PATH_ADMIN_IGNORED_ROUTES);
    }

    /**
     * Whether entity changes are recorded
     *
     * @return bool
     */
    public function isEntityEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENTITY_ENABLED);
    }

    /**
     * Kinds of actor whose entity changes are recorded
     *
     * @return list<ActorType>
     */
    public function getEntityActorTypes(): array
    {
        $types = [];
        foreach ($this->readList(self::XML_PATH_ENTITY_ACTOR_TYPES) as $value) {
            $type = ActorType::tryFrom($value);
            if ($type !== null) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * Days to keep the changes of an entity without its own setting
     *
     * @return int
     */
    public function getDefaultEntityRetentionDays(): int
    {
        $days = $this->scopeConfig->getValue(self::XML_PATH_ENTITY_RETENTION);

        return is_numeric($days) && (int) $days > 0 ? (int) $days : self::DEFAULT_RETENTION_DAYS;
    }

    /**
     * Per-entity rows as saved in the settings; values are not validated here
     *
     * @return list<array{entity_type: string, diff_mode: string, retention_days: string}>
     */
    public function getEntityTypeRows(): array
    {
        $value = $this->scopeConfig->getValue(self::XML_PATH_ENTITY_TYPES);
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $rows = $this->json->unserialize($value);
        } catch (\InvalidArgumentException) {
            return [];
        }

        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $result[] = [
                'entity_type' => $this->text($row['entity_type'] ?? null),
                'diff_mode' => $this->text($row['diff_mode'] ?? null),
                'retention_days' => $this->text($row['retention_days'] ?? null),
            ];
        }

        return $result;
    }

    /**
     * A comma-separated setting as trimmed, lowercase, non-empty values
     *
     * @param string $path
     * @return list<string>
     */
    private function readList(string $path): array
    {
        $value = $this->scopeConfig->getValue($path);
        if (!is_string($value)) {
            return [];
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = strtolower(trim($item));
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * A setting value as trimmed text
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
