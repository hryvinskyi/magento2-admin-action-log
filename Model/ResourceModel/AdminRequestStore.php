<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * The admin request detail of an activity, one row per activity
 *
 * @phpstan-type RequestDetail array{
 *     route: string,
 *     method: string,
 *     controller: string|null,
 *     ajax: bool,
 *     parameters: list<array{name: string, value: string, tag: string}>,
 *     omitted: int,
 *     responseCode: int|null,
 *     redirectPath: string|null,
 *     messages: list<array{type: string, text: string}>
 * }
 */
class AdminRequestStore
{
    public const TABLE = 'hryvinskyi_admin_action_log_request';

    /**
     * @param ResourceConnection $resourceConnection
     * @param Json $json
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        private readonly Json $json
    ) {
    }

    /**
     * Store the request of an activity
     *
     * @param int $activityId
     * @param RequestDetail $detail
     * @return void
     */
    public function save(int $activityId, array $detail): void
    {
        $this->resourceConnection->getConnection()->insert($this->table(), [
            'activity_id' => $activityId,
            'route' => mb_substr($detail['route'], 0, 255),
            'http_method' => mb_substr($detail['method'], 0, 10),
            'controller_class' => $detail['controller'] === null ? null : mb_substr($detail['controller'], 0, 255),
            'is_ajax' => (int) $detail['ajax'],
            'parameters' => $this->json->serialize($detail['parameters']),
            'parameters_omitted' => $detail['omitted'],
            'response_code' => $detail['responseCode'],
            'redirect_path' => $detail['redirectPath'],
            'messages' => $detail['messages'] === [] ? null : $this->json->serialize($detail['messages']),
        ]);
    }

    /**
     * The request of an activity, or null
     *
     * @param int $activityId
     * @return RequestDetail|null
     */
    public function find(int $activityId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->table())->where('activity_id = ?', $activityId)
        );
        if (!is_array($row)) {
            return null;
        }

        return [
            'route' => $this->text($row['route'] ?? null),
            'method' => $this->text($row['http_method'] ?? null),
            'controller' => $this->nullableText($row['controller_class'] ?? null),
            'ajax' => (bool) ($row['is_ajax'] ?? false),
            'parameters' => $this->parameters($row['parameters'] ?? null),
            'omitted' => is_numeric($row['parameters_omitted'] ?? null) ? (int) $row['parameters_omitted'] : 0,
            'responseCode' => is_numeric($row['response_code'] ?? null) ? (int) $row['response_code'] : null,
            'redirectPath' => $this->nullableText($row['redirect_path'] ?? null),
            'messages' => $this->messages($row['messages'] ?? null),
        ];
    }

    /**
     * The request table name
     *
     * @return string
     */
    private function table(): string
    {
        return $this->resourceConnection->getTableName(self::TABLE);
    }

    /**
     * Stored parameters, typed
     *
     * @param mixed $value
     * @return list<array{name: string, value: string, tag: string}>
     */
    private function parameters(mixed $value): array
    {
        $parameters = [];
        foreach ($this->decodeList($value) as $item) {
            $parameters[] = [
                'name' => $this->text($item['name'] ?? null),
                'value' => $this->text($item['value'] ?? null),
                'tag' => $this->text($item['tag'] ?? null),
            ];
        }

        return $parameters;
    }

    /**
     * Stored messages, typed
     *
     * @param mixed $value
     * @return list<array{type: string, text: string}>
     */
    private function messages(mixed $value): array
    {
        $messages = [];
        foreach ($this->decodeList($value) as $item) {
            $messages[] = ['type' => $this->text($item['type'] ?? null), 'text' => $this->text($item['text'] ?? null)];
        }

        return $messages;
    }

    /**
     * A JSON list of objects, or nothing when the value is not one
     *
     * @param mixed $value
     * @return list<array<mixed>>
     */
    private function decodeList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($value);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    /**
     * A column value as text
     *
     * @param mixed $value
     * @return string
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A column value as text, or null when empty
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullableText(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
