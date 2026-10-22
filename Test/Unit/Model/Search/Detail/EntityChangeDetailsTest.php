<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\Search\Detail;

use Hryvinskyi\ActivityLog\Api\SanitizerInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\EventDetailInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\FieldChangeInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\FieldChangeInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\ValueKind;
use Hryvinskyi\ActivityLog\Api\Data\Search\Verb;
use Hryvinskyi\ActivityLog\Model\Data\Search\EventDetail;
use Hryvinskyi\ActivityLog\Model\Data\Search\FieldChange;
use Hryvinskyi\ActivityLog\Model\Data\Search\LinkPolicy;
use Hryvinskyi\AdminActionLog\Api\TrackedEntityInterface;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ChangeOperation;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\TrackedEntityPool;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueKinds;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\EditPage;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\EntityChangeDetails;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\SubjectLinks;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplayInterface;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;
use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordPresenceInterface;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\TestCase;

/**
 * Entity changes described for search results, over fakes of the store, the displays and the record links
 *
 * @phpstan-type Row array{
 *     field: string,
 *     before?: string|null,
 *     after?: string|null,
 *     changed?: bool,
 *     masked?: bool,
 *     automatic?: bool
 * }
 */
class EntityChangeDetailsTest extends TestCase
{
    /**
     * Values the product display was asked to name
     *
     * @var list<string>
     */
    private array $valuesAsked = [];

    /**
     * Fields the product display was asked to name values of, with the values, one entry per lookup
     *
     * @var list<array{0: string, 1: list<string>}>
     */
    private array $valueLookups = [];

    /**
     * Code lists the product display was asked to label, one entry per lookup
     *
     * @var list<list<string>>
     */
    private array $labelLookups = [];

    public function testDescribesAnUpdateOfOneFieldByItsLabelAndOptionNamedValues(): void
    {
        $details = $this->provider([
            10 => $this->change('product', '5', ChangeOperation::Update, [
                ['field' => 'status', 'before' => '1', 'after' => '2'],
            ]),
        ])->detailsFor([10]);

        $detail = $details[10] ?? null;
        self::assertInstanceOf(EventDetailInterface::class, $detail);
        self::assertSame(
            [Verb::Changed, 'Enable Product', 1, 'Product', '/admin/catalog/product/edit?id=5', 0, 0],
            [
                $detail->getVerb(),
                $detail->getWhat(),
                $detail->getFieldCount(),
                $detail->getSubjectTypeLabel(),
                $detail->getSubjectUrl(),
                $detail->getMoreFieldCount(),
                $detail->getUnchangedCount(),
            ]
        );
        self::assertSame(
            [['status', 'Enable Product', 'Default Store View', 'Enabled', 'Disabled', ValueKind::Short, true]],
            $this->rows($detail)
        );
    }

    public function testNamesTheRecordWithoutTheKindItsRecordedNameBeginsWith(): void
    {
        $update = [['field' => 'status', 'before' => '1', 'after' => '2']];
        $details = $this->provider([
            30 => $this->change('product', '5', ChangeOperation::Update, $update),
            31 => $this->change('product', '6', ChangeOperation::Update, $update),
            32 => $this->change('product', '7', ChangeOperation::Update, $update),
            33 => $this->change('product', '8', ChangeOperation::Update, $update),
            34 => $this->change('product', '9', ChangeOperation::Update, $update),
            35 => $this->change('order_comment', '3', ChangeOperation::Update, $update),
        ])->detailsFor([30, 31, 32, 33, 34, 35], [
            30 => 'Product 24-MB01 · Joust Duffle Bag',
            31 => 'Product #6',
            32 => 'Products of the week',
            33 => 'Product ',
            35 => 'Order comment on #100000012',
        ]);

        $shown = static fn (int $id): ?string => ($details[$id] ?? null)?->getSubjectLabel();
        self::assertSame(
            ['24-MB01 · Joust Duffle Bag', '#6', null, null, null, 'on #100000012'],
            array_map($shown, [30, 31, 32, 33, 34, 35]),
            'Old and new names lose the kind; a name merely starting with the word, a bare kind or none stay'
        );
    }

