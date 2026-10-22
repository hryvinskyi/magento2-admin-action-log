<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\ActivityBuilderInterface;
use Hryvinskyi\ActivityLog\Api\ActivityRecorderInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActivityInterface;
use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterface;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\AdminActionLog\Api\Data\EntityScopeInterface;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\Data\EntityScope;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic\AutomaticFields;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic\SubmittedForm;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeWriter;
use Hryvinskyi\AdminActionLog\Model\EntityChange\Comparison\FieldEquivalences;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityTypeSettings;
use Hryvinskyi\AdminActionLog\Model\EntityChange\FieldDiffer;
use Hryvinskyi\AdminActionLog\Model\RequestSubjects;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use PHPUnit\Framework\TestCase;

/**
 * One change written into the journal and the change tables, over recording fakes of both
 *
 * @phpstan-import-type StoredField from EntityChangeStore
 */
class ChangeWriterTest extends TestCase
{
    /**
     * Actions and subject labels the journal was given
     *
     * @var list<array{action: string, subject: string}>
     */
    private array $journaled = [];

    /**
     * What the change tables were given
     *
     * @var list<array{entityId: string|null, mode: DiffMode, unchanged: int, scope: string, fields: list<StoredField>}>
     */
    private array $stored = [];

    /**
     * @var DiffMode
     */
    private DiffMode $mode = DiffMode::Changed;

    /**
     * @var int|null
     */
    private ?int $activityId = 7;

    /**
     * What the admin form being saved submitted
     *
     * @var SubmittedForm
     */
    private SubmittedForm $form;

    protected function setUp(): void
    {
        $this->form = new SubmittedForm();
    }

    public function testAChangeInWhichNoFieldChangedIsNotWritten(): void
    {
        $named = 0;
        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['quantity' => '100.0000', 'source_status' => '1'],
            ['quantity' => '100', 'source_status' => '1'],
            static function () use (&$named): string {
                $named++;

                return 'Product 24-MB01 · Joust Duffle Bag';
            },
            new EntityScope(),
            static fn (string $field): bool => false
        );

