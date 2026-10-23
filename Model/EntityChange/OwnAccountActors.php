<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\EntityChange;

use Hryvinskyi\ActivityLog\Api\ActorContextInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorInterface;
use Hryvinskyi\ActivityLog\Api\Data\ActorType;
use Hryvinskyi\ActivityLog\Api\UserActorsInterface;

/**
 * Who changed an account record when nobody was signed in: the account's own holder
 *
 * Signing up, resetting a forgotten password or confirming an email address saves the customer's own record before
 * (or without) anyone signing in, so the request has no actor of its own. Such a change is the holder's: the
 * entities in `ownAccounts` are accounts, each with the user type its holders sign in as. Any other change, and a
 * change made by someone who is signed in, keeps the request's actor.
 */
class OwnAccountActors
{
    /**
     * The actors a request without a signed-in user has
     */
    private const UNSIGNED = [ActorType::Anonymous, ActorType::Guest];

    /**
     * @param ActorContextInterface $actorContext
     * @param UserActorsInterface $userActors
     * @param array<string,int|numeric-string> $ownAccounts User type of each account entity, by entity code
     */
    public function __construct(
        private readonly ActorContextInterface $actorContext,
        private readonly UserActorsInterface $userActors,
        private readonly array $ownAccounts = []
    ) {
    }

    /**
     * The holder who changed the account, or null when the change keeps the request's actor
     *
     * @param string $entityCode
     * @param string|null $entityId
     * @return ActorInterface|null
     */
    public function actorOf(string $entityCode, ?string $entityId): ?ActorInterface
    {
        $userType = $this->ownAccounts[$entityCode] ?? null;
        if ($userType === null || $entityId === null || !ctype_digit($entityId)) {
            return null;
        }
        if (!in_array($this->actorContext->current()->getType(), self::UNSIGNED, true)) {
            return null;
        }

        return $this->userActors->actorOf((int) $userType, (int) $entityId);
    }
}