    public function testDropsATrailingListOfExactlyTheChangedFieldCodes(): void
    {
        $prices = [
            ['field' => 'special_price', 'before' => null, 'after' => '30.00'],
            ['field' => 'special_from_date', 'before' => null, 'after' => '2026-10-01'],
            ['field' => 'name', 'before' => 'Bag', 'after' => 'Bag', 'changed' => false],
        ];
        $details = $this->provider([
            40 => $this->change('product', '5', ChangeOperation::Update, $prices),
            41 => $this->change('product', '6', ChangeOperation::Update, $prices),
            42 => $this->change('product', '7', ChangeOperation::Update, $prices),
            43 => $this->change('product', '8', ChangeOperation::Update, $prices),
            44 => $this->change('product', '9', ChangeOperation::Update, $prices),
        ])->detailsFor([40, 41, 42, 43, 44], [
            40 => 'Product 24-MB01 · Joust Duffle Bag · special_price, special_from_date',
            41 => 'Product #6 · special_from_date, special_price',
            42 => 'Product 24-MB01 · Bag · special_price',
            43 => 'Product 24-MB01 · Bag · special_price, special_from_date, name',
            44 => 'Product 24-MB01 · Bag · special_price, special_from_d…',
        ]);

        $shown = static fn (int $id): ?string => ($details[$id] ?? null)?->getSubjectLabel();
        self::assertSame(
            [
                '24-MB01 · Joust Duffle Bag',
                '#6',
                '24-MB01 · Bag · special_price',
                '24-MB01 · Bag · special_price, special_from_date, name',
                '24-MB01 · Bag · special_price, special_from_d…',
            ],
            array_map($shown, [40, 41, 42, 43, 44]),
            'Only a list naming every changed field and nothing else goes, in any order; a cut list stays'
        );
    }

    public function testShowsWhatNoDisplayKnowsAsStoredAndNeverResolvesAMaskedValue(): void
    {
        $details = $this->provider([
            11 => $this->change('product', '6', ChangeOperation::Update, [
                ['field' => 'custom_code', 'before' => 'a', 'after' => 'b'],
                ['field' => 'status', 'before' => '7', 'after' => '1'],
                ['field' => 'api_key', 'before' => '••••••', 'after' => '••••••', 'masked' => true],
            ]),
            12 => $this->change('order_comment', '9', ChangeOperation::Create, [
                ['field' => 'comment', 'before' => null, 'after' => 'Shipped'],
            ]),
        ])->detailsFor([11, 12]);

        self::assertSame([
            ['custom_code', 'custom_code', 'Default Store View', 'a', 'b', ValueKind::Short, true],
            ['status', 'Enable Product', 'Default Store View', '7', 'Enabled', ValueKind::Short, true],
            ['api_key', 'api_key', 'Default Store View', '••••••', '••••••', ValueKind::Masked, true],
        ], $this->rows($details[11] ?? null));
        self::assertNotContains('••••••', $this->valuesAsked, 'A masked value is never given to a display');
        $several = $details[11] ?? null;
        self::assertInstanceOf(EventDetailInterface::class, $several);
        self::assertSame(
            [null, 3, null],
            [$several->getWhat(), $several->getFieldCount(), $several->getSubjectUrl()],
            'Several fields are counted; a record that no longer exists gets no link'
        );

        $comment = $details[12] ?? null;
        self::assertInstanceOf(EventDetailInterface::class, $comment);
        self::assertSame([Verb::Created, null, 1, 'Order comment', null], [
            $comment->getVerb(),
            $comment->getWhat(),
            $comment->getFieldCount(),
            $comment->getSubjectTypeLabel(),
            $comment->getSubjectUrl(),
        ], 'A creation names no field; a kind without an edit page gets no link');
        self::assertSame(
            [['comment', 'comment', 'Default Store View', null, 'Shipped', ValueKind::Short, true]],
            $this->rows($comment)
        );
        self::assertSame([['custom_code', 'status', 'api_key']], $this->labelLookups, 'One label lookup per kind');
    }

