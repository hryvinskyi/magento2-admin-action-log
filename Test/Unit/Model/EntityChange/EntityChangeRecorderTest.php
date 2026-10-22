<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityChangeInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\SecretFieldsInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\Data\EntityChange;
use Hryvinskyi\AdminActionLog\Model\Data\EntityScope;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeWriter;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityChangeRecorder;
use Hryvinskyi\AdminActionLog\Model\EntityChange\RecordingRules;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Storage\StoredRecordsInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Magento\Framework\Model\AbstractModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Changes made without a model go through the rules and the writer of saved models, and never fail the caller
 */
class EntityChangeRecorderTest extends TestCase
{
    /**
     * What the writer was given
     *
     * @var list<array{
     *     code: string,
     *     id: string|null,
     *     operation: ChangeOperation,
     *     before: array<string, string|null>,
     *     after: array<string, string|null>,
     *     name: string,
     *     scope: string,
     *     secret: array<string, bool>
     * }>
     */
    private array $written = [];

    /**
     * Messages logged as errors
     *
     * @var list<string>
     */
    private array $errors = [];

    /**
     * @var bool
     */
    private bool $recorded = true;

    /**
     * The ids of each read of records
     *
     * @var list<list<string>>
     */
    private array $reads = [];

    /**
     * The id whose change the writer fails on
     *
     * @var string|null
     */
    private ?string $failOn = null;

    /**
     * @var \Throwable|null
     */
    private ?\Throwable $failure = null;

    public function testAChangeIsWrittenUnderTheEntityNamedFromItsRecord(): void
    {
        $product = $this->createMock(AbstractModel::class);
        $this->recorder(['42' => $product])->record($this->quantityChange());

        self::assertCount(1, $this->written);
        $written = $this->written[0];
        self::assertSame(['product', '42'], [$written['code'], $written['id']]);
        self::assertSame(ChangeOperation::Update, $written['operation']);
        self::assertSame(['quantity' => '100.0000', 'source_status' => '1'], $written['before']);
        self::assertSame(['quantity' => '95.0000', 'source_status' => '1'], $written['after']);
        self::assertSame('Product 24-MB01 · Joust Duffle Bag', $written['name'], 'Named as its saved models are');
        self::assertSame('Default Source', $written['scope']);
        self::assertSame(
            ['quantity' => false, 'password' => true, 'carriers/courier/account' => false],
            $written['secret'],
            'Secret by the pool\'s rule'
        );
        self::assertSame([['42']], $this->reads, 'Only the record the call names is asked for');
    }

    public function testTheRecordsTheChangesOfOneCallNameAreReadTogether(): void
    {
        $product = $this->createMock(AbstractModel::class);
        $this->recorder(['42' => $product, '43' => $product])->recordAll([
            $this->quantityChange(),
            $this->quantityChange()->withEntity('product', '43'),
            $this->quantityChange()->withEntity('product', '44'),
        ]);

        self::assertCount(3, $this->written);
        self::assertSame(
            [['42', '43', '44']],
            $this->reads,
            'Each name asks for every record the call names, so they are read together'
        );
        self::assertSame('Product #44', $this->written[2]['name']);
    }

    public function testASettingKeptEncryptedIsMaskedByItsPathWithoutAnyRecord(): void
    {
        $this->recorder([], ['config_value' => ['carriers/courier/account']])->record(
            $this->blank()->withEntity('config_value', '7')->withField('carriers/courier/account', 'a', 'b')
        );

        self::assertTrue($this->written[0]['secret']['carriers/courier/account']);
        self::assertSame([], $this->reads, 'Nothing is read to tell a secret');
    }

    public function testAnEntityWhoseRecordIsGoneIsNamedByItsLabelAndId(): void
    {
        $this->recorder([])->record($this->quantityChange());

        self::assertSame('Product #42', $this->written[0]['name']);
        self::assertSame(
            ['quantity' => false, 'password' => true, 'carriers/courier/account' => false],
            $this->written[0]['secret'],
            'The journal\'s secret fields are masked still'
        );
    }

    public function testOneChangeThatFailsNeverKeepsTheOthersOfTheCallFromBeingRecorded(): void
    {
        $this->failOn = '43';

        $this->recorder([])->recordAll([
            $this->quantityChange(),
            $this->quantityChange()->withEntity('product', '43'),
            $this->quantityChange()->withEntity('product', '44'),
        ]);

        self::assertSame(['42', '44'], array_column($this->written, 'id'));
        self::assertSame(['The change of product could not be recorded.'], $this->errors);
    }

    public function testNothingIsRecordedWhenTheRulesSayNo(): void
    {
        $this->recorded = false;

        $this->recorder([])->record($this->quantityChange());

        self::assertSame([], $this->written);
        self::assertSame([], $this->reads, 'Nothing recorded, nothing read');
    }

