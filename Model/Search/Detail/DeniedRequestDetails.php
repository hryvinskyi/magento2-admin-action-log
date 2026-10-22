<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\Search\Detail;

use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;
use Hryvinskyi\ActivityLog\Api\Search\EventDetailProviderInterface;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\AdminRequestStore;

/**
 * Admin requests the admin's role refused to carry out, as search results describe them: who tried to change or to
 * delete which record
 *
 * - A request is a refused write when the admin panel answered it 403 and it was sent to change something: by any
 *   method but GET, or to a delete action whatever its method. A refused page view, and every request that was
 *   carried out, keep the journal's own description.
 * - A request to a delete action ("delete", "massDelete") tried to delete; any other tried to change.
 * - "targets" names, by the route and controller a request went to ("cms/block"), the tracked kind of record it
 *   writes and the request parameters that may carry the record's id, tried in order. A request to a target names the
 *   kind and, when one of the parameters holds an id, the record, linked when it exists and the admin may open it. A
 *   request to anything else is named by its route.
 *
 * The requests of a page of activities are read in one query.
 *
 * @phpstan-import-type RequestDetail from AdminRequestStore
 * @phpstan-type Target array{entity: string, idParams: list<string>}
 * @phpstan-type Attempt array{verb: Verb, route: string, entity: string|null, id: string|null}
 * @phpstan-type Parameter array{name: string, value: string, tag: string}
 */
class DeniedRequestDetails implements EventDetailProviderInterface
{
    private const DENIED_STATUS = 403;
    private const READ_METHOD = 'GET';
    private const CONTROLLER_PATH = '#^[a-z][a-z0-9_]*/[a-z][a-z0-9_]*$#';

    /**
     * @var array<string, Target>
     */
    private array $targets = [];

    /**
     * @var array<string, true>
     */
    private array $deleteActions = [];

    /**
     * @param AdminRequestStore $requests
     * @param TrackedEntityPool $entities
     * @param SubjectLinks $subjectLinks
     * @param EventDetailInterfaceFactory $detailFactory
     * @param array<mixed> $targets Keyed by route and controller: entity (a tracked entity code), idParams (a list)
     * @param array<mixed> $deleteActions Action names of the requests that delete
     * @throws \InvalidArgumentException When a target is malformed
     */
    public function __construct(
        private readonly AdminRequestStore $requests,
        private readonly TrackedEntityPool $entities,
        private readonly SubjectLinks $subjectLinks,
        private readonly EventDetailInterfaceFactory $detailFactory,
        array $targets = [],
        array $deleteActions = []
    ) {
        foreach ($targets as $controllerPath => $target) {
            $this->addTarget((string) $controllerPath, $target);
        }
        foreach ($deleteActions as $action) {
            if (is_string($action) && $action !== '') {
                $this->deleteActions[$action] = true;
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function supports(string $channel): bool
    {
        return $channel === ChannelCode::ADMIN;
    }

    /**
     * @inheritDoc
     */
    public function detailsFor(array $activityIds, array $subjectLabels = []): array
    {
        $attempts = [];
        foreach ($this->requests->findMany($activityIds) as $activityId => $request) {
            if ($this->isRefusedWrite($request)) {
                $attempts[$activityId] = $this->attemptOf($request);
            }
        }
        if ($attempts === []) {
            return [];
        }

        $links = $this->subjectLinks->linksFor(array_map(
            static fn (array $attempt): array => ['type' => $attempt['entity'], 'id' => $attempt['id']],
            array_filter($attempts, static fn (array $attempt): bool => $attempt['entity'] !== null)
        ));

        $details = [];
        foreach ($attempts as $activityId => $attempt) {
            $details[$activityId] = $this->detailOf($attempt, $links[$activityId] ?? null);
        }

        return $details;
    }

    /**
     * Whether the admin panel refused a request that was sent to change or delete something
     *
     * @param RequestDetail $request
     * @return bool
     */
    private function isRefusedWrite(array $request): bool
    {
        return $request['responseCode'] === self::DENIED_STATUS
            && (strtoupper($request['method']) !== self::READ_METHOD || $this->isDelete($request['route']));
    }

    /**
     * What a refused request tried to do, and to which record
     *
     * @param RequestDetail $request
     * @return Attempt
     */
    private function attemptOf(array $request): array
    {
        $target = $this->targets[$this->controllerPath($request['route'])] ?? null;

        return [
            'verb' => $this->isDelete($request['route']) ? Verb::TriedToDelete : Verb::TriedToChange,
            'route' => $request['route'],
            'entity' => $target['entity'] ?? null,
            'id' => $target === null ? null : $this->idOf($target['idParams'], $request['parameters']),
        ];
    }

    /**
     * The detail of one refused request
     *
     * @param Attempt $attempt
     * @param string|null $link
     * @return EventDetailInterface
     */
    private function detailOf(array $attempt, ?string $link): EventDetailInterface
    {
        $detail = $this->detailFactory->create()->withVerb($attempt['verb']);
        if ($attempt['entity'] === null) {
            return $detail->withSubjectLabel($attempt['route']);
        }

        return $detail
            ->withSubject($this->entities->get($attempt['entity'])?->getLabel(), $link)
            ->withSubjectLabel($attempt['id'] === null ? null : '#' . $attempt['id']);
    }

    /**
     * The first id one of the parameters holds
     *
     * @param list<string> $idParams
     * @param list<Parameter> $parameters
     * @return string|null
     */
    private function idOf(array $idParams, array $parameters): ?string
    {
        $values = array_column($parameters, 'value', 'name');
        foreach ($idParams as $name) {
            $value = trim($values[$name] ?? '');
            if ($value !== '' && ctype_digit($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Whether a route goes to a delete action
     *
     * @param string $route Route, controller and action: "cms/block/delete"
     * @return bool
     */
    private function isDelete(string $route): bool
    {
        $slash = strrpos($route, '/');

        return $slash !== false && isset($this->deleteActions[substr($route, $slash + 1)]);
    }

    /**
     * The route and controller of a route: "cms/block" of "cms/block/save"
     *
     * @param string $route
     * @return string
     */
    private function controllerPath(string $route): string
    {
        $slash = strrpos($route, '/');

        return $slash === false ? $route : substr($route, 0, $slash);
    }

    /**
     * Register a target; anything malformed fails here, at construction
     *
     * @param string $controllerPath
     * @param mixed $target
     * @return void
     * @throws \InvalidArgumentException
     */
    private function addTarget(string $controllerPath, mixed $target): void
    {
        $entity = is_array($target) ? ($target['entity'] ?? null) : null;
        $idParams = is_array($target) ? ($target['idParams'] ?? []) : null;
        if (preg_match(self::CONTROLLER_PATH, $controllerPath) !== 1
            || !is_string($entity)
            || $entity === ''
            || !is_array($idParams)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'The target "%s" must name a route and controller, an entity and a list of id parameters.',
                $controllerPath
            ));
        }

        $this->targets[$controllerPath] = [
            'entity' => $entity,
            'idParams' => array_values(array_filter(
                $idParams,
                static fn (mixed $name): bool => is_string($name) && $name !== ''
            )),
        ];
    }
}
