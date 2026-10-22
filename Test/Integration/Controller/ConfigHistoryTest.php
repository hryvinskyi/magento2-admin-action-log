<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Integration\Controller;

use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\LoggingStart;
use Hryvinskyi\AdminActionLog\Model\ConfigHistory\SectionHistory;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\ConfigChanges;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\EntityChangeStore;
use Hryvinskyi\AdminActionLog\Test\Integration\Model\Search\Fixture\EntityChanges;
use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Config\Model\Config\Structure;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\TestFramework\Fixture\AppArea;
use Magento\TestFramework\Fixture\DbIsolation;
use Magento\TestFramework\Request as TestRequest;
use Magento\TestFramework\Response as TestResponse;
use Magento\TestFramework\TestCase\AbstractBackendController;
use Magento\User\Model\User;

/**
 * Each setting's history on Stores › Configuration, through the real admin stack: the count of changes per config
 * path from one grouped query per section page, the button after each setting's control with its count, its newest
 * change and the recent-change mark, the popover's config and the timeline's assets — and nothing of it, not even
 * the count, for an admin who may not read the activity log
 *
 * Changes are written straight to the journal and the change tables, at times relative to now: the recorder writes
 * them only after a save's transaction commits, which never happens inside a test's transaction.
 */
#[AppArea('adminhtml')]
#[DbIsolation(true)]
class ConfigHistoryTest extends AbstractBackendController
{
    private const HOUR_MS = 3_600_000;
    private const DAY_MS = 86_400_000;
    private const SECTION = 'hryvinskyi_admin_action_log';
    private const ENABLED = 'hryvinskyi_admin_action_log/admin/enabled';
    private const RETENTION = 'hryvinskyi_admin_action_log/admin/retention_days';
    private const GET_REQUESTS = 'hryvinskyi_admin_action_log/admin/log_get_requests';

    /**
     * The page the inherited access tests open, the section of this module's own settings, needs the configuration
     *
     * @var string
     */
    protected $resource = 'Magento_Config::config';

    /**
     * @var string
     */
    protected $uri = 'backend/admin/system_config/edit/section/hryvinskyi_admin_action_log/';

    /**
     * @var EntityChanges
     */
    private EntityChanges $changes;

