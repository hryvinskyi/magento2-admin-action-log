<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Performance;

use Hryvinskyi\ActivityLog\Api\Data\Search\SearchCriterionInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchPageInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchRequestInterface;
use Hryvinskyi\ActivityLog\Api\Data\Search\SearchRequestInterfaceFactory;
use Hryvinskyi\ActivityLog\Api\Search\ActivitySearchInterface;
use Hryvinskyi\ActivityLog\Model\Search\CriteriaResolver;
use Hryvinskyi\ActivityLog\Model\Search\Query\SearchQueryBuilder;
use Hryvinskyi\ActivityLog\Model\Search\Query\TimeScope;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\EntityChangeDetails;
use Hryvinskyi\AdminActionLog\Model\Search\Detail\SubjectLinks;
use Hryvinskyi\AdminActionLog\Test\Integration\Performance\Fixture\MillionChanges;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The searches the log page, the record panels and the setting history send, with the entity change keys the
 * container registers, over a million activities and their change rows
 *
 * Each shape is a whole search — the page and every count it carries — timed as the median of five runs after a
 * warm-up, and must answer within BUDGET_MS (eight one-letter words, the worst plain text a person can send, within
 * WORST_TEXT_BUDGET_MS); each search's page carries its event details. Building the details of a
 * page of fifty changes alone must take under DETAILS_BUDGET_MS. The page query's plan and the timings go to standard
 * error.
 */
#[DbIsolation(false)]
#[AppArea('adminhtml')]
class EntitySearchPerformanceTest extends TestCase
{
    private const BUDGET_MS = 300.0;

    /**
     * Budget of the worst plain-text filter a person can send: eight one-letter words, each a group of its own
     */
    private const WORST_TEXT_BUDGET_MS = 1000.0;
    private const DETAILS_BUDGET_MS = 100.0;
    private const DAY_MS = 86_400_000;
    private const RUNS = 5;

    public static function setUpBeforeClass(): void
    {
        self::changes()->seed();
    }

    public static function tearDownAfterClass(): void
    {
        self::changes()->remove();
    }

    /**
     * @return array<string, array{0: int, 1: list<array{0: string, 1: string}>, 2?: float}>
     */
    public static function shapes(): array
    {
        return [
            'last 7 days, no filters' => [7, []],
            'last 30 days, one product by id' => [30, [['product', '#recent']]],
            'all time, one product by id' => [0, [['product', '#recent']]],
            'all time, one setting by its exact path' => [0, [['config', MillionChanges::RARE_PATH]]],
            'all time, a group of settings' => [0, [['config', 'aalperf/rare']]],
            'last 30 days, text in a value' => [30, [['value', 'cotton']]],
            'last 30 days, a word in nearly every activity' => [30, [['text', 'saved']]],
            'last 30 days, a word in no activity' => [30, [['text', 'zaltz']]],
            'last 30 days, one word in the journal and one in a value' => [30, [['text', 'saved cotton']]],
            'last 30 days, a filter of eight single letters' => [
                30,
                [['text', 'a b c d e f g h i j k l m']],
                self::WORST_TEXT_BUDGET_MS,
            ],
            'last 30 days, a field' => [30, [['field', 'price']]],
            'last 30 days, a field by its label' => [30, [['field', 'Enable Product']]],
            'last 30 days, a kind of entity' => [30, [['entity', 'category']]],
            'last 7 days, a website scope' => [7, [['store', 'Main Website']]],
            'last 30 days, a product no longer in the catalog' => [30, [['product', 'aal-perf-retired']]],
        ];
    }

    /**
     * @param int $days 0 for all time
     * @param list<array{0: string, 1: string}> $chips
     * @param float $budgetMs
     */
    #[DataProvider('shapes')]
    public function testAnswersWithinBudget(int $days, array $chips, float $budgetMs = self::BUDGET_MS): void
    {
        $chips = array_map(
            static fn (array $chip): array => $chip[1] === '#recent'
                ? [$chip[0], '#' . self::changes()->recentProduct()]
                : $chip,
            $chips
        );
        $request = $this->request($days === 0 ? 0 : MillionChanges::NOW_MS - $days * self::DAY_MS, $chips);
        $engine = self::service(ActivitySearchInterface::class);

        $engine->search($request);
        $timings = [];
        $page = null;
        for ($run = 0; $run < self::RUNS; $run++) {
            $started = hrtime(true);
            $page = $engine->search($request);
            $timings[] = (hrtime(true) - $started) / 1e6;
        }
        sort($timings);
        $median = $timings[intdiv(self::RUNS, 2)];

        self::assertInstanceOf(SearchPageInterface::class, $page);
        $this->report((string) $this->dataName(), $request, $median, $page);
        self::assertLessThan($budgetMs, $median, sprintf('Median %.1f ms', $median));
    }

