<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Model;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterfaceFactory;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\EntityChangeRecorderInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Config\Model\Config\Backend\Encrypted;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Changes recorded without a model, through the recorder the container builds, the journal and the change tables
 *
 * The recorder writes at once, so these tests see their records inside their own transaction.
 *
 * @phpstan-import-type ChangeDetail from EntityChangeStore
 */
#[DbIsolation(true)]
class EntityChangeRecorderTest extends TestCase
{
    private const BLOCK_LABEL = 'CMS block #990001';

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-recorded-tote', 'name' => 'Aal Recorded Tote'], 'product')]
    public function testAChangeIsJournaledUnderTheEntityNamedAsItsSavedChangesAre(): void
    {
        $productId = $this->productId();
        $this->recorder()->record(
            $this->change()
                ->withEntity('product', $productId)
                ->withScope(EntityScopeInterface::TYPE_GLOBAL, 0, 'Back Room')
                ->withField('quantity', '100.0000', '95.0000')
                ->withField('source_status', '1', '1')
        );

        $change = $this->latestChange('product', $productId);
        self::assertSame('Product saved', $change['action']);
        self::assertSame('Product aal-recorded-tote · Aal Recorded Tote · quantity', $change['subject']);
        self::assertSame(ChangeOperation::Update, $change['detail']['operation']);
        self::assertSame(DiffMode::Changed, $change['detail']['mode']);
        self::assertSame(1, $change['detail']['unchangedCount']);
        self::assertCount(1, $change['detail']['fields'], 'Changed fields only');
        $field = $change['detail']['fields'][0];
        self::assertSame(['quantity', '100.0000', '95.0000'], [$field['field'], $field['before'], $field['after']]);
        self::assertSame(['global', 0, 'Back Room'], [$field['scopeType'], $field['scopeId'], $field['scopeLabel']]);
    }

    #[DataFixture(ProductFixture::class, ['sku' => 'aal-recorded-same'], 'product')]
    public function testAChangeInWhichNothingChangedLeavesNoRecord(): void
    {
        $productId = $this->productId();
        $before = $this->countChanges('product', $productId);
        $this->recorder()->record(
            $this->change()->withEntity('product', $productId)->withField('quantity', '95.0000', '95')
        );

        self::assertSame($before, $this->countChanges('product', $productId), 'Decimals compare by value');
    }

    public function testAnEntityWhoseRecordCannotBeReadIsNamedByItsLabelAndId(): void
    {
        $this->recorder()->record(
            $this->change()
                ->withEntity('cms_block', '990001')
                ->withOperation(EntityChangeInterface::OPERATION_DELETE)
                ->withField('title', 'Gone', null)
        );

        $change = $this->latestChange('cms_block', '990001');
        self::assertSame('CMS block deleted', $change['action']);
        self::assertStringStartsWith(self::BLOCK_LABEL, $change['subject']);
        self::assertSame(ChangeOperation::Delete, $change['detail']['operation']);
        self::assertSame('Global', $change['detail']['fields'][0]['scopeLabel'], 'A change naming no scope is global');
    }

    public function testSecretFieldsAreMaskedBeforeTheyAreStored(): void
    {
        $this->recorder()->record(
            $this->change()->withEntity('cms_block', '990001')->withField('api_password', 'old', 'new')
        );

        $field = $this->latestChange('cms_block', '990001')['detail']['fields'][0];
        self::assertSame([SanitizerInterface::MASK, SanitizerInterface::MASK, true], [
            $field['before'],
            $field['after'],
            $field['masked'],
        ]);
    }

    public function testASettingTheConfigurationKeepsEncryptedIsMaskedWhateverItsPathIsCalled(): void
    {
        $path = $this->encryptedPathWithoutASecretName();

        $this->recorder()->record(
            $this->change()->withEntity('config_value', '990002')->withField($path, 'old-account', 'new-account')
        );

        $field = $this->latestChange('config_value', '990002')['detail']['fields'][0];
        self::assertSame($path, $field['field']);
        self::assertSame([SanitizerInterface::MASK, SanitizerInterface::MASK, true], [
            $field['before'],
            $field['after'],
            $field['masked'],
        ]);
    }

