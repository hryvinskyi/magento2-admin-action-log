<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Cms\Model\Block;
use Magento\Cms\Model\BlockFactory;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\ResourceModel\Config\Data as ConfigData;
use Magento\Framework\App\Config\Value;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Entity changes through real saves, transactions and the journal
 *
 * A change is journaled after its transaction commits, which a test wrapped in its own transaction never sees; these
 * tests commit for real and remove what they wrote.
 *
 * @phpstan-import-type ChangeDetail from EntityChangeStore
 */
#[DbIsolation(false)]
class EntityChangeJournalTest extends TestCase
{
    protected function tearDown(): void
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $connection->delete($resource->getTableName('cms_block'), ['identifier LIKE ?' => 'aal-%']);
        $connection->delete($resource->getTableName('core_config_data'), ['path LIKE ?' => 'hryvinskyi_test/%']);
        $connection->delete($resource->getTableName('hryvinskyi_activity_log'), [
            'channel = ?' => 'entity',
            '(subject_label LIKE \'CMS block aal-%\' OR subject_label LIKE \'Config value hryvinskyi_test/%\')',
        ]);
    }

    public function testCreatingAndChangingABlockIsJournaledFieldByField(): void
    {
        $block = $this->saveBlock('aal-footer-links', 'Footer links', '<ul class="footer links"></ul>');
        $blockId = (string) $block->getId();

        $created = $this->latestChange('cms_block', $blockId);
        self::assertSame('CMS block created', $created['action']);
        self::assertStringStartsWith('CMS block aal-footer-links · ', $created['subject']);
        self::assertSame(ChangeOperation::Create, $created['detail']['operation']);
        self::assertContains(
            ['field' => 'title', 'before' => null, 'after' => 'Footer links', 'changed' => true],
            $this->fields($created['detail'])
        );
        self::assertNotContains(
            'block_id',
            array_column($this->fields($created['detail']), 'field'),
            'The id names the subject; it is not a field of the change.'
        );

        $block->setTitle('Footer links (new)');
        self::service(BlockRepositoryInterface::class)->save($block);

        $saved = $this->latestChange('cms_block', $blockId);
        self::assertSame('CMS block saved', $saved['action']);
        self::assertSame('CMS block aal-footer-links · title', $saved['subject']);
        self::assertSame(
            [['field' => 'title', 'before' => 'Footer links', 'after' => 'Footer links (new)', 'changed' => true]],
            $this->fields($saved['detail']),
            'Only the field that changed is kept.'
        );
        self::assertGreaterThan(0, $saved['detail']['unchangedCount']);
        self::assertSame('global', $saved['detail']['fields'][0]['scopeType']);
    }

    public function testASaveWithoutChangesLeavesNoRecord(): void
    {
        $block = $this->saveBlock('aal-unchanged', 'Unchanged', 'Same');
        $before = $this->countChanges('cms_block', (string) $block->getId());

        $block->setTitle('Unchanged');
        $block->setDataChanges(true);
        self::service(BlockRepositoryInterface::class)->save($block);

        self::assertSame($before, $this->countChanges('cms_block', (string) $block->getId()));
    }

    public function testARolledBackSaveLeavesNoRecord(): void
    {
        $this->saveBlock('aal-taken', 'Taken', 'First');
        $kept = $this->newBlock('aal-rolled-back', 'Would be saved', 'Second');
        $duplicate = $this->newBlock('aal-taken', 'Duplicate identifier', 'Third');

        $transaction = self::service(TransactionFactory::class)->create();
        $transaction->addObject($kept)->addObject($duplicate);
        $stopped = false;
        try {
            $transaction->save();
        } catch (LocalizedException) {
            $stopped = true;
        }

        self::assertTrue($stopped, 'The duplicate identifier should have stopped the transaction.');
        self::assertSame(0, $this->countSubjects('cms_block', 'CMS block aal-rolled-back%'));
    }

    #[Config(
        'hryvinskyi_admin_action_log/entity/types',
        '{"_1":{"entity_type":"cms_block","diff_mode":"snapshot","retention_days":"30"}}'
    )]
    public function testSnapshotModeKeepsTheUnchangedFieldsToo(): void
    {
        $block = $this->saveBlock('aal-snapshot', 'Snapshot', 'Body');
        $block->setContent('Body, changed');
        self::service(BlockRepositoryInterface::class)->save($block);

        $saved = $this->latestChange('cms_block', (string) $block->getId());
        $byField = array_column($this->fields($saved['detail']), null, 'field');

        self::assertTrue($byField['content']['changed']);
        self::assertFalse($byField['title']['changed']);
        self::assertSame('Snapshot', $byField['title']['after']);
    }

    public function testSecretSettingsAreMaskedBeforeTheyAreStored(): void
    {
        $this->saveConfig(Value::class, 'hryvinskyi_test/carrier/password', 'hunter2');
        $this->saveConfig(Encrypted::class, 'hryvinskyi_test/carrier/account', 'acct-0042');
        $this->saveConfig(Value::class, 'hryvinskyi_test/carrier/title', 'Fast');

        $password = $this->latestChangeBySubject('Config value hryvinskyi_test/carrier/password%');
        $account = $this->latestChangeBySubject('Config value hryvinskyi_test/carrier/account%');
        $title = $this->latestChangeBySubject('Config value hryvinskyi_test/carrier/title%');

        self::assertSame(
            [[
                'field' => 'hryvinskyi_test/carrier/password',
                'before' => null,
                'after' => SanitizerInterface::MASK,
                'changed' => true,
            ]],
            $this->fields($password['detail'])
        );
        self::assertTrue($password['detail']['fields'][0]['masked']);
        self::assertSame(
            SanitizerInterface::MASK,
            $account['detail']['fields'][0]['after'],
            'Encrypted values are secret whatever their path.'
        );
        self::assertSame('Fast', $title['detail']['fields'][0]['after']);
        self::assertSame('Config value hryvinskyi_test/carrier/title', $title['subject'], 'The path is not repeated.');
        self::assertSame('Default Config', $title['detail']['fields'][0]['scopeLabel']);
    }

    #[Config('hryvinskyi_admin_action_log/entity/actor_types', 'admin')]
    public function testChangesByActorsNotChosenAreNotRecorded(): void
    {
        $block = $this->saveBlock('aal-not-chosen', 'Not chosen', 'Saved from the command line');

        self::assertSame(0, $this->countChanges('cms_block', (string) $block->getId()));
    }

    /**
     * @param string $identifier
     * @param string $title
     * @param string $content
     * @return Block
     */
    private function saveBlock(string $identifier, string $title, string $content): Block
    {
        $block = $this->newBlock($identifier, $title, $content);
        $saved = self::service(BlockRepositoryInterface::class)->save($block);
        self::assertInstanceOf(Block::class, $saved);

        return $saved;
    }

    /**
     * @param string $identifier
     * @param string $title
     * @param string $content
     * @return Block
     */
    private function newBlock(string $identifier, string $title, string $content): Block
    {
        $block = self::service(BlockFactory::class)->create();
        $block->setIdentifier($identifier)->setTitle($title)->setContent($content)->setIsActive(true);
        $block->setData('store_id', [0]);

        return $block;
    }

    /**
     * @param class-string<Value> $class
     * @param string $path
     * @param string $value
     * @return void
     */
    private function saveConfig(string $class, string $path, string $value): void
    {
        $model = self::service($class, []);
        $model->addData(['path' => $path, 'value' => $value, 'scope' => 'default', 'scope_id' => 0]);
        self::service(ConfigData::class)->save($model);
    }

    /**
     * @param string $type
     * @param string $id
     * @return array{action: string, subject: string, detail: ChangeDetail}
     */
    private function latestChange(string $type, string $id): array
    {
        $connection = self::service(ResourceConnection::class)->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(self::service(ResourceConnection::class)->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', $type)
                ->where('subject_id = ?', $id)
                ->order('entity_id DESC')
                ->limit(1)
        );

        return $this->change($row);
    }

    /**
     * @param string $subjectLike
     * @return array{action: string, subject: string, detail: ChangeDetail}
     */
    private function latestChangeBySubject(string $subjectLike): array
    {
        $connection = self::service(ResourceConnection::class)->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from(self::service(ResourceConnection::class)->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', 'entity')
                ->where('subject_label LIKE ?', $subjectLike)
                ->order('entity_id DESC')
                ->limit(1)
        );

        return $this->change($row);
    }

    /**
     * @param mixed $row
     * @return array{action: string, subject: string, detail: ChangeDetail}
     */
    private function change(mixed $row): array
    {
        self::assertIsArray($row, 'The change was not journaled.');
        self::assertIsNumeric($row['entity_id'] ?? null);
        self::assertIsString($row['action'] ?? null);
        self::assertIsString($row['subject_label'] ?? null);
        $detail = self::service(EntityChangeStore::class)->find((int) $row['entity_id']);
        self::assertNotNull($detail, 'The change has no field detail.');

        return ['action' => $row['action'], 'subject' => $row['subject_label'], 'detail' => $detail];
    }

    /**
     * @param ChangeDetail $detail
     * @return list<array{field: string, before: string|null, after: string|null, changed: bool}>
     */
    private function fields(array $detail): array
    {
        $fields = [];
        foreach ($detail['fields'] as $field) {
            $fields[] = [
                'field' => $field['field'],
                'before' => $field['before'],
                'after' => $field['after'],
                'changed' => $field['changed'],
            ];
        }

        return $fields;
    }

    /**
     * @param string $type
     * @param string $id
     * @return int
     */
    private function countChanges(string $type, string $id): int
    {
        $connection = self::service(ResourceConnection::class)->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from(self::service(ResourceConnection::class)->getTableName('hryvinskyi_activity_log'), ['COUNT(*)'])
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', $type)
                ->where('subject_id = ?', $id)
        );
    }

    /**
     * @param string $type
     * @param string $subjectLike
     * @return int
     */
    private function countSubjects(string $type, string $subjectLike): int
    {
        $connection = self::service(ResourceConnection::class)->getConnection();

        return (int) $connection->fetchOne(
            $connection->select()
                ->from(self::service(ResourceConnection::class)->getTableName('hryvinskyi_activity_log'), ['COUNT(*)'])
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', $type)
                ->where('subject_label LIKE ?', $subjectLike)
        );
    }

    /**
     * A service from the real container, typed
     *
     * @template T of object
     * @param class-string<T> $class
     * @param array<string,mixed>|null $arguments Build a fresh instance with these arguments instead
     * @return T
     */
    private static function service(string $class, ?array $arguments = null): object
    {
        $objectManager = Bootstrap::getObjectManager();
        $service = $arguments === null ? $objectManager->get($class) : $objectManager->create($class, $arguments);
        self::assertInstanceOf($class, $service);

        return $service;
    }
}