    /**
     * The details of the newest page of fifty changes: field rows, labels, values named by their options and links to
     * the records, for an admin allowed every edit page, so that every record kind's existence is checked
     */
    public function testDescribesAPageOfChangesWithinBudget(): void
    {
        $connection = self::service(ResourceConnection::class)->getConnection();
        $ids = [];
        foreach ($connection->fetchCol(
            $connection->select()
                ->from(self::service(ResourceConnection::class)->getTableName('hryvinskyi_activity_log'), ['entity_id'])
                ->where('channel = ?', 'entity')
                ->where('occurred_at_ms <= ?', MillionChanges::NOW_MS)
                ->order(['occurred_at_ms DESC', 'entity_id DESC'])
                ->limit(50)
        ) as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }
        self::assertCount(50, $ids);

        $allowed = $this->createStub(AuthorizationInterface::class);
        $allowed->method('isAllowed')->willReturn(true);
        $objectManager = Bootstrap::getObjectManager();
        $details = $objectManager->create(EntityChangeDetails::class, [
            'subjectLinks' => $objectManager->create(SubjectLinks::class, ['authorization' => $allowed]),
        ]);
        self::assertInstanceOf(EntityChangeDetails::class, $details);

        $details->detailsFor($ids);
        $timings = [];
        $described = [];
        for ($run = 0; $run < self::RUNS; $run++) {
            $started = hrtime(true);
            $described = $details->detailsFor($ids);
            $timings[] = (hrtime(true) - $started) / 1e6;
        }
        sort($timings);
        $median = $timings[intdiv(self::RUNS, 2)];

        fwrite(STDERR, sprintf(
            "\n[entity search performance] details of a page of %d changes: median %.1f ms\n",
            count($described),
            $median
        ));
        self::assertCount(50, $described);
        self::assertLessThan(self::DETAILS_BUDGET_MS, $median, sprintf('Median %.1f ms', $median));
    }

    /**
     * A request with "changes only", as the log page sends it
     *
     * @param int $fromMs
     * @param list<array{0: string, 1: string}> $chips
     * @return SearchRequestInterface
     */
    private function request(int $fromMs, array $chips): SearchRequestInterface
    {
        $criteria = [];
        foreach ($chips as [$key, $value]) {
            $criteria[] = self::service(SearchCriterionInterfaceFactory::class)->create()->withTerm($key, $value);
        }

        return self::service(SearchRequestInterfaceFactory::class)->create()
            ->withRange($fromMs, MillionChanges::NOW_MS)
            ->withCriteria($criteria)
            ->withChangesOnly(true)
            ->withLimit(50);
    }

    /**
     * Write the shape's timing and the page query's plan to standard error
     *
     * @param string $shape
     * @param SearchRequestInterface $request
     * @param float $medianMs
     * @param SearchPageInterface $page
     * @return void
     */
    private function report(
        string $shape,
        SearchRequestInterface $request,
        float $medianMs,
        SearchPageInterface $page
    ): void {
        $criteria = self::service(CriteriaResolver::class)->resolve($request);
        $select = self::service(SearchQueryBuilder::class)->matches($request, $criteria, TimeScope::InRange, true)
            ->order([SearchQueryBuilder::ALIAS . '.occurred_at_ms DESC', SearchQueryBuilder::ALIAS . '.entity_id DESC'])
            ->limit($request->getLimit() + 1);

        $plan = [];
        $connection = self::service(ResourceConnection::class)->getConnection();
        foreach ($connection->fetchAll('EXPLAIN ' . $select->assemble()) as $row) {
            if (is_array($row)) {
                $plan[] = sprintf(
                    '  %s %s type=%s key=%s rows=%s extra=%s',
                    is_scalar($row['select_type'] ?? null) ? (string) $row['select_type'] : '',
                    is_scalar($row['table'] ?? null) ? (string) $row['table'] : '',
                    is_scalar($row['type'] ?? null) ? (string) $row['type'] : '',
                    is_scalar($row['key'] ?? null) ? (string) $row['key'] : 'NULL',
                    is_scalar($row['rows'] ?? null) ? (string) $row['rows'] : '',
                    is_scalar($row['Extra'] ?? null) ? (string) $row['Extra'] : ''
                );
            }
        }

        fwrite(STDERR, sprintf(
            "\n[entity search performance] %s: median %.1f ms, %d events, total %s%s, narrowed %s\n%s\n",
            $shape,
            $medianMs,
            count($page->getEvents()),
            $page->getTotal()?->getCount() ?? '-',
            $page->getTotal()?->isAtLeast() === true ? '+' : '',
            $page->isNarrowed() ? 'yes' : 'no',
            implode("\n", $plan)
        ));
    }

    /**
     * @return MillionChanges
     */
    private static function changes(): MillionChanges
    {
        return self::service(MillionChanges::class);
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
