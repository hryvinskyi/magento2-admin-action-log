<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\ConfigHistory;

use Hryvinskyi\ActivityLog\Api\Data\Search\ActivityFactInterface;
use Hryvinskyi\ActivityLog\Api\MillisecondClockInterface;
use Hryvinskyi\ActivityLog\Api\Search\ActivityFactsInterface;
use Hryvinskyi\ActivityLog\Model\Search\RecentDays;
use Hryvinskyi\AdminActionLog\Model\ResourceModel\ConfigChanges;
use Magento\Config\Model\Config\Structure;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The recorded history of each setting of a Stores › Configuration section, in short: how many changes, in every
 * scope, and the newest of them — when, by whom, and whether it falls in the last RECENT_DAYS days
 *
 * The first setting asked about reads its whole section: the config paths of every field the section declares, then
 * one grouped count over this module's change tables, then when and by whom the newest change of every setting that
 * has any was made, in one read of the journal. What was read is kept for the rest of the request, so a section page
 * costs the same few queries however many fields it shows or how many of them changed. "The
 * last N days" runs from the store's midnight N−1 days ago to now, the rule the activity log's presets follow.
 *
 * @phpstan-type SettingHistory array{changes: int, latestMs: int|null, latestActor: string|null, recent: bool}
 */
class SectionHistory implements ResetAfterRequestInterface
{
    /**
     * A change of the last this many days marks its setting as recently changed
     */
    public const RECENT_DAYS = 7;

    /**
     * Per section read so far: the config paths its fields declare, and the histories of those that have any
     *
     * @var array<string, array{paths: array<string, true>, histories: array<string, SettingHistory>}>
     */
    private array $sections = [];

    /**
     * Config paths asked about outside the paths their section declares, each read once
     *
     * @var array<string, SettingHistory|null>
     */
    private array $strays = [];

    /**
     * @param Structure $structure
     * @param ConfigChanges $changes
     * @param ActivityFactsInterface $activities
     * @param RecentDays $recentDays
     * @param MillisecondClockInterface $clock
     */
    public function __construct(
        private readonly Structure $structure,
        private readonly ConfigChanges $changes,
        private readonly ActivityFactsInterface $activities,
        private readonly RecentDays $recentDays,
        private readonly MillisecondClockInterface $clock
    ) {
    }

    /**
     * The history of one setting of a section, or null when no change of it was recorded
     *
     * @param string $section Id of the section the setting is shown in
     * @param string $path The setting's config path
     * @return SettingHistory|null
     */
    public function of(string $section, string $path): ?array
    {
        if (!isset($this->sections[$section])) {
            $paths = $this->pathsOf($section);
            $this->sections[$section] = [
                'paths' => array_fill_keys($paths, true),
                'histories' => $this->histories($paths),
            ];
        }

        $read = $this->sections[$section];
        if (isset($read['paths'][$path])) {
            return $read['histories'][$path] ?? null;
        }

        if (!array_key_exists($path, $this->strays)) {
            $this->strays[$path] = $this->histories([$path])[$path] ?? null;
        }

        return $this->strays[$path];
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->sections = [];
        $this->strays = [];
    }

    /**
     * The histories of the settings that have any, by config path
     *
     * @param list<string> $paths
     * @return array<string, SettingHistory>
     */
    private function histories(array $paths): array
    {
        $counts = $this->changes->byPath($paths);
        if ($counts === []) {
            return [];
        }

        $recentFromMs = $this->recentDays->startMs(self::RECENT_DAYS, $this->clock->nowMs());
        $latest = $this->newest(array_values(array_map(
            static fn (array $count): int => $count['latestActivityId'],
            $counts
        )));
        $histories = [];
        foreach ($counts as $path => $count) {
            $fact = $latest[$count['latestActivityId']] ?? null;
            $histories[$path] = [
                'changes' => $count['changes'],
                'latestMs' => $fact?->getOccurredAtMs(),
                'latestActor' => $fact?->getActorName(),
                'recent' => $fact !== null && $fact->getOccurredAtMs() >= $recentFromMs,
            ];
        }

        return $histories;
    }

    /**
     * When the newest changes were made and by whom, by activity id; one the journal no longer has is left out
     *
     * Read in batches of the most activities the journal answers about at once.
     *
     * @param list<int> $activityIds
     * @return array<int, ActivityFactInterface>
     */
    private function newest(array $activityIds): array
    {
        $facts = [];
        $batches = array_chunk(array_values(array_unique($activityIds)), ActivityFactsInterface::MAX_ACTIVITIES);
        foreach ($batches as $ids) {
            $facts += $this->activities->of($ids);
        }

        return $facts;
    }

    /**
     * Config paths of the fields a section declares, a field declared with a config path of its own under that path
     *
     * @param string $section
     * @return list<string>
     */
    private function pathsOf(string $section): array
    {
        $prefix = $section . '/';
        $paths = [];
        foreach ($this->structure->getFieldPaths() as $configPath => $shownUnder) {
            foreach (is_array($shownUnder) ? $shownUnder : [] as $structurePath) {
                if (is_string($structurePath) && str_starts_with($structurePath, $prefix)) {
                    $paths[] = (string) $configPath;
                    break;
                }
            }
        }

        return $paths;
    }
}
