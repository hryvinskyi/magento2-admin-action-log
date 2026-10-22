<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Detail;

use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;
use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\ActivityLog\Model\Data\Search\EventDetail;
use Hryvinskyi\ActivityLog\Model\Data\Search\LinkPolicy;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\AdminRequestStore;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\DeniedRequestDetails;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\EditPage;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\SubjectLinks;
use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordPresenceInterface;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type RequestDetail from AdminRequestStore
 */
class DeniedRequestDetailsTest extends TestCase
{
    public function testDescribesTheAdminChannelOnly(): void
    {
        $details = $this->details([]);

        self::assertTrue($details->supports('admin'));
        self::assertFalse($details->supports('entity'));
    }

    public function testARefusedSaveTriedToChangeTheRecordItsIdNames(): void
    {
        $detail = $this->details([
            7 => $this->request('cms/block/save', 'POST', 403, ['title' => 'Footer', 'block_id' => '5']),
        ])->detailsFor([7])[7] ?? null;

        self::assertSame(
            [Verb::TriedToChange, 'CMS block', '#5', '/admin/cms/block/edit?block_id=5'],
            $this->sentence($detail)
        );
    }

    public function testARefusedDeleteTriedToDeleteWhateverItsMethod(): void
    {
        $details = $this->details([
            1 => $this->request('cms/block/delete', 'GET', 403, ['block_id' => '9']),
            2 => $this->request('cms/block/massDelete', 'POST', 403, ['selected[0]' => '5']),
        ])->detailsFor([1, 2]);

        self::assertSame([Verb::TriedToDelete, 'CMS block', '#9', null], $this->sentence($details[1] ?? null));
        self::assertSame(
            [Verb::TriedToDelete, 'CMS block', null, null],
            $this->sentence($details[2] ?? null),
            'A mass action names no one record'
        );
    }

    public function testTheIdParametersOfATargetAreTriedInOrder(): void
    {
        $details = $this->details([
            1 => $this->request('cms/block/save', 'POST', 403, ['id' => 'x', 'block_id' => '', 'entity_id' => '5']),
            2 => $this->request('cms/block/save', 'POST', 403, ['block_id' => 'not-a-number', 'entity_id' => '6']),
        ])->detailsFor([1, 2]);

        self::assertSame(['#5', '#6'], [
            ($details[1] ?? null)?->getSubjectLabel(),
            ($details[2] ?? null)?->getSubjectLabel(),
        ]);
    }

    public function testARequestToAnUnknownControllerIsNamedByItsRoute(): void
    {
        $detail = $this->details([
            3 => $this->request('acme_feed/export/run', 'POST', 403, []),
        ])->detailsFor([3])[3] ?? null;

        self::assertSame([Verb::TriedToChange, null, 'acme_feed/export/run', null], $this->sentence($detail));
    }

    public function testRequestsCarriedOutAndRefusedPageViewsKeepTheJournalsDescription(): void
    {
        self::assertSame([], $this->details([
            1 => $this->request('cms/block/save', 'POST', 302, ['block_id' => '5']),
            2 => $this->request('cms/block/edit', 'GET', 403, ['block_id' => '5']),
            3 => $this->request('cms/block/save', 'POST', null, ['block_id' => '5']),
        ])->detailsFor([1, 2, 3, 4]));
    }

    public function testRefusesAMalformedTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"cms"');

        new DeniedRequestDetails(
            $this->createStub(AdminRequestStore::class),
            new TrackedEntityPool($this->createStub(SanitizerInterface::class), []),
            $this->links(),
            $this->createStub(EventDetailInterfaceFactory::class),
            ['cms' => ['entity' => 'cms_block', 'idParams' => ['block_id']]]
        );
    }

    /**
     * The verb, kind, name and link of a detail
     *
     * @param EventDetailInterface|null $detail
     * @return array{Verb, string|null, string|null, string|null}
     */
    private function sentence(?EventDetailInterface $detail): array
    {
        self::assertInstanceOf(EventDetailInterface::class, $detail);

        return [
            $detail->getVerb(),
            $detail->getSubjectTypeLabel(),
            $detail->getSubjectLabel(),
            $detail->getSubjectUrl(),
        ];
    }

    /**
     * Details over these stored requests, for CMS blocks of which block 5 exists
     *
     * @param array<int, RequestDetail> $requests
     * @return DeniedRequestDetails
     */
    private function details(array $requests): DeniedRequestDetails
    {
        $store = $this->createMock(AdminRequestStore::class);
        $store->method('findMany')->willReturnCallback(
            static fn (array $ids): array => array_filter(
                $requests,
                static fn (int $id): bool => in_array($id, $ids, true),
                ARRAY_FILTER_USE_KEY
            )
        );
        $factory = $this->createMock(EventDetailInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn (): EventDetail => new EventDetail(new LinkPolicy()));

        return new DeniedRequestDetails(
            $store,
            new TrackedEntityPool($this->createStub(SanitizerInterface::class), [
                'cms_block' => $this->entity('cms_block', 'CMS block'),
            ]),
            $this->links(),
            $factory,
            ['cms/block' => ['entity' => 'cms_block', 'idParams' => ['block_id', 'entity_id']]],
            ['delete', 'massDelete']
        );
    }

    /**
     * A stored request
     *
     * @param string $route
     * @param string $method
     * @param int|null $responseCode
     * @param array<string, string> $parameters
     * @return RequestDetail
     */
    private function request(string $route, string $method, ?int $responseCode, array $parameters): array
    {
        $named = [];
        foreach ($parameters as $name => $value) {
            $named[] = ['name' => $name, 'value' => $value, 'tag' => ''];
        }

        return [
            'route' => $route,
            'method' => $method,
            'controller' => null,
            'ajax' => false,
            'parameters' => $named,
            'omitted' => 0,
            'responseCode' => $responseCode,
            'redirectPath' => null,
            'messages' => [],
        ];
    }

    /**
     * Links to CMS block edit pages, of which block 5 exists, for an admin allowed everything
     *
     * @return SubjectLinks
     */
    private function links(): SubjectLinks
    {
        $records = $this->createMock(RecordPresenceInterface::class);
        $records->method('existing')->willReturnCallback(
            static fn (array $ids): array => array_values(
                array_filter($ids, static fn (mixed $id): bool => $id === '5')
            )
        );
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (mixed $route, mixed $params): string => '/admin/' . (is_string($route) ? $route : '') . '?'
                . http_build_query(is_array($params) ? $params : [])
        );
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);

        return new SubjectLinks($url, $authorization, [
            'cms_block' => new EditPage($records, 'cms/block/edit', 'block_id', 'Magento_Cms::block'),
        ]);
    }

    /**
     * A tracked entity kind with a code and a label
     *
     * @param string $code
     * @param string $label
     * @return TrackedEntityInterface
     */
    private function entity(string $code, string $label): TrackedEntityInterface
    {
        $entity = $this->createMock(TrackedEntityInterface::class);
        $entity->method('getCode')->willReturn($code);
        $entity->method('getLabel')->willReturn($label);

        return $entity;
    }
}
