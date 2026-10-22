<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Performance;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\SuggestionProviderInterface;
use Hryvinskyi\ActivityLog\Model\Cache\Type\SearchCache;
use Hryvinskyi\ActivityLog\Model\Search\Suggestion\Journal\ActorSuggestions;
use Hryvinskyi\ActivityLog\Model\Search\Suggestion\Journal\StoreViewSuggestions;
use Hryvinskyi\ActivityLog\Model\Search\Suggestion\Journal\ViaClientSuggestions;
use Hryvinskyi\ActivityLog\Model\Search\Suggestion\RecentWindow;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\EntityTypeSuggestions;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\FieldSuggestions;
use Hryvinskyi\AdminActionLog\Model\Search\Suggestion\ScopeSuggestions;
use Hryvinskyi\AdminActionLog\Test\Integration\Performance\Fixture\MillionChanges;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\TestCase\AbstractBackendController;

/**
 * The search bar's suggestions over a million activities and their change rows, with the recent window ending at the
 * seed's last activity: each list read from the database, and the endpoint answering with every list, cold (the
 * cache type emptied) and warm
 *
 * Each list must be read within LIST_BUDGET_MS (median of three after a warm-up), the endpoint within
 * COLD_BUDGET_MS cold and WARM_BUDGET_MS warm (medians). The timings and the plans of the queries each list runs go
 * to standard error. The parent class adds the endpoint's ACL allow and deny tests, which run over the seed too.
 */
#[DbIsolation(false)]
class SuggestionPerformanceTest extends AbstractBackendController
{
    private const LIST_BUDGET_MS = 500.0;
    private const COLD_BUDGET_MS = 2000.0;
    private const WARM_BUDGET_MS = 150.0;
    private const NS = 'Hryvinskyi\\AdminActionLog\\Model\\Search\\Suggestion\\';

    /**
     * @var string
     */
    protected $resource = 'Hryvinskyi_ActivityLog::view';

    /**
     * @var string
     */
    protected $uri = 'backend/activitylog/activity/suggest';

    /**
     * @var string
     */
    protected $httpMethod = 'GET';

