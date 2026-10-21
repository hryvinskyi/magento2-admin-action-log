<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Test\Unit\Model\AdminRequest;

use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Hryvinskyi\AdminActionLog\Model\AdminRequest\OutcomePolicy;
use PHPUnit\Framework\TestCase;

class OutcomePolicyTest extends TestCase
{
    public function testARedirectWithASuccessMessageSucceeded(): void
    {
        self::assertSame(
            ['outcome' => Outcome::Success, 'message' => null],
            (new OutcomePolicy())->decide(302, [['type' => 'success', 'text' => 'You saved the product.']], null)
        );
    }

    public function testAnErrorMessageMeansTheActionFailedAndExplainsWhy(): void
    {
        self::assertSame(
            ['outcome' => Outcome::Failure, 'message' => 'The URL key is already used.'],
            (new OutcomePolicy())->decide(302, [
                ['type' => 'warning', 'text' => 'Some images were skipped.'],
                ['type' => 'error', 'text' => '  The URL key is already used. '],
            ], null)
        );
    }

    public function testRefusalForLackOfPermissionIsDeniedNotFailed(): void
    {
        self::assertSame(
            ['outcome' => Outcome::Denied, 'message' => null],
            (new OutcomePolicy())->decide(403, [], null)
        );
    }

    public function testAnErrorStatusOrAThrownErrorIsAFailure(): void
    {
        $policy = new OutcomePolicy();

        self::assertSame(Outcome::Failure, $policy->decide(500, [], null)['outcome']);
        self::assertSame(
            ['outcome' => Outcome::Failure, 'message' => 'Database is gone'],
            $policy->decide(null, [], new \RuntimeException('Database is gone'))
        );
    }
}