    public function testFieldsSetAutomaticallyAreMarkedCountedApartAndNeverTheOneTheSentenceNames(): void
    {
        $label = static fn (string $field): array => [
            'field' => $field,
            'before' => 'Joust Duffle Bag',
            'after' => null,
            'automatic' => true,
        ];
        $details = $this->provider([
            21 => $this->change('product', '5', ChangeOperation::Update, [
                $label('image_label'),
                ['field' => 'price', 'before' => '34.00', 'after' => '39.00'],
                $label('thumbnail_label'),
            ]),
            22 => $this->change('product', '5', ChangeOperation::Update, [
                $label('image_label'),
                $label('thumbnail_label'),
            ]),
            23 => $this->change('product', '5', ChangeOperation::Update, [
                ['field' => 'gift_message_available', 'before' => null, 'after' => '0', 'automatic' => true],
            ]),
        ])->detailsFor([21, 22, 23]);

        $asked = $details[21] ?? null;
        self::assertInstanceOf(EventDetailInterface::class, $asked);
        self::assertSame(['price', 3, 2], [$asked->getWhat(), $asked->getFieldCount(), $asked->getAutomaticCount()]);
        self::assertSame(
            [['price', false], ['image_label', true], ['thumbnail_label', true]],
            array_map(
                static fn (FieldChangeInterface $row): array => [$row->getName(), $row->isAutomatic()],
                $asked->getFields()
            ),
            'The field asked for comes first, each row says whether it was set automatically'
        );

        $automatic = $details[22] ?? null;
        self::assertInstanceOf(EventDetailInterface::class, $automatic);
        self::assertSame([null, 2, 2], [
            $automatic->getWhat(),
            $automatic->getFieldCount(),
            $automatic->getAutomaticCount(),
        ], 'Nothing asked for: the sentence counts the fields set automatically');

        $one = $details[23] ?? null;
        self::assertInstanceOf(EventDetailInterface::class, $one);
        self::assertSame(['gift_message_available', 1], [$one->getWhat(), $one->getAutomaticCount()]);
    }

    public function testListsChangedFieldsFirstUpToTheLimitAndCountsTheRest(): void
    {
        $fields = [];
        for ($i = 1; $i <= 22; $i++) {
            $fields[] = ['field' => 'field_' . $i, 'before' => 'a', 'after' => 'b'];
        }
        $fields[] = ['field' => 'kept', 'before' => 'same', 'after' => 'same', 'changed' => false];

        $detail = $this->provider([
            13 => $this->change('product', '5', ChangeOperation::Delete, $fields, 4),
        ])->detailsFor([13])[13] ?? null;

        self::assertInstanceOf(EventDetailInterface::class, $detail);
        self::assertCount(EntityChangeDetails::ROW_LIMIT, $detail->getFields());
        self::assertSame(
            [Verb::Deleted, null, 22, 2, 1, true],
            [
                $detail->getVerb(),
                $detail->getWhat(),
                $detail->getFieldCount(),
                $detail->getMoreFieldCount(),
                $detail->getUnchangedCount(),
                $detail->areUnchangedFieldsRecorded(),
            ],
            'Fields that kept their value are counted by the rows recorded for them'
        );
    }

    public function testKeepsRowsOfUnchangedFieldsAfterTheChangedOnesAndCountsAllOfThem(): void
    {
        $detail = $this->provider([
            14 => $this->change('product', '5', ChangeOperation::Update, [
                ['field' => 'name', 'before' => 'Bag', 'after' => 'Bag', 'changed' => false],
                ['field' => 'price', 'before' => '1', 'after' => '2'],
                ['field' => 'sku', 'before' => 'A', 'after' => 'A', 'changed' => false],
            ], 1),
        ])->detailsFor([14])[14] ?? null;

        self::assertInstanceOf(EventDetailInterface::class, $detail);
        self::assertSame(['price', 'name', 'sku'], array_map(
            static fn (FieldChangeInterface $row): string => $row->getName(),
            $detail->getFields()
        ));
        self::assertSame(
            ['price', 0, 2, true],
            [
                $detail->getWhat(),
                $detail->getMoreFieldCount(),
                $detail->getUnchangedCount(),
                $detail->areUnchangedFieldsRecorded(),
            ]
        );
    }