    /**
     * @var int
     */
    private int $nowMs = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changes = new EntityChanges();
        $this->changes->clear();
        EntityChanges::service(SectionHistory::class)->_resetState();
        EntityChanges::service(LoggingStart::class)->_resetState();
        $this->nowMs = EntityChanges::service(MillisecondClockInterface::class)->nowMs();
    }

    public function testCountsTheChangesOfEachPathInEveryScopeAndNothingElse(): void
    {
        $newest = $this->seed(self::RETENTION, 10 * self::DAY_MS, 'anna');
        $this->seed(self::ENABLED, 3 * self::DAY_MS, 'anna');
        $latest = $this->seed(self::ENABLED, 2 * self::HOUR_MS, 'john', ['website', 1, 'Main Website']);
        $this->seed(self::ENABLED . '_x', self::HOUR_MS, 'john');
        $this->changes->change('product', '5', [['field' => self::ENABLED, 'before' => '1', 'after' => '0']]);

        self::assertSame(
            [
                self::ENABLED => ['changes' => 2, 'latestActivityId' => $latest],
                self::RETENTION => ['changes' => 1, 'latestActivityId' => $newest],
            ],
            $this->sorted(EntityChanges::service(ConfigChanges::class)->byPath(
                [self::ENABLED, self::RETENTION, self::GET_REQUESTS]
            )),
            'A longer sibling path and a field of another kind of record are not the setting'
        );

        $history = EntityChanges::service(SectionHistory::class);
        self::assertSame(
            ['changes' => 2, 'latestMs' => $this->nowMs - 2 * self::HOUR_MS, 'latestActor' => 'john', 'recent' => true],
            $history->of(self::SECTION, self::ENABLED)
        );
        self::assertSame(
            [
                'changes' => 1,
                'latestMs' => $this->nowMs - 10 * self::DAY_MS,
                'latestActor' => 'anna',
                'recent' => false,
            ],
            $history->of(self::SECTION, self::RETENTION)
        );
        self::assertNull($history->of(self::SECTION, self::GET_REQUESTS));
    }

    public function testEachSettingGetsItsButtonFromOneGroupedCountForTheWholeSection(): void
    {
        $oldestMs = $this->nowMs - 10 * self::DAY_MS;
        $this->seed(self::RETENTION, 10 * self::DAY_MS, 'anna');
        $this->seed(self::ENABLED, 3 * self::DAY_MS, 'anna');
        $this->seed(self::ENABLED, 2 * self::HOUR_MS, 'john', ['website', 1, 'Main Website']);

        [$body, $queries] = $this->pageWithQueries();

        self::assertSame(1, $this->groupedCounts($queries), 'One grouped count per section page');
        $buttons = $this->buttons($body);
        self::assertSame(
            ['2', (string) ($this->nowMs - 2 * self::HOUR_MS), 'john', 'recent', 'History of Record Admin Requests'],
            [
                $buttons[self::ENABLED]['data-changes'] ?? null,
                $buttons[self::ENABLED]['data-latest-ms'] ?? null,
                $buttons[self::ENABLED]['data-latest-actor'] ?? null,
                $this->state($buttons[self::ENABLED] ?? []),
                $buttons[self::ENABLED]['aria-label'] ?? null,
            ]
        );
        self::assertSame(['1', 'changed'], [
            $buttons[self::RETENTION]['data-changes'] ?? null,
            $this->state($buttons[self::RETENTION] ?? []),
        ]);
        self::assertSame(['0', '', 'never'], [
            $buttons[self::GET_REQUESTS]['data-changes'] ?? null,
            $buttons[self::GET_REQUESTS]['data-latest-ms'] ?? null,
            $this->state($buttons[self::GET_REQUESTS] ?? []),
        ]);
        preg_match_all('/<button[^>]*data-role="config-history-open"/', $body, $all);
        self::assertCount(count($buttons), $all[0], 'One button per setting');
        self::assertSame(
            [count($buttons), 0],
            [
                $this->countNodes($body, '//td[contains(@class, "value")]//button[@data-role="config-history-open"]'),
                $this->countNodes($body, '//label//button[@data-role="config-history-open"]'),
            ],
            'Each button sits in its setting\'s value column, outside the labels a screen reader names the control by'
        );

        $config = $this->popoverConfig($body);
        self::assertSame([50, $oldestMs], [$config['limit'] ?? null, $config['loggingStartedMs'] ?? null]);
        self::assertIsString($config['searchUrl'] ?? null);
        self::assertStringContainsString('/activitylog/activity/search/', $config['searchUrl']);
        self::assertStringContainsString('data-role="activity-timeline-assets"', $body);
    }

    public function testASectionWithManyChangedSettingsCostsTheSameQueriesAsOneWithOne(): void
    {
        $paths = $this->sectionPaths();
        self::assertGreaterThan(3, count($paths), 'The section has several settings');
        $history = EntityChanges::service(SectionHistory::class);
        $history->of(self::SECTION, self::ENABLED);

        $this->seed(self::ENABLED, 10 * self::DAY_MS, 'john');
        $history->_resetState();
        [$one, $oneQueries] = $this->historyWithQueries($paths);

        foreach ($paths as $index => $path) {
            $this->seed($path, (count($paths) - $index) * self::HOUR_MS, 'anna');
        }
        $history->_resetState();
        [$many, $manyQueries] = $this->historyWithQueries($paths);

        self::assertCount(1, array_filter($one));
        self::assertCount(count($paths), array_filter($many), 'Every setting has its history');
        self::assertSame(['john', 'anna', 'anna', true], [
            $one[self::ENABLED]['latestActor'] ?? null,
            $many[self::ENABLED]['latestActor'] ?? null,
            $many[self::RETENTION]['latestActor'] ?? null,
            $many[self::RETENTION]['recent'] ?? null,
        ]);
        self::assertSame(
            count($this->journalQueries($oneQueries)),
            count($this->journalQueries($manyQueries)),
            'When and by whom the newest changes were made is read in one go, however many settings changed'
        );
        self::assertCount(1, $this->journalQueries($manyQueries));
        self::assertSame(count($oneQueries), count($manyQueries), 'The section costs the same queries either way');
    }

    public function testAnAdminWhoMayNotReadTheLogGetsNoButtonNoCountAndNoAssets(): void
    {
        $this->seed(self::ENABLED, self::HOUR_MS, 'john');
        $admin = $this->_auth->getUser();
        self::assertInstanceOf(User::class, $admin);
        $aclBuilder = EntityChanges::service(AclBuilder::class);
        $aclBuilder->getAcl()->deny($admin->getRoles(), 'Hryvinskyi_ActivityLog::view');

        try {
            [$body, $queries] = $this->pageWithQueries();
        } finally {
            $aclBuilder->resetRuntimeAcl();
        }

        self::assertStringContainsString('Record Admin Requests', $body, 'The section itself is shown');
        self::assertSame([], $this->buttons($body));
        self::assertStringNotContainsString('config-history', $body);
        self::assertStringNotContainsString('activity-timeline-assets', $body);
        self::assertSame(0, $this->groupedCounts($queries), 'Nothing is counted');
    }

    /**
     * Write one change of a setting, that long before now, and return its activity id
     *
     * Changes are written oldest first, as the log records them: a later change gets a higher activity id.
     *
     * @param string $path
     * @param int $agoMs
     * @param string $actor
     * @param array{0: string, 1: int, 2: string} $scope
     * @return int
     */
    private function seed(
        string $path,
        int $agoMs,
        string $actor,
        array $scope = ['default', 0, 'Default Config']
    ): int {
        $ms = $this->nowMs - $agoMs;

        return $this->changes->change(
            'config_value',
            '12',
            [['field' => $path, 'before' => '1', 'after' => '0']],
            $scope,
            [
                'occurred_at_ms' => $ms,
                'created_at' => gmdate('Y-m-d H:i:s', intdiv($ms, 1000)),
                'actor_name' => $actor,
                'subject_label' => 'Config value ' . $path,
            ]
        );
    }

    /**
     * The history of every setting of the section, and the SQL the database was asked while it was read
     *
     * @param list<string> $paths
     * @return array{0: array<string, array<string, mixed>|null>, 1: list<string>}
     */
    private function historyWithQueries(array $paths): array
    {
        $history = EntityChanges::service(SectionHistory::class);
        $connection = EntityChanges::service(ResourceConnection::class)->getConnection();
        self::assertInstanceOf(\Zend_Db_Adapter_Abstract::class, $connection);
        $profiler = $connection->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $histories = [];
            foreach ($paths as $path) {
                $histories[$path] = $history->of(self::SECTION, $path);
            }
            $queries = [];
            foreach ($profiler->getQueryProfiles() ?: [] as $profile) {
                self::assertInstanceOf(\Zend_Db_Profiler_Query::class, $profile);
                $queries[] = $profile->getQuery();
            }
        } finally {
            $profiler->setEnabled(false);
            $profiler->clear();
        }

        return [$histories, $queries];
    }

    /**
     * Config paths of the settings the section shows
     *
     * @return list<string>
     */
    private function sectionPaths(): array
    {
        $paths = [];
        foreach (EntityChanges::service(Structure::class)->getFieldPaths() as $path => $shownUnder) {
            foreach (is_array($shownUnder) ? $shownUnder : [] as $structurePath) {
                if (is_string($structurePath) && str_starts_with($structurePath, self::SECTION . '/')) {
                    $paths[] = (string) $path;
                    break;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * The queries that read the journal
     *
     * @param list<string> $queries
     * @return list<string>
     */
    private function journalQueries(array $queries): array
    {
        $journal = EntityChanges::service(ResourceConnection::class)->getTableName('hryvinskyi_activity_log');

        return array_values(array_filter(
            $queries,
            static fn (string $query): bool => preg_match('/`' . preg_quote($journal, '/') . '`/', $query) === 1
        ));
    }

    /**
     * Counts by path, in path order
     *
     * @param array<string, array{changes: int, latestActivityId: int}> $counts
     * @return array<string, array{changes: int, latestActivityId: int}>
     */
    private function sorted(array $counts): array
    {
        ksort($counts);

        return $counts;
    }

    /**
     * The section page's body
     *
     * @return string
     */
    private function page(): string
    {
        $this->resetRequest();
        // Building admin links switches the shared URL model's secret key back on; the test's request URIs carry none
        EntityChanges::service(BackendUrl::class)->turnOffSecretKey();
        $request = $this->getRequest();
        self::assertInstanceOf(TestRequest::class, $request);
        $request->setMethod('GET');
        $this->dispatch($this->uri);

        $response = $this->getResponse();
        self::assertInstanceOf(TestResponse::class, $response);
        self::assertSame(200, $response->getHttpResponseCode());

        return (string) $response->getBody();
    }

    /**
     * The section page's body and the SQL the database was asked while it was built
     *
     * @return array{0: string, 1: list<string>}
     */
    private function pageWithQueries(): array
    {
        $connection = EntityChanges::service(ResourceConnection::class)->getConnection();
        self::assertInstanceOf(\Zend_Db_Adapter_Abstract::class, $connection);
        $profiler = $connection->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $body = $this->page();
            $queries = [];
            foreach ($profiler->getQueryProfiles() ?: [] as $profile) {
                self::assertInstanceOf(\Zend_Db_Profiler_Query::class, $profile);
                $queries[] = $profile->getQuery();
            }
        } finally {
            $profiler->setEnabled(false);
            $profiler->clear();
        }

        return [$body, $queries];
    }

    /**
     * Grouped counts over the settings' field rows among the queries
     *
     * @param list<string> $queries
     * @return int
     */
    private function groupedCounts(array $queries): int
    {
        $table = EntityChanges::service(ResourceConnection::class)->getTableName(EntityChangeStore::FIELD_TABLE);

        return count(array_filter(
            $queries,
            static fn (string $query): bool => str_contains($query, $table) && str_contains($query, 'GROUP BY')
        ));
    }

    /**
     * The history buttons on the page, by the path they name, each as its attributes
     *
     * @param string $body
     * @return array<string, array<string, string>>
     */
    private function buttons(string $body): array
    {
        preg_match_all('/<button[^>]*data-role="config-history-open"[^>]*>/', $body, $tags);
        $buttons = [];
        foreach ($tags[0] as $tag) {
            preg_match_all('/([a-z-]+)="([^"]*)"/', $tag, $pairs, PREG_SET_ORDER);
            $attributes = [];
            foreach ($pairs as $pair) {
                $attributes[$pair[1]] = html_entity_decode($pair[2], ENT_QUOTES);
            }
            $buttons[$attributes['data-path'] ?? ''] = $attributes;
        }

        return $buttons;
    }

    /**
     * How a button is drawn: "recent", "changed" or "never"
     *
     * @param array<string, string> $button
     * @return string
     */
    private function state(array $button): string
    {
        preg_match('/config-history__button--([a-z]+)/', $button['class'] ?? '', $state);

        return $state[1] ?? '';
    }

    /**
     * How many nodes of the parsed page an XPath expression finds
     *
     * @param string $html
     * @param string $expression
     * @return int
     */
    private function countNodes(string $html, string $expression): int
    {
        $document = new \DOMDocument();
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $nodes = (new \DOMXPath($document))->query($expression);
        self::assertInstanceOf(\DOMNodeList::class, $nodes);

        return $nodes->length;
    }

    /**
     * The popover's config as the page prints it
     *
     * @param string $body
     * @return array<string, mixed>
     */
    private function popoverConfig(string $body): array
    {
        $pattern = '#<script type="application/json" data-role="config-history-config">(.*?)</script>#s';
        self::assertSame(1, preg_match($pattern, $body, $match), 'The page has the popover');
        $config = json_decode(trim($match[1] ?? ''), true);
        self::assertIsArray($config);
        /** @var array<string, mixed> $config */
        return $config;
    }
}
