<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * What is known about the admin request being recorded, from its dispatch until its response is sent
 *
 * @phpstan-type Captured array{
 *     route: string,
 *     method: string,
 *     controller: string|null,
 *     ajax: bool,
 *     parameters: list<array{name: string, value: string, tag: string}>,
 *     omitted: int,
 *     startedAtMs: int,
 *     messages: list<array{type: string, text: string}>
 * }
 */
class RequestCapture implements ResetAfterRequestInterface
{
    private const MESSAGE_MAX_LENGTH = 500;
    private const MAX_MESSAGES = 20;

    /**
     * @var Captured|null
     */
    private ?array $request = null;

    /**
     * Start capturing a request; a request already being captured is kept
     *
     * @param string $route
     * @param string $method
     * @param string|null $controller
     * @param bool $ajax
     * @param list<array{name:string,value:string,tag:string}> $parameters
     * @param int $omitted
     * @param int $startedAtMs
     * @return bool Whether this call started the capture
     */
    public function start(
        string $route,
        string $method,
        ?string $controller,
        bool $ajax,
        array $parameters,
        int $omitted,
        int $startedAtMs
    ): bool {
        if ($this->request !== null) {
            return false;
        }

        $this->request = [
            'route' => $route,
            'method' => strtoupper($method),
            'controller' => $controller,
            'ajax' => $ajax,
            'parameters' => $parameters,
            'omitted' => $omitted,
            'startedAtMs' => $startedAtMs,
            'messages' => [],
        ];

        return true;
    }

    /**
     * Whether a request is being captured
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return $this->request !== null;
    }

    /**
     * Remember a message shown to the admin during the request
     *
     * @param string $type
     * @param string $text
     * @return void
     */
    public function addMessage(string $type, string $text): void
    {
        $text = trim($text);
        if ($this->request === null || $text === '' || count($this->request['messages']) >= self::MAX_MESSAGES) {
            return;
        }

        $this->request['messages'][] = ['type' => $type, 'text' => mb_substr($text, 0, self::MESSAGE_MAX_LENGTH)];
    }

    /**
     * End the capture and hand over what was captured, or null when nothing was
     *
     * @return Captured|null
     */
    public function stop(): ?array
    {
        $request = $this->request;
        $this->request = null;

        return $request;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->request = null;
    }
}