    public function testABlankChangeOrAnUntrackedEntityRecordsNothing(): void
    {
        $recorder = $this->recorder([]);
        $recorder->record($this->blank());
        $recorder->record($this->blank()->withEntity('warehouse_bin', '7')->withField('quantity', '1', '2'));

        self::assertSame([], $this->written);
        self::assertSame([], $this->errors);
    }

    public function testTheOperationGivenIsTheOperationWritten(): void
    {
        $this->recorder([])->record(
            $this->quantityChange()->withOperation(EntityChangeInterface::OPERATION_DELETE)
        );

        self::assertSame(ChangeOperation::Delete, $this->written[0]['operation']);
    }

    public function testAFailureToRecordIsLoggedAndNeverReachesTheCaller(): void
    {
        $this->failure = new \RuntimeException('Deadlock found when trying to get lock');

        $this->recorder([])->record($this->quantityChange());

        self::assertSame(['The change of product could not be recorded.'], $this->errors);
    }

    private function quantityChange(): EntityChangeInterface
    {
        return $this->blank()
            ->withEntity('product', '42')
            ->withScope(EntityScopeInterface::TYPE_GLOBAL, 0, 'Default Source')
            ->withField('quantity', '100.0000', '95.0000')
            ->withField('source_status', '1', '1');
    }

    private function blank(): EntityChangeInterface
    {
        return new EntityChange(new EntityScope());
    }

    /**
     * @param array<int|string, AbstractModel> $products Stored products by id
     * @param array<string, list<string>> $secretFields Fields secret by the entity's nature, by entity code
     * @return EntityChangeRecorder
     */
    private function recorder(array $products, array $secretFields = []): EntityChangeRecorder
    {
        $entities = [];
        foreach (['product' => 'Product', 'config_value' => 'Config value'] as $code => $label) {
            $entity = $this->createMock(TrackedEntityInterface::class);
            $entity->method('getCode')->willReturn($code);
            $entity->method('getLabel')->willReturn($label);
            $entity->method('describe')->willReturn('Product 24-MB01 · Joust Duffle Bag');
            $entity->expects(self::never())->method('isSecretField');
            $entities[$code] = $entity;
        }

        $rules = $this->createMock(RecordingRules::class);
        $rules->method('records')->willReturnCallback(fn (): bool => $this->recorded);

        $writer = $this->createMock(ChangeWriter::class);
        $writer->method('write')->willReturnCallback(
            function (
                TrackedEntityInterface $entity,
                ?string $entityId,
                ChangeOperation $operation,
                array $before,
                array $after,
                callable $name,
                EntityScopeInterface $scope,
                callable $isSecret
            ): void {
                if ($this->failure !== null) {
                    throw $this->failure;
                }

                if ($this->failOn !== null && $entityId === $this->failOn) {
                    throw new \RuntimeException('Deadlock found when trying to get lock');
                }

                /** @var array<string, string|null> $before */
                /** @var array<string, string|null> $after */
                $this->written[] = [
                    'code' => $entity->getCode(),
                    'id' => $entityId,
                    'operation' => $operation,
                    'before' => $before,
                    'after' => $after,
                    'name' => is_string($named = $name()) ? $named : '',
                    'scope' => $scope->getLabel(),
                    'secret' => [
                        'quantity' => (bool) $isSecret('quantity'),
                        'password' => (bool) $isSecret('password'),
                        'carriers/courier/account' => (bool) $isSecret('carriers/courier/account'),
                    ],
                ];
            }
        );

        $sanitizer = $this->createMock(SanitizerInterface::class);
        $sanitizer->method('isSecretField')->willReturnCallback(
            static fn (string $field): bool => $field === 'password'
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('error')->willReturnCallback(function (string|\Stringable $message): void {
            $this->errors[] = (string) $message;
        });

        $records = $this->createMock(StoredRecordsInterface::class);
        $records->expects(self::never())->method('record');
        $records->method('records')->willReturnCallback(function (array $ids) use ($products): array {
            /** @var list<string> $ids */
            if ($this->reads === [] || end($this->reads) !== $ids) {
                $this->reads[] = $ids;
            }

            return array_intersect_key($products, array_flip($ids));
        });

        $secrets = [];
        foreach ($secretFields as $code => $fields) {
            $secret = $this->createMock(SecretFieldsInterface::class);
            $secret->method('isSecretField')->willReturnCallback(
                static fn (string $field): bool => in_array($field, $fields, true)
            );
            $secrets[$code] = $secret;
        }

        return new EntityChangeRecorder(
            new TrackedEntityPool($sanitizer, $entities, [], $secrets),
            $rules,
            $writer,
            $logger,
            ['product' => $records]
        );
    }
}
