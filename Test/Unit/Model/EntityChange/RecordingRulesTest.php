<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\ActorContextInterface;
use Hryvinskyi\ActivityLog\Api\ConfigInterface as ActivityLogConfig;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Hryvinskyi\AdminActionLog\Model\Config;
use Hryvinskyi\AdminActionLog\Model\EntityChange\DiffMode;
use Hryvinskyi\AdminActionLog\Model\EntityChange\EntityTypeSettings;
use Hryvinskyi\AdminActionLog\Model\EntityChange\RecordingRules;
use PHPUnit\Framework\TestCase;

/**
 * Whether a change is recorded: both switches on, the entity not off, the actor chosen
 */
class RecordingRulesTest extends TestCase
{
    /**
     * @var bool
     */
    private bool $entityChanges = true;

    /**
     * @var bool
     */
    private bool $journal = true;

    /**
     * @var DiffMode
     */
    private DiffMode $mode = DiffMode::Changed;

    /**
     * @var ActorType
     */
    private ActorType $actor = ActorType::Admin;

    public function testAChangeIsRecordedWhenEveryRuleAllowsIt(): void
    {
        self::assertTrue($this->rules()->isOn());
        self::assertTrue($this->rules()->records('product'));
    }

    public function testNothingIsRecordedWhileEntityChangesAreOff(): void
    {
        $this->entityChanges = false;

        self::assertFalse($this->rules()->isOn());
        self::assertFalse($this->rules()->records('product'));
    }

    public function testNothingIsRecordedWhileTheJournalIsOff(): void
    {
        $this->journal = false;

        self::assertFalse($this->rules()->isOn());
        self::assertFalse($this->rules()->records('product'));
    }

    public function testAnEntitySwitchedOffIsNotRecorded(): void
    {
        $this->mode = DiffMode::Off;

        self::assertTrue($this->rules()->isOn());
        self::assertFalse($this->rules()->records('product'));
    }

    public function testAnActorNotChosenIsNotRecorded(): void
    {
        $this->actor = ActorType::Customer;

        self::assertFalse($this->rules()->records('product'));
    }

    private function rules(): RecordingRules
    {
        $config = $this->createMock(Config::class);
        $config->method('isEntityEnabled')->willReturnCallback(fn (): bool => $this->entityChanges);
        $config->method('getEntityActorTypes')->willReturn([ActorType::Admin, ActorType::Cli]);

        $journal = $this->createMock(ActivityLogConfig::class);
        $journal->method('isEnabled')->willReturnCallback(fn (): bool => $this->journal);

        $settings = $this->createMock(EntityTypeSettings::class);
        $settings->method('modeOf')->willReturnCallback(fn (): DiffMode => $this->mode);

        $actor = $this->createMock(ActorInterface::class);
        $actor->method('getType')->willReturnCallback(fn (): ActorType => $this->actor);
        $context = $this->createMock(ActorContextInterface::class);
        $context->method('current')->willReturn($actor);

        return new RecordingRules($config, $journal, $settings, $context);
    }
}