    public function testCountsFieldsThatKeptTheirValueWithoutRowsAsANumberWithNothingToOpen(): void
    {
        $detail = $this->provider([
            15 => $this->change('product', '5', ChangeOperation::Update, [
                ['field' => 'price', 'before' => '1', 'after' => '2'],
            ], 39),
        ])->detailsFor([15])[15] ?? null;

        self::assertInstanceOf(EventDetailInterface::class, $detail);
        self::assertSame([39, false], [$detail->getUnchangedCount(), $detail->areUnchangedFieldsRecorded()]);
    }

    public function testNamesTheValuesOfAPageOneLookupPerField(): void
    {
        $this->provider([
            16 => $this->change('product', '5', ChangeOperation::Update, [
                ['field' => 'status', 'before' => '1', 'after' => '2'],
                ['field' => 'price', 'before' => '1', 'after' => null],
            ]),
            17 => $this->change('product', '6', ChangeOperation::Update, [
                ['field' => 'status', 'before' => '2', 'after' => '1'],
                ['field' => 'api_key', 'before' => '••••••', 'after' => '••••••', 'masked' => true],
            ]),
        ])->detailsFor([16, 17]);

        self::assertSame(
            [['status', ['1', '2']], ['price', ['1']]],
            $this->valueLookups,
            'Each field\'s values are named together, each value once; none and masked values are never asked'
        );
    }

    public function testDescribesNothingForActivitiesWithoutAChange(): void
    {
        self::assertSame([], $this->provider([])->detailsFor([20, 21]));
        self::assertTrue($this->provider([])->supports('entity'));
        self::assertFalse($this->provider([])->supports('admin'));
    }

    /**
     * A stored change, as the store answers for it
     *
     * @param string $type
     * @param string|null $id
     * @param ChangeOperation $operation
     * @param list<Row> $fields
     * @param int $unchangedCount
     * @return array{summary: array<string, mixed>, fields: list<array<string, mixed>>}
     */
    private function change(
        string $type,
        ?string $id,
        ChangeOperation $operation,
        array $fields,
        int $unchangedCount = 0
    ): array {
        return [
            'summary' => [
                'entityType' => $type,
                'entityId' => $id,
                'operation' => $operation,
                'mode' => DiffMode::Changed,
                'unchangedCount' => $unchangedCount,
            ],
            'fields' => array_map(static fn (array $field): array => [
                'scopeType' => 'store',
                'scopeId' => 1,
                'scopeLabel' => 'Default Store View',
                'field' => $field['field'],
                'before' => $field['before'] ?? null,
                'after' => $field['after'] ?? null,
                'changed' => $field['changed'] ?? true,
                'masked' => $field['masked'] ?? false,
                'automatic' => $field['automatic'] ?? false,
            ], $fields),
        ];
    }

