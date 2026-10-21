<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

use Hryvinskyi\ActivityLog\Api\ActivityBuilderInterface;
use Hryvinskyi\ActivityLog\Api\ActivityRecorderInterface;
use Hryvinskyi\ActivityLog\Api\ConfigInterface as ActivityLogConfig;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\RequestSubjects;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\AdminRequestStore;
use Laminas\Http\Header\HeaderInterface;
use Magento\Backend\App\Area\FrontNameResolver;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Records an admin request: captured when its action is dispatched, journaled when its response is ready
 *
 * Recording never breaks the request: a failure to record is logged and the request goes on.
 */
class AdminRequestJournal
{
    private const INTERCEPTOR_SUFFIX = '\\Interceptor';

    /**
     * @param ActivityLogConfig $activityLogConfig
     * @param RequestPolicy $policy
     * @param ParameterSanitizer $parameterSanitizer
     * @param RequestCapture $capture
     * @param RequestSubjects $subjects
     * @param OutcomePolicy $outcomePolicy
     * @param RedirectPath $redirectPath
     * @param FrontNameResolver $frontNameResolver
     * @param ActivityBuilderInterface $activityBuilder
     * @param ActivityRecorderInterface $activityRecorder
     * @param AdminRequestStore $store
     * @param MillisecondClockInterface $clock
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ActivityLogConfig $activityLogConfig,
        private readonly RequestPolicy $policy,
        private readonly ParameterSanitizer $parameterSanitizer,
        private readonly RequestCapture $capture,
        private readonly RequestSubjects $subjects,
        private readonly OutcomePolicy $outcomePolicy,
        private readonly RedirectPath $redirectPath,
        private readonly FrontNameResolver $frontNameResolver,
        private readonly ActivityBuilderInterface $activityBuilder,
        private readonly ActivityRecorderInterface $activityRecorder,
        private readonly AdminRequestStore $store,
        private readonly MillisecondClockInterface $clock,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Start capturing the request an admin action is dispatched for, when it is worth recording
     *
     * A request forwarded to another action stays the request first dispatched.
     *
     * @param RequestInterface $request
     * @param object $action The action being dispatched
     * @return void
     */
    public function start(RequestInterface $request, object $action): void
    {
        if ($this->capture->isActive()
            || !$request instanceof HttpRequest
            || !$this->activityLogConfig->isEnabled()
        ) {
            return;
        }

        $route = sprintf(
            '%s/%s/%s',
            (string) $request->getRouteName(),
            (string) $request->getControllerName(),
            (string) $request->getActionName()
        );
        $method = (string) $request->getMethod();
        if (!$this->policy->shouldRecord($route, $method)) {
            return;
        }

        $parameters = $this->parameterSanitizer->sanitize($request->getParams());
        $this->capture->start(
            $route,
            $method,
            $this->controllerClass($action),
            $request->isAjax(),
            $parameters['parameters'],
            $parameters['omitted'],
            $this->clock->nowMs()
        );
    }

    /**
     * Journal the captured request with how it ended; does nothing when no request is being captured
     *
     * @param ResponseInterface|null $response The response about to be sent, or null when the action threw
     * @param \Throwable|null $error What the action threw
     * @return void
     */
    public function finish(?ResponseInterface $response, ?\Throwable $error = null): void
    {
        $captured = $this->capture->stop();
        if ($captured === null) {
            return;
        }

        try {
            $responseCode = $response instanceof HttpResponse ? (int) $response->getHttpResponseCode() : null;
            $decision = $this->outcomePolicy->decide($responseCode, $captured['messages'], $error);
            $activity = $this->activityBuilder->build(
                ChannelCode::ADMIN,
                $captured['route'],
                $decision['outcome'],
                $this->subjects->first(),
                (float) max(0, $this->clock->nowMs() - $captured['startedAtMs']),
                $decision['message'],
                $captured['startedAtMs']
            );
            $activityId = $this->activityRecorder->record($activity);
            if ($activityId === null) {
                return;
            }

            $this->store->save($activityId, [
                'route' => $captured['route'],
                'method' => $captured['method'],
                'controller' => $captured['controller'],
                'ajax' => $captured['ajax'],
                'parameters' => $captured['parameters'],
                'omitted' => $captured['omitted'],
                'responseCode' => $responseCode,
                'redirectPath' => $this->redirectPathOf($response),
                'messages' => $captured['messages'],
            ]);
        } catch (\Throwable $exception) {
            $this->logger->error('The admin request could not be recorded.', ['exception' => $exception]);
        }
    }

    /**
     * The admin path the response redirects to, or null
     *
     * @param ResponseInterface|null $response
     * @return string|null
     */
    private function redirectPathOf(?ResponseInterface $response): ?string
    {
        if (!$response instanceof HttpResponse) {
            return null;
        }

        $location = $response->getHeader('Location');
        if (!$location instanceof HeaderInterface || $location->getFieldValue() === '') {
            return null;
        }

        return $this->redirectPath->fromLocation(
            $location->getFieldValue(),
            (string) $this->frontNameResolver->getFrontName()
        );
    }

    /**
     * The action's own class, not the generated interceptor around it
     *
     * @param object $action
     * @return string
     */
    private function controllerClass(object $action): string
    {
        $class = $action::class;

        return str_ends_with($class, self::INTERCEPTOR_SUFFIX)
            ? substr($class, 0, -strlen(self::INTERCEPTOR_SUFFIX))
            : $class;
    }
}
