<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;
use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\ActivityLog\Model\Search\Detail\EventDetails;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\AdminRequestStore;
use Magento\Backend\Model\Auth;
use Magento\Backend\Model\Authorization\RoleLocator;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * Admin requests through the real admin stack: dispatch, plugins, response and journal
 *
 * The requests are Magento's CMS block pages, so the access checks this test case inherits run against them: a page
 * the admin's role allows answers, one it does not answers 403. The access list a test narrows is rebuilt from the
 * stored roles when the test ends.
 */
class AdminRequestTest extends AbstractBackendController
{
    /**
     * @var string
     */
    protected $resource = 'Magento_Cms::block';

    /**
     * @var string
     */
    protected $uri = 'backend/cms/block/index';

    protected function tearDown(): void
    {
        $this->service(AclBuilder::class)->resetRuntimeAcl();
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $connection->delete($resource->getTableName('cms_block'), ['identifier LIKE ?' => 'aal-request-%']);
        $connection->delete(
            $resource->getTableName('hryvinskyi_activity_log'),
            ['subject_label LIKE ?' => 'CMS block aal-request-%']
        );
        parent::tearDown();
    }

    /**
     * The block's change is journaled when its transaction commits, so this test commits for real
     */
    #[DbIsolation(false)]
    public function testSavingABlockRecordsTheRequestAndTheChangeAsOneOperation(): void
    {
        $identifier = 'aal-request-' . bin2hex(random_bytes(4));
        $this->httpRequest()->setMethod(HttpRequest::METHOD_POST)->setPostValue([
            'title' => 'Footer links',
            'identifier' => $identifier,
            'content' => '<ul class="footer links"></ul>',
            'is_active' => '1',
            'store_id' => ['0'],
        ]);

        $this->dispatch('backend/cms/block/save');

        $request = $this->latest('admin', 'cms/block/save');
        $detail = $this->service(AdminRequestStore::class)->find($request['id']);
        self::assertNotNull($detail);
        self::assertSame('success', $request['outcome']);
        self::assertSame('POST', $detail['method']);
        self::assertSame('Magento\Cms\Controller\Adminhtml\Block\Save', $detail['controller']);
        self::assertSame(302, $detail['responseCode']);
        self::assertStringStartsWith('cms/block', (string) $detail['redirectPath']);
        self::assertContains(['type' => 'success', 'text' => 'You saved the block.'], $detail['messages']);
        self::assertContains(
            ['name' => 'form_key', 'value' => SanitizerInterface::MASK, 'tag' => 'masked'],
            $detail['parameters']
        );
        self::assertContains(['name' => 'identifier', 'value' => $identifier, 'tag' => ''], $detail['parameters']);

        $change = $this->latest('entity', 'CMS block created');
        self::assertSame(
            $request['correlation'],
            $change['correlation'],
            'The request and its change are one operation.'
        );
        self::assertSame('CMS block ' . $identifier, $request['subject'], 'The request acted on the block it created.');
        self::assertLessThanOrEqual($change['occurredAtMs'], $request['occurredAtMs']);
    }

    public function testASaveTheRoleMayNotMakeIsRecordedAsDenied(): void
    {
        $role = $this->service(RoleLocator::class)->getAclRoleId();
        self::assertIsString($role, 'No admin is logged in.');
        $this->service(AclBuilder::class)->getAcl()->deny($role, $this->resource);
        $this->httpRequest()->setMethod(HttpRequest::METHOD_POST)->setPostValue([
            'title' => 'Not allowed',
            'identifier' => 'aal-request-denied',
            'store_id' => ['0'],
        ]);

        $this->dispatch('backend/cms/block/save');

        $response = $this->getResponse();
        self::assertInstanceOf(\Magento\TestFramework\Response::class, $response);
        self::assertSame(403, $response->getHttpResponseCode());
        $request = $this->latest('admin', 'cms/block/save');
        self::assertSame('denied', $request['outcome']);
        $details = $this->service(EventDetails::class)->forActivities([$request['id'] => 'admin']);
        $detail = $details[$request['id']] ?? null;
        self::assertSame(
            [Verb::TriedToChange, 'CMS block'],
            [$detail?->getVerb(), $detail?->getSubjectTypeLabel()],
            'A search shows who tried to change a CMS block'
        );
        $resource = $this->service(ResourceConnection::class);
        self::assertFalse($resource->getConnection()->fetchOne(
            $resource->getConnection()->select()
                ->from($resource->getTableName('cms_block'), ['block_id'])
                ->where('identifier = ?', 'aal-request-denied')
        ), 'Nothing was saved.');
    }

    public function testPageViewsAreNotRecordedByDefault(): void
    {
        $before = $this->activityCount('admin', 'cms/block/index');

        $this->dispatch('backend/cms/block/index');

        self::assertSame($before, $this->activityCount('admin', 'cms/block/index'));
    }

    public function testAFailedSignInIsRecordedWithTheNameThatWasTried(): void
    {
        Bootstrap::getInstance()->loadArea('adminhtml');
        $this->_auth->logout();
        try {
            $this->service(Auth::class)->login('nobody-' . bin2hex(random_bytes(3)), 'wrong password');
            self::fail('Signing in with a wrong password should fail.');
        } catch (LocalizedException) {
            // Expected; the journal is what this test reads
        }

        $failure = $this->latest('admin', 'Admin login failed');
        self::assertSame('failure', $failure['outcome']);
        self::assertStringStartsWith('User "nobody-', $failure['subject']);
    }

    public function testSigningOutNamesTheAdminWhoLeft(): void
    {
        $this->dispatch('backend/admin/auth/logout');

        $logout = $this->latest('admin', 'Admin logout');
        self::assertSame('success', $logout['outcome']);
        self::assertSame('User "' . \Magento\TestFramework\Bootstrap::ADMIN_NAME . '"', $logout['subject']);
    }

    /**
     * The newest activity of a channel with an action
     *
     * @param string $channel
     * @param string $action
     * @return array{id: int, outcome: string, subject: string, correlation: string, occurredAtMs: int}
     */
    private function latest(string $channel, string $action): array
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', $channel)
                ->where('action = ?', $action)
                ->order('entity_id DESC')
                ->limit(1)
        );
        self::assertIsArray($row, sprintf('No "%s" activity in channel %s.', $action, $channel));
        self::assertIsNumeric($row['entity_id'] ?? null);
        self::assertIsNumeric($row['occurred_at_ms'] ?? null);

        return [
            'id' => (int) $row['entity_id'],
            'outcome' => is_string($row['outcome'] ?? null) ? $row['outcome'] : '',
            'subject' => is_string($row['subject_label'] ?? null) ? $row['subject_label'] : '',
            'correlation' => is_string($row['correlation_id'] ?? null) ? $row['correlation_id'] : '',
            'occurredAtMs' => (int) $row['occurred_at_ms'],
        ];
    }

    /**
     * @param string $channel
     * @param string $action
     * @return int
     */
    private function activityCount(string $channel, string $action): int
    {
        $resource = $this->service(ResourceConnection::class);
        $connection = $resource->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'), ['COUNT(*)'])
                ->where('channel = ?', $channel)
                ->where('action = ?', $action)
        );
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @return \Magento\TestFramework\Request
     */
    private function httpRequest(): \Magento\TestFramework\Request
    {
        $request = $this->getRequest();
        self::assertInstanceOf(\Magento\TestFramework\Request::class, $request);

        return $request;
    }
}