    /**
     * The provider over a store holding the changes, a product display, and product 5 as the one existing record
     *
     * @param array<int, array{summary: array<string, mixed>, fields: list<array<string, mixed>>}> $changes
     * @return EntityChangeDetails
     */
    private function provider(array $changes): EntityChangeDetails
    {
        $store = $this->createMock(EntityChangeStore::class);
        $store->method('changesOf')->willReturnCallback(
            static fn (array $ids): array => array_map(
                static fn (array $change): array => $change['summary'],
                array_intersect_key($changes, array_flip(self::ints($ids)))
            )
        );
        $store->method('fieldsOf')->willReturnCallback(
            static function (array $ids, int $limit) use ($changes): array {
                $pages = [];
                foreach (array_intersect_key($changes, array_flip(self::ints($ids))) as $id => $change) {
                    $rows = $change['fields'];
                    usort(
                        $rows,
                        static fn (array $a, array $b): int => [$b['changed'] === true, $a['automatic'] === true]
                            <=> [$a['changed'] === true, $b['automatic'] === true]
                    );
                    $changed = count(array_filter($rows, static fn (array $row): bool => $row['changed'] === true));
                    $pages[$id] = [
                        'rows' => array_slice($rows, 0, $limit),
                        'changed' => $changed,
                        'automatic' => count(array_filter(
                            $rows,
                            static fn (array $row): bool => $row['changed'] === true && $row['automatic'] === true
                        )),
                        'unchanged' => count($rows) - $changed,
                        'changedFields' => array_values(array_map(
                            static fn (array $row): string => is_string($row['field']) ? $row['field'] : '',
                            array_filter($rows, static fn (array $row): bool => $row['changed'] === true)
                        )),
                    ];
                }

                return $pages;
            }
        );

        $details = $this->createMock(EventDetailInterfaceFactory::class);
        $details->method('create')->willReturnCallback(static fn (): EventDetail => new EventDetail(new LinkPolicy()));
        $fields = $this->createMock(FieldChangeInterfaceFactory::class);
        $fields->method('create')->willReturnCallback(static fn (): FieldChange => new FieldChange());

        return new EntityChangeDetails(
            $store,
            new TrackedEntityPool($this->createStub(SanitizerInterface::class), [
                'product' => $this->entity('product', 'Product'),
                'order_comment' => $this->entity('order_comment', 'Order comment'),
            ]),
            new FieldDisplays(['product' => $this->productDisplay()]),
            $this->links(),
            new ValueKinds(),
            $details,
            $fields
        );
    }

    /**
     * A display that knows the product status attribute and its two options
     *
     * @return FieldDisplayInterface
     */
    private function productDisplay(): FieldDisplayInterface
    {
        $display = $this->createMock(FieldDisplayInterface::class);
        $display->method('labelsOf')->willReturnCallback(function (array $codes): array {
            $codes = self::strings($codes);
            $this->labelLookups[] = $codes;

            return array_intersect_key(['status' => 'Enable Product'], array_flip($codes));
        });
        $display->method('valuesOf')->willReturnCallback(function (string $code, array $values): array {
            $values = self::strings($values);
            $this->valueLookups[] = [$code, $values];
            array_push($this->valuesAsked, ...$values);

            return $code === 'status'
                ? array_intersect_key(['1' => 'Enabled', '2' => 'Disabled'], array_flip($values))
                : [];
        });

        return $display;
    }

    /**
     * Links to product edit pages, of which product 5 exists, for an admin allowed everything
     *
     * @return SubjectLinks
     */
    private function links(): SubjectLinks
    {
        $records = $this->createMock(RecordPresenceInterface::class);
        $records->method('existing')->willReturnCallback(
            static fn (array $ids): array => array_values(array_intersect(self::strings($ids), ['5']))
        );
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(
            static fn (mixed $route, mixed $params): string => '/admin/' . (is_string($route) ? $route : '') . '?'
                . http_build_query(is_array($params) ? $params : [])
        );
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->willReturn(true);

        return new SubjectLinks($url, $authorization, [
            'product' => new EditPage($records, 'catalog/product/edit', 'id', 'Magento_Catalog::products'),
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

    /**
     * Each row of a detail as name, label, scope, before, after, kind and whether it changed
     *
     * @param EventDetailInterface|null $detail
     * @return list<array{string, string, string|null, string|null, string|null, ValueKind, bool}>
     */
    private function rows(?EventDetailInterface $detail): array
    {
        self::assertInstanceOf(EventDetailInterface::class, $detail);

        return array_map(static fn (FieldChangeInterface $row): array => [
            $row->getName(),
            $row->getLabel(),
            $row->getScope(),
            $row->getBefore(),
            $row->getAfter(),
            $row->getKind(),
            $row->isChanged(),
        ], $detail->getFields());
    }

    /**
     * The integers of a list a fake receives
     *
     * @param array<mixed> $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $ints = [];
        foreach ($values as $value) {
            if (is_int($value)) {
                $ints[] = $value;
            }
        }

        return $ints;
    }

    /**
     * The strings of a list a fake receives
     *
     * @param array<mixed> $values
     * @return list<string>
     */
    private static function strings(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
