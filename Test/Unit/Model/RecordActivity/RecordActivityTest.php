<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\RecordActivity;

use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Hryvinskyi\ActivityLog\Api\Data\Search\EventViewInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\FieldChangeInterface;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\ActivityFactsInterface;
use Hryvinskyi\ActivityLog\Model\Data\Search\ActivityFact;
use Hryvinskyi\ActivityLog\Model\Data\Search\EventDetail;
use Hryvinskyi\ActivityLog\Model\Data\Search\EventView;
use Hryvinskyi\ActivityLog\Model\Data\Search\LinkPolicy;
use Hryvinskyi\ActivityLog\Model\Data\Search\SearchCount;
use Hryvinskyi\ActivityLog\Model\Data\Search\SearchPage;
use Hryvinskyi\ActivityLog\Model\Search\RecentDays;
use Hryvinskyi\AdminActionLog\Model\EntityChange\ValueKinds;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivityAccess;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\ActivitySummary;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecentFieldChanges;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecordPage;
use Hryvinskyi\AdminActionLog\Model\RecordActivity\RecordSearch;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\RecordHistory;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplayInterface;
use Hryvinskyi\AdminActionLog\Model\Search\Field\FieldDisplays;
use Hryvinskyi\AdminActionLog\Model\Search\Record\RecordKeysInterface;
use Hryvinskyi\AdminActionLog\Ui\Component\Control\ActivityButton;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\AuthorizationInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A record's activity on its edit page: which record the page edits, the summary of its last 30 days, the newest
 * change of each recently changed field, and the button that carries the count
 */
class RecordActivityTest extends TestCase
{
    private const NOW_MS = 1_792_670_700_000;

    public function testThePageEditsTheRecordOfAPositiveIdOnlyAndNamesItExactly(): void
    {
        $page = $this->page();

        self::assertSame('1245', $page->recordIdIn($this->request(['id' => '1245'])));
        self::assertSame('7', $page->recordIdIn($this->request(['id' => 7])));
        foreach ([null, '', '0', '-3', '12a', '1.5', ['1'], '0012'] as $notAnId) {
            self::assertNull($page->recordIdIn($this->request(['id' => $notAnId])), var_export($notAnId, true));
        }
        self::assertSame('#1245', $page->criterionValue('1245'));
    }

    public function testThePageShowsTheNaturalKeyOnlyOfAKindThatHasOne(): void
    {
        $keys = $this->createMock(RecordKeysInterface::class);
        $keys->method('keysOf')->willReturnCallback(
            static fn (array $ids): array => $ids === ['1245'] ? ['1245' => '24-MB01'] : []
        );
        $product = new RecordPage('product', 'product', 'id', 'record_product', 'All Store Views', $keys, 'SKU');

        self::assertSame(['label' => 'SKU', 'value' => '24-MB01'], $product->naturalKeyOf('1245'));
        self::assertNull($product->naturalKeyOf('9'), 'A record without the key');
        self::assertNull($this->page()->naturalKeyOf('1245'), 'A kind without one');
    }

