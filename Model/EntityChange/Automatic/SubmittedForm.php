<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * The data an admin submitted with the back-office action that is running, held while that action runs
 *
 * Only an admin action holds data here: a request through the web API, a console command, a cron job or anything else
 * leaves it empty, so nothing it changes can be told apart from what was asked for. The values are only compared,
 * never stored.
 */
class SubmittedForm implements ResetAfterRequestInterface
{
    /**
     * @var array<mixed>|null
     */
    private ?array $parameters = null;

    /**
     * Hold the parameters of the admin action that starts; those of an action that ran before are dropped
     *
     * @param array<mixed> $parameters The request's parameters as the action reads them, nested as posted
     * @return void
     */
    public function begin(array $parameters): void
    {
        $this->parameters = $parameters;
    }

    /**
     * Drop the parameters once the admin action has run
     *
     * @return void
     */
    public function end(): void
    {
        $this->parameters = null;
    }

    /**
     * The parameters of the admin action that is running, or null outside one
     *
     * @return array<mixed>|null
     */
    public function parameters(): ?array
    {
        return $this->parameters;
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->parameters = null;
    }
}