        self::assertSame([], $this->journaled, 'Decimals compare by value');
        self::assertSame([], $this->stored);
        self::assertSame(0, $named, 'Nothing written, nothing named');
    }

    public function testOnlyTheChangedFieldsAreKeptAndNamedAfterTheEntity(): void
    {
        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['quantity' => '100.0000', 'source_status' => '1'],
            ['quantity' => '95.0000', 'source_status' => '1'],
            static fn (): string => 'Product 24-MB01 · Joust Duffle Bag',
            (new EntityScope())->withValues('global', 0, 'Default Source'),
            static fn (string $field): bool => false
        );

        self::assertSame(
            [['action' => 'Product saved', 'subject' => 'Product 24-MB01 · Joust Duffle Bag · quantity']],
            $this->journaled
        );
        self::assertCount(1, $this->stored);
        self::assertSame('42', $this->stored[0]['entityId']);
        self::assertSame(1, $this->stored[0]['unchanged']);
        self::assertSame('Default Source', $this->stored[0]['scope']);
        self::assertSame(
            [[
                'field' => 'quantity',
                'before' => '100.0000',
                'after' => '95.0000',
                'changed' => true,
                'masked' => false,
                'automatic' => false,
            ]],
            $this->stored[0]['fields']
        );
    }

    public function testAChangedFieldTheAdminFormDidNotAskForIsMarkedAutomaticSecretsNever(): void
    {
        $this->form->begin([
            'id' => '42',
            'product' => ['price' => '39.00', 'gift_message_available' => '0', 'api_key' => 'new-secret'],
        ]);

        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['price' => '34.00', 'gift_message_available' => '0', 'quantity' => '1', 'api_key' => 'old', 'sku' => 'A'],
            ['price' => '39.00', 'gift_message_available' => '1', 'quantity' => '2', 'api_key' => 'new', 'sku' => 'A'],
            static fn (): string => 'Product #42',
            new EntityScope(),
            static fn (string $field): bool => $field === 'api_key'
        );

        self::assertSame(
            ['price' => false, 'gift_message_available' => true, 'quantity' => false, 'api_key' => false],
            array_column($this->stored[0]['fields'], 'automatic', 'field'),
            'Asked for, submitted as it was, not submitted (no field of the record), masked'
        );
    }

    public function testOutsideAnAdminFormNothingIsAutomatic(): void
    {
        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['gift_message_available' => '0'],
            ['gift_message_available' => '1'],
            static fn (): string => 'Product #42',
            new EntityScope(),
            static fn (string $field): bool => false
        );

        self::assertFalse($this->stored[0]['fields'][0]['automatic']);
    }

    public function testSnapshotModeKeepsTheUnchangedFieldsToo(): void
    {
        $this->mode = DiffMode::Snapshot;

        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['quantity' => '100.0000', 'source_status' => '1'],
            ['quantity' => '95.0000', 'source_status' => '1'],
            static fn (): string => 'Product #42',
            new EntityScope(),
            static fn (string $field): bool => false
        );

        self::assertSame(['quantity', 'source_status'], array_column($this->stored[0]['fields'], 'field'));
        self::assertSame(DiffMode::Snapshot, $this->stored[0]['mode']);
    }

    public function testSecretFieldsAreMaskedBeforeTheyAreStored(): void
    {
        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['api_key' => 'old-secret', 'quantity' => null],
            ['api_key' => 'new-secret', 'quantity' => '1.0000'],
            static fn (): string => 'Product #42',
            new EntityScope(),
            static fn (string $field): bool => $field === 'api_key'
        );

        $fields = $this->stored[0]['fields'];
        self::assertSame(
            ['field' => 'api_key', 'before' => SanitizerInterface::MASK, 'after' => SanitizerInterface::MASK,
                'changed' => true, 'masked' => true, 'automatic' => false],
            $fields[0]
        );
        self::assertNull($fields[1]['before'], 'No value stays no value');
    }

    public function testNothingIsStoredWhenTheJournalWroteNothing(): void
    {
        $this->activityId = null;

        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['quantity' => '1.0000'],
            ['quantity' => '2.0000'],
            static fn (): string => 'Product #42',
            new EntityScope(),
            static fn (string $field): bool => false
        );

        self::assertCount(1, $this->journaled);
        self::assertSame([], $this->stored);
    }

    public function testALongNameIsCutToWhatASubjectHolds(): void
    {
        $this->writer()->write(
            $this->entity(),
            '42',
            ChangeOperation::Update,
            ['quantity' => '1.0000'],
            ['quantity' => '2.0000'],
            static fn (): string => 'Product ' . str_repeat('x', 300),
            new EntityScope(),
            static fn (string $field): bool => false
        );

        self::assertSame(255, mb_strlen($this->journaled[0]['subject']));
        self::assertStringEndsWith('…', $this->journaled[0]['subject']);
    }

    private function writer(): ChangeWriter
    {
        $settings = $this->createMock(EntityTypeSettings::class);
        $settings->method('modeOf')->willReturnCallback(fn (): DiffMode => $this->mode);

        $builder = $this->createMock(ActivityBuilderInterface::class);
        $builder->method('build')->willReturnCallback(
            function (
                string $channel,
                string $action,
                Outcome $outcome,
                ?SubjectInterface $subject
            ): ActivityInterface {
                $this->journaled[] = ['action' => $action, 'subject' => $subject?->getLabel() ?? ''];

                return $this->createMock(ActivityInterface::class);
            }
        );

        $recorder = $this->createMock(ActivityRecorderInterface::class);
        $recorder->method('record')->willReturnCallback(fn (): ?int => $this->activityId);

        $subjects = $this->createMock(SubjectInterfaceFactory::class);
        $subjects->method('create')->willReturnCallback(function (array $data): SubjectInterface {
            $subject = $this->createMock(SubjectInterface::class);
            $label = $data['label'] ?? '';
            $subject->method('getLabel')->willReturn(is_string($label) ? $label : '');

            return $subject;
        });

        $store = $this->createMock(EntityChangeStore::class);
        $store->method('save')->willReturnCallback(
            function (
                int $activityId,
                string $entityType,
                ?string $entityId,
                ChangeOperation $operation,
                DiffMode $mode,
                int $unchangedCount,
                EntityScopeInterface $scope,
                array $fields
            ): void {
                /** @var list<StoredField> $fields */
                $this->stored[] = [
                    'entityId' => $entityId,
                    'mode' => $mode,
                    'unchanged' => $unchangedCount,
                    'scope' => $scope->getLabel(),
                    'fields' => $fields,
                ];
            }
        );

        $differ = new FieldDiffer(new FieldEquivalences());

        return new ChangeWriter(
            $settings,
            $differ,
            $builder,
            $recorder,
            $subjects,
            $store,
            new RequestSubjects(),
            new AutomaticFields($this->form, $differ, [
                'product' => ['id' => ['id'], 'values' => ['product[{field}]']],
            ])
        );
    }

    private function entity(): TrackedEntityInterface
    {
        $entity = $this->createMock(TrackedEntityInterface::class);
        $entity->method('getCode')->willReturn('product');
        $entity->method('getLabel')->willReturn('Product');

        return $entity;
    }
}