    public function testAMalformedPageDeclarationFailsAtConstruction(): void
    {
        $keys = $this->createMock(RecordKeysInterface::class);
        $declarations = [
            static fn () => new RecordPage('Product', 'product', 'id', 'handle'),
            static fn () => new RecordPage('product', 'product', 'id"', 'handle'),
            static fn () => new RecordPage('product', 'product', 'id', 'handle with space'),
            static fn () => new RecordPage('product', 'product', 'id', 'handle', '', $keys),
            static fn () => new RecordPage('product', 'product', 'id', 'handle', '', null, 'SKU'),
        ];

        foreach ($declarations as $index => $declare) {
            try {
                $declare();
                self::fail('Declaration ' . $index . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheSummaryCountsTheLast30DaysAndNamesTheNewestChangeOnceARequest(): void
    {
        $newest = $this->event(self::NOW_MS - 60_000, 'john', []);
        $search = $this->recordSearch(
            [new SearchPage([$newest], null, false, new SearchCount(10000, true))],
            ActivitySummary::DAYS,
            1
        );
        $summary = new ActivitySummary($search);

        $expected = ['count' => 10000, 'atLeast' => true, 'latestMs' => self::NOW_MS - 60_000, 'latestActor' => 'john'];
        self::assertSame($expected, $summary->summaryOf($this->page(), '1245'));
        self::assertSame($expected, $summary->summaryOf($this->page(), '1245'), 'Asked again, from memory');

        $summary->_resetState();
        $empty = new ActivitySummary($this->recordSearch([new SearchPage([], null, false, new SearchCount(0))], 30, 1));
        self::assertSame(
            ['count' => 0, 'atLeast' => false, 'latestMs' => null, 'latestActor' => null],
            $empty->summaryOf($this->page(), '1245')
        );
    }

    public function testTheHintsKeepTheNewestChangeOfEachChangedFieldOfTheLastDaysAndOnlyShortValues(): void
    {
        $long = str_repeat('x', 300);
        $history = $this->createMock(RecordHistory::class);
        $history->expects(self::once())->method('recentActivityIds')->with('product', '1245')
            ->willReturn([30, 20, 10]);
        $history->expects(self::once())->method('changedFieldsOf')
            ->with(self::callback(static function (array $ids): bool {
                sort($ids);

                return $ids === [20, 30];
            }))
            ->willReturn([30 => ['price'], 20 => ['price', 'name', 'description', 'api_key', 'status']]);
        $history->expects(self::once())->method('changedValues')
            ->with(['price' => 30, 'name' => 20, 'description' => 20, 'api_key' => 20, 'status' => 20])
            ->willReturn([
                ['activityId' => 30, 'field' => 'price', 'before' => '35.00', 'after' => '39.00', 'masked' => false],
                ['activityId' => 20, 'field' => 'name', 'before' => $long, 'after' => 'Bag', 'masked' => false],
                ['activityId' => 20, 'field' => 'description', 'before' => 'a', 'after' => $long, 'masked' => false],
                ['activityId' => 20, 'field' => 'api_key', 'before' => '******', 'after' => '***', 'masked' => true],
                ['activityId' => 20, 'field' => 'status', 'before' => '1', 'after' => '2', 'masked' => false],
            ]);
        $changes = $this->recentFieldChanges($history, [
            30 => [self::NOW_MS - 1_000, 'john'],
            20 => [self::NOW_MS - 2_000, 'anna'],
            10 => [self::NOW_MS - 8 * 86_400_000, 'old'],
        ]);

        $hints = $changes->of($this->page(), '1245');

        self::assertSame(['price', 'api_key', 'description', 'name', 'status'], array_column($hints, 'field'));
        self::assertSame([
            'field' => 'price',
            'label' => 'Price',
            'ms' => self::NOW_MS - 1_000,
            'actor' => 'john',
            'before' => '35.00',
            'after' => '39.00',
            'kind' => 'short',
        ], $hints[0]);
        self::assertSame(['Enable Product', 'Disabled', 'Enabled'], [
            $hints[4]['label'],
            $hints[4]['before'],
            $hints[4]['after'],
        ], 'Named and valued as the admin sees them');
        self::assertSame(['name', 'long'], [$hints[3]['label'], $hints[3]['kind']], 'A long value is described');
        self::assertSame([null, null, 'long'], [$hints[2]['before'], $hints[2]['after'], $hints[2]['kind']]);
        self::assertSame([null, null, 'masked'], [$hints[1]['before'], $hints[1]['after'], $hints[1]['kind']]);
        self::assertSame($hints, $changes->of($this->page(), '1245'), 'Asked again, from memory');
    }

    public function testEveryFieldOfAChangeThatChangedThirtyFieldsGetsItsHint(): void
    {
        $fields = array_map(static fn (int $index): string => 'field_' . $index, range(1, 30));
        $history = $this->createMock(RecordHistory::class);
        $history->method('recentActivityIds')->willReturn([7]);
        $history->method('changedFieldsOf')->willReturn([7 => $fields]);
        $history->method('changedValues')->willReturnCallback(static fn (array $wanted): array => array_map(
            static fn (string $field): array => [
                'activityId' => 7,
                'field' => $field,
                'before' => '1',
                'after' => '2',
                'masked' => false,
            ],
            array_keys($wanted)
        ));

        $hints = $this->recentFieldChanges($history, [7 => [self::NOW_MS, 'john']])->of($this->page(), '1245');

        self::assertCount(30, $hints);
        self::assertEqualsCanonicalizing($fields, array_column($hints, 'field'));
    }

    public function testARecordWithoutChangesOfTheLastDaysHasNoHintAndReadsNoField(): void
    {
        $history = $this->createMock(RecordHistory::class);
        $history->method('recentActivityIds')->willReturn([10]);
        $history->method('changedFieldsOf')->with([])->willReturn([]);
        $history->expects(self::never())->method('changedValues');

        $hints = $this->recentFieldChanges($history, [10 => [self::NOW_MS - 8 * 86_400_000, 'old']])
            ->of($this->page(), '1245');

        self::assertSame([], $hints);
    }

    public function testTheButtonCarriesTheCountForAnExistingRecordAndAnAdminWhoMayReadTheLog(): void
    {
        $summary = new ActivitySummary($this->recordSearch([
            new SearchPage([$this->event(self::NOW_MS, 'john', [])], null, false, new SearchCount(5)),
        ], 30, 1));

        $data = $this->button(true, ['id' => '1245'], $summary)->getButtonData();

        $label = $data['label'] ?? null;
        self::assertInstanceOf(\Stringable::class, $label);
        self::assertSame('Activity', (string) $label);
        self::assertSame(
            ['role' => 'record-activity-open', 'count' => 5, 'count-at-least' => 'false'],
            $data['data_attribute'] ?? null
        );
        self::assertSame(ActivityAccess::RESOURCE, $data['aclResource'] ?? null);
    }

    public function testThereIsNoButtonAndNoSearchForANewRecordOrAnAdminWhoMayNotReadTheLog(): void
    {
        $search = $this->createMock(RecordSearch::class);
        $search->expects(self::never())->method('lastDays');
        $summary = new ActivitySummary($search);

        self::assertSame([], $this->button(false, ['id' => '1245'], $summary)->getButtonData());
        self::assertSame([], $this->button(true, [], $summary)->getButtonData());
    }

    /**
     * Recent field changes over these changes of the record, timed as given, with a display that names "status"
     *
     * @param RecordHistory $history
     * @param array<int, array{0: int, 1: string}> $facts Time and actor by activity id
     * @return RecentFieldChanges
     */
    private function recentFieldChanges(RecordHistory $history, array $facts): RecentFieldChanges
    {
        $journal = $this->createMock(ActivityFactsInterface::class);
        $journal->method('of')->willReturnCallback(
            static function (array $ids) use ($facts): array {
                $found = [];
                foreach ($ids as $id) {
                    if (is_int($id) && isset($facts[$id])) {
                        $found[$id] = (new ActivityFact())->withActivity($id, $facts[$id][0], $facts[$id][1]);
                    }
                }

                return $found;
            }
        );
        $display = $this->createMock(FieldDisplayInterface::class);
        $display->method('labelsOf')->willReturnCallback(
            static fn (array $codes): array => array_intersect_key(
                ['price' => 'Price', 'status' => 'Enable Product'],
                array_flip(array_filter($codes, 'is_string'))
            )
        );
        $display->method('valuesOf')->willReturnCallback(
            static fn (string $code, array $values): array => $code === 'status'
                ? array_intersect_key(
                    ['1' => 'Disabled', '2' => 'Enabled'],
                    array_flip(array_filter($values, 'is_string'))
                )
                : []
        );
        $recentDays = $this->createMock(RecentDays::class);
        $recentDays->method('startMs')->with(RecentFieldChanges::DAYS, self::NOW_MS)
            ->willReturn(self::NOW_MS - 7 * 86_400_000);
        $clock = $this->createMock(MillisecondClockInterface::class);
        $clock->method('nowMs')->willReturn(self::NOW_MS);

        return new RecentFieldChanges(
            $history,
            $journal,
            new FieldDisplays(['product' => $display]),
            new ValueKinds(),
            $recentDays,
            $clock
        );
    }

    /**
     * @return RecordPage
     */
    private function page(): RecordPage
    {
        return new RecordPage('product', 'product', 'id', 'record_product', 'All Store Views');
    }

    /**
     * @param array<string, mixed> $params
     * @return RequestInterface
     */
    private function request(array $params): RequestInterface
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(
            static fn (string $name): mixed => $params[$name] ?? null
        );

        return $request;
    }

    /**
     * A record search answering with these pages, one call each, for the given days and limit
     *
     * @param list<SearchPage> $pages
     * @param int $days
     * @param int $limit
     * @return RecordSearch&MockObject
     */
    private function recordSearch(array $pages, int $days, int $limit): RecordSearch
    {
        $search = $this->createMock(RecordSearch::class);
        $search->expects(self::exactly(count($pages)))
            ->method('lastDays')
            ->with(self::isInstanceOf(RecordPage::class), '1245', $days, $limit)
            ->willReturnOnConsecutiveCalls(...$pages);

        return $search;
    }

    /**
     * @param int $ms
     * @param string $actor
     * @param list<FieldChangeInterface> $fields
     * @return EventViewInterface
     */
    private function event(int $ms, string $actor, array $fields): EventViewInterface
    {
        return (new EventView(new LinkPolicy()))
            ->withActivity(1, $ms, 'entity', 'op')
            ->withActor(ActorType::Admin, $actor, null, null, null)
            ->withDetail((new EventDetail(new LinkPolicy()))->withFields($fields));
    }

    /**
     * @param bool $allowed
     * @param array<string, mixed> $params
     * @param ActivitySummary $summary
     * @return ActivityButton
     */
    private function button(bool $allowed, array $params, ActivitySummary $summary): ActivityButton
    {
        $authorization = $this->createMock(AuthorizationInterface::class);
        $authorization->method('isAllowed')->with(ActivityAccess::RESOURCE)->willReturn($allowed);

        return new ActivityButton(new ActivityAccess($authorization), $this->request($params), $summary, $this->page());
    }
}
