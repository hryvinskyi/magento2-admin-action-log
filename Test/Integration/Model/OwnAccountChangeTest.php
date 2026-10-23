<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model;

use Hryvinskyi\ActivityLog\Api\ActorScopeInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * A customer signing up, a request in which nobody is signed in: every change of the new account is the customer's
 *
 * Signing up saves the account more than once; the saves after the first never list the id as a changed field.
 *
 * @phpstan-type Change array{
 *     action: string,
 *     actor_type: string,
 *     actor_name: string,
 *     actor_id: string,
 *     fields: list<string>
 * }
 */
#[DbIsolation(false)]
class OwnAccountChangeTest extends TestCase
{
    private const EMAIL = 'aal-own-account@example.com';

    protected function tearDown(): void
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName('customer_entity'), ['entity_id'])
                ->where('email = ?', self::EMAIL)
        );
        if ($ids !== []) {
            $connection->delete($resource->getTableName('hryvinskyi_activity_log'), [
                'subject_type = ?' => 'customer',
                'subject_id IN (?)' => $ids,
            ]);
            $connection->delete($resource->getTableName('customer_entity'), ['entity_id IN (?)' => $ids]);
        }
    }

    public function testASignUpIsRecordedAsTheNewCustomersOwnWithoutItsIdAsAChangedField(): void
    {
        $customer = self::service(CustomerInterfaceFactory::class)->create();
        $customer->setEmail(self::EMAIL)->setFirstname('Aal')->setLastname('Owner')->setWebsiteId(1);
        $anonymous = self::service(ActorInterfaceFactory::class)->create([
            'type' => ActorType::Anonymous,
            'name' => 'anonymous',
        ]);

        $created = self::service(ActorScopeInterface::class)->actAs(
            $anonymous,
            static fn () => self::service(AccountManagementInterface::class)
                ->createAccount($customer, 'Aal-Owner-Passw0rd')
        );

        $id = (string) $created->getId();
        $changes = $this->changesOf($id);
        self::assertNotEmpty($changes, 'Signing up was not recorded.');
        foreach ($changes as $change) {
            self::assertSame(
                ['customer', self::EMAIL, $id],
                [$change['actor_type'], $change['actor_name'], $change['actor_id']],
                $change['action']
            );
            self::assertNotContains('entity_id', $change['fields'], $change['action']);
        }
    }

    /**
     * The journaled changes of the customer, oldest first, with the fields each changed
     *
     * @param string $id
     * @return list<Change>
     */
    private function changesOf(string $id): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', 'customer')
                ->where('subject_id = ?', $id)
                ->order('entity_id ASC')
        );

        $changes = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertIsNumeric($row['entity_id'] ?? null);
            $detail = self::service(EntityChangeStore::class)->find((int) $row['entity_id']);
            self::assertNotNull($detail);
            self::assertIsString($row['action'] ?? null);
            self::assertIsString($row['actor_type'] ?? null);
            self::assertIsString($row['actor_name'] ?? null);
            self::assertIsString($row['actor_id'] ?? null);
            $changes[] = [
                'action' => $row['action'],
                'actor_type' => $row['actor_type'],
                'actor_name' => $row['actor_name'],
                'actor_id' => $row['actor_id'],
                'fields' => array_values(array_map(
                    static fn (array $field): string => $field['field'],
                    array_filter($detail['fields'], static fn (array $field): bool => $field['changed'])
                )),
            ];
        }

        return $changes;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private static function service(string $class): object
    {
        $service = Bootstrap::getObjectManager()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