    #[Config(
        'hryvinskyi_admin_action_log/entity/types',
        '{"_1":{"entity_type":"cms_block","diff_mode":"off","retention_days":"30"}}'
    )]
    public function testAnEntitySwitchedOffIsNotRecorded(): void
    {
        $this->recorder()->record($this->change()->withEntity('cms_block', '990001')->withField('title', 'A', 'B'));

        self::assertSame(0, $this->countChanges('cms_block', '990001'));
    }

    #[Config(
        'hryvinskyi_admin_action_log/entity/types',
        '{"_1":{"entity_type":"cms_block","diff_mode":"snapshot","retention_days":"30"}}'
    )]
    public function testSnapshotModeKeepsTheUnchangedFieldsToo(): void
    {
        $this->recorder()->record(
            $this->change()
                ->withEntity('cms_block', '990001')
                ->withField('title', 'A', 'B')
                ->withField('content', 'C', 'C')
        );

        $detail = $this->latestChange('cms_block', '990001')['detail'];
        self::assertSame(DiffMode::Snapshot, $detail['mode']);
        self::assertSame(['title', 'content'], array_column($detail['fields'], 'field'));
    }

    #[Config('hryvinskyi_admin_action_log/entity/actor_types', 'admin')]
    public function testChangesByActorsNotChosenAreNotRecorded(): void
    {
        $this->recorder()->record($this->change()->withEntity('cms_block', '990001')->withField('title', 'A', 'B'));

        self::assertSame(0, $this->countChanges('cms_block', '990001'), 'Made from the command line');
    }

    #[Config('hryvinskyi_admin_action_log/entity/enabled', '0')]
    public function testNothingIsRecordedWhileEntityChangesAreOff(): void
    {
        $this->recorder()->record($this->change()->withEntity('cms_block', '990001')->withField('title', 'A', 'B'));

        self::assertSame(0, $this->countChanges('cms_block', '990001'));
    }

    public function testABlankChangeOrAnUntrackedEntityRecordsNothingAndFailsNothing(): void
    {
        $this->recorder()->record($this->change());
        $this->recorder()->record($this->change()->withEntity('warehouse_bin', '7')->withField('title', 'A', 'B'));

        self::assertSame(0, $this->countChanges('warehouse_bin', '7'));
    }

    #[DbIsolation(false)]
    public function testAChangeRecordedInATransactionThatRollsBackLeavesNoRecord(): void
    {
        $connection = self::service(ResourceConnection::class)->getConnection();
        $connection->beginTransaction();
        try {
            $this->recorder()->record(
                $this->change()->withEntity('cms_block', '990001')->withField('title', 'Kept?', 'No')
            );
            self::assertSame(1, $this->countChanges('cms_block', '990001'), 'Written inside the transaction');
        } finally {
            $connection->rollBack();
        }

        self::assertSame(0, $this->countChanges('cms_block', '990001'));
    }

    /**
     * A setting path the configuration saves through an encrypting backend model, whose name reads as no secret
     *
     * @return string
     */
    private function encryptedPathWithoutASecretName(): string
    {
        $structure = Bootstrap::getObjectManager()->get('adminhtmlConfigStructure');
        self::assertInstanceOf(Structure::class, $structure, 'The admin\'s configuration structure');
        $sanitizer = self::service(SanitizerInterface::class);
        foreach ($structure->getFieldPathsByAttribute('backend_model', Encrypted::class) as $structurePath) {
            self::assertIsString($structurePath);
            $field = $structure->getElement($structurePath);
            $configPath = $field instanceof Field ? $field->getConfigPath() : null;
            $path = is_string($configPath) && $configPath !== '' ? $configPath : $structurePath;
            if (!$sanitizer->isSecretField($path)) {
                return $path;
            }
        }

        self::fail('No encrypted setting whose path reads as no secret is declared.');
    }

    private function recorder(): EntityChangeRecorderInterface
    {
        return self::service(EntityChangeRecorderInterface::class);
    }

    private function change(): EntityChangeInterface
    {
        return self::service(EntityChangeInterfaceFactory::class)->create();
    }

    private function productId(): string
    {
        $product = DataFixtureStorageManager::getStorage()->get('product');
        self::assertInstanceOf(ProductInterface::class, $product);

        return (string) $product->getId();
    }

    /**
     * @param string $type
     * @param string $id
     * @return int
     */
    private function countChanges(string $type, string $id): int
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $count = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'), ['COUNT(*)'])
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', $type)
                ->where('subject_id = ?', $id)
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param string $type
     * @param string $id
     * @return array{action: string, subject: string, detail: ChangeDetail}
     */
    private function latestChange(string $type, string $id): array
    {
        $resource = self::service(ResourceConnection::class);
        $connection = $resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($resource->getTableName('hryvinskyi_activity_log'))
                ->where('channel = ?', 'entity')
                ->where('subject_type = ?', $type)
                ->where('subject_id = ?', $id)
                ->order('entity_id DESC')
                ->limit(1)
        );
        self::assertIsArray($row, 'The change was not journaled.');
        self::assertIsNumeric($row['entity_id'] ?? null);
        self::assertIsString($row['action'] ?? null);
        self::assertIsString($row['subject_label'] ?? null);
        $detail = self::service(EntityChangeStore::class)->find((int) $row['entity_id']);
        self::assertNotNull($detail, 'The change has no field detail.');

        return ['action' => $row['action'], 'subject' => $row['subject_label'], 'detail' => $detail];
    }

    /**
     * A service from the real container, typed
     *
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