    /**
     * @var bool
     */
    private bool $cacheWasEnabled = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::service(MillionChanges::class)->seed();
    }

    public static function tearDownAfterClass(): void
    {
        self::service(MillionChanges::class)->remove();
        self::forgetSuggestionServices();
        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $clock = new class implements MillisecondClockInterface {
            /**
             * @inheritDoc
             */
            public function nowMs(): int
            {
                return MillionChanges::NOW_MS;
            }
        };
        // Every suggestion service the container builds from here on reads the window that ends with the seed
        self::forgetSuggestionServices();
        $objectManager = self::testObjectManager();
        $objectManager->addSharedInstance(
            $objectManager->create(RecentWindow::class, ['clock' => $clock]),
            RecentWindow::class
        );

        $state = self::service(StateInterface::class);
        $this->cacheWasEnabled = $state->isEnabled(SearchCache::TYPE_IDENTIFIER);
        $state->setEnabled(SearchCache::TYPE_IDENTIFIER, true);
        self::service(SearchCache::class)->clean();
    }

    protected function tearDown(): void
    {
        self::service(SearchCache::class)->clean();
        self::service(StateInterface::class)->setEnabled(SearchCache::TYPE_IDENTIFIER, $this->cacheWasEnabled);
        parent::tearDown();
    }

    public function testEveryListIsReadWithinBudget(): void
    {
        $types = [
            ActorSuggestions::class,
            ViaClientSuggestions::class,
            StoreViewSuggestions::class,
            EntityTypeSuggestions::class,
            FieldSuggestions::class,
            self::NS . 'SettingSuggestions',
            self::NS . 'ProductSuggestions',
            self::NS . 'CategorySuggestions',
            self::NS . 'CmsBlockSuggestions',
            ScopeSuggestions::class,
        ];

        foreach ($types as $type) {
            $provider = Bootstrap::getObjectManager()->get($type);
            self::assertInstanceOf(SuggestionProviderInterface::class, $provider);
            $window = self::service(RecentWindow::class);

            $window->_resetState();
            $queries = $this->queriesOf(static fn (): array => $provider->suggestions());
            $timings = [];
            $count = 0;
            for ($run = 0; $run < 3; $run++) {
                $window->_resetState();
                $started = hrtime(true);
                $count = count($provider->suggestions());
                $timings[] = (hrtime(true) - $started) / 1e6;
            }
            sort($timings);

            fwrite(STDERR, sprintf(
                "\n[suggestion performance] %s (%s): median %.1f ms, %d suggestions\n%s\n",
                $provider->getKey(),
                substr((string) strrchr('\\' . $type, '\\'), 1),
                $timings[1],
                $count,
                implode("\n", array_map(fn (string $query): string => $this->plan($query), $queries))
            ));
            self::assertLessThan(self::LIST_BUDGET_MS, $timings[1], sprintf('%s: median %.1f ms', $type, $timings[1]));
            // The seed records no client an actor worked through; every other list finds it
            self::assertSame($type !== ViaClientSuggestions::class, $count > 0, $type . ' finds the seed');
        }
    }

    public function testTheEndpointAnswersWithinBudgetColdAndWarm(): void
    {
        $this->dispatchSuggest();
        $cold = [];
        for ($run = 0; $run < 3; $run++) {
            self::service(SearchCache::class)->clean();
            self::service(RecentWindow::class)->_resetState();
            $cold[] = $this->dispatchSuggest();
        }
        $warm = [];
        for ($run = 0; $run < 5; $run++) {
            $warm[] = $this->dispatchSuggest();
        }
        sort($cold);
        sort($warm);
        $response = $this->getResponse();
        self::assertInstanceOf(\Magento\TestFramework\Response::class, $response);
        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        $lists = is_array($body['lists'] ?? null) ? $body['lists'] : [];

        fwrite(STDERR, sprintf(
            "\n[suggestion performance] endpoint: cold median %.1f ms (%s), warm median %.1f ms (%s), %d lists, %d KiB"
            . "\n",
            $cold[1],
            implode(', ', array_map(static fn (float $ms): string => sprintf('%.0f', $ms), $cold)),
            $warm[2],
            implode(', ', array_map(static fn (float $ms): string => sprintf('%.0f', $ms), $warm)),
            count($lists),
            intdiv(strlen((string) $response->getBody()), 1024)
        ));
        foreach (['by', 'field', 'config', 'product', 'entity', 'store'] as $key) {
            self::assertNotEmpty($lists[$key] ?? [], $key);
        }
        self::assertLessThan(self::COLD_BUDGET_MS, $cold[1], sprintf('Cold median %.1f ms', $cold[1]));
        self::assertLessThan(self::WARM_BUDGET_MS, $warm[2], sprintf('Warm median %.1f ms', $warm[2]));
    }

    /**
     * Dispatch the endpoint once and return its wall time in ms
     *
     * @return float
     */
    private function dispatchSuggest(): float
    {
        $this->resetRequest();
        $request = $this->getRequest();
        self::assertInstanceOf(\Magento\TestFramework\Request::class, $request);
        $request->setMethod('GET');

        $started = hrtime(true);
        $this->dispatch($this->uri);
        $elapsed = (hrtime(true) - $started) / 1e6;

        $response = $this->getResponse();
        self::assertInstanceOf(\Magento\TestFramework\Response::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return $elapsed;
    }

    /**
     * The SELECT statements a call sends, recorded by the connection's profiler
     *
     * @param callable(): mixed $call
     * @return list<string>
     */
    private function queriesOf(callable $call): array
    {
        $connection = self::service(ResourceConnection::class)->getConnection();
        self::assertInstanceOf(\Zend_Db_Adapter_Abstract::class, $connection);
        $profiler = $connection->getProfiler();
        self::assertInstanceOf(\Zend_Db_Profiler::class, $profiler);
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $call();
        } finally {
            $profiler->setEnabled(false);
        }

        $queries = [];
        foreach ($profiler->getQueryProfiles(\Zend_Db_Profiler::SELECT) ?: [] as $profile) {
            if ($profile instanceof \Zend_Db_Profiler_Query && !str_contains($profile->getQuery(), 'eav_')) {
                $queries[] = $profile->getQuery();
            }
        }
        $profiler->clear();

        return $queries;
    }

    /**
     * A query's plan, one line per table
     *
     * @param string $query
     * @return string
     */
    private function plan(string $query): string
    {
        $lines = ['  ' . preg_replace('/\s+/', ' ', substr($query, 0, 300))];
        foreach (self::service(ResourceConnection::class)->getConnection()->fetchAll('EXPLAIN ' . $query) as $row) {
            if (is_array($row)) {
                $lines[] = sprintf(
                    '    %s type=%s key=%s rows=%s extra=%s',
                    is_scalar($row['table'] ?? null) ? (string) $row['table'] : '',
                    is_scalar($row['type'] ?? null) ? (string) $row['type'] : '',
                    is_scalar($row['key'] ?? null) ? (string) $row['key'] : 'NULL',
                    is_scalar($row['rows'] ?? null) ? (string) $row['rows'] : '',
                    is_scalar($row['Extra'] ?? null) ? (string) $row['Extra'] : ''
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Drop the shared suggestion services, so that the next ones are built around the current window
     *
     * @return void
     */
    private static function forgetSuggestionServices(): void
    {
        $objectManager = self::testObjectManager();
        foreach ([
            RecentWindow::class,
            \Hryvinskyi\ActivityLog\Model\ResourceModel\RecentJournalValues::class,
            \Hryvinskyi\ActivityLog\Model\ResourceModel\RecentSubjects::class,
            \Hryvinskyi\AdminActionLog\Model\ResourceModel\RecentChanges::class,
            \Hryvinskyi\ActivityLog\Model\Search\SuggestionPool::class,
            \Hryvinskyi\ActivityLog\Model\Search\SuggestionsPresenter::class,
            ActorSuggestions::class,
            ViaClientSuggestions::class,
            StoreViewSuggestions::class,
            EntityTypeSuggestions::class,
            FieldSuggestions::class,
            ScopeSuggestions::class,
            self::NS . 'SettingSuggestions',
            self::NS . 'ProductSuggestions',
            self::NS . 'CategorySuggestions',
            self::NS . 'CmsPageSuggestions',
            self::NS . 'CmsBlockSuggestions',
            self::NS . 'CustomerSuggestions',
            self::NS . 'OrderSuggestions',
            self::NS . 'RuleSuggestions',
        ] as $type) {
            $objectManager->removeSharedInstance($type);
        }
    }

    /**
     * The test framework's object manager, which can replace a shared instance
     *
     * @return \Magento\TestFramework\ObjectManager
     */
    private static function testObjectManager(): \Magento\TestFramework\ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(\Magento\TestFramework\ObjectManager::class, $objectManager);

        return $objectManager;
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
