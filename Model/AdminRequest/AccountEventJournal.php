<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

use Hryvinskyi\ActivityLog\Api\ActivityBuilderInterface;
use Hryvinskyi\ActivityLog\Api\ActivityRecorderInterface;
use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterface;
use Hryvinskyi\ActivityLog\Api\Data\SubjectInterfaceFactory;
use Hryvinskyi\AdminActionLog\Model\ChannelCode;
use Hryvinskyi\AdminActionLog\Model\Config;
use Psr\Log\LoggerInterface;

/**
 * Records admin sign-ins, failed sign-ins and sign-outs
 *
 * They happen outside a recorded action (the sign-in form is answered before any action runs), so they are journaled
 * on their own, with the admin account as the subject.
 */
class AccountEventJournal
{
    public const SUBJECT_TYPE = 'admin_user';
    public const ACTION_LOGIN = 'Admin login';
    public const ACTION_LOGIN_FAILED = 'Admin login failed';
    public const ACTION_LOGOUT = 'Admin logout';
    private const NAME_MAX_LENGTH = 200;

    /**
     * @param Config $config
     * @param ActivityBuilderInterface $activityBuilder
     * @param ActivityRecorderInterface $activityRecorder
     * @param SubjectInterfaceFactory $subjectFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly ActivityBuilderInterface $activityBuilder,
        private readonly ActivityRecorderInterface $activityRecorder,
        private readonly SubjectInterfaceFactory $subjectFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * An admin signed in
     *
     * @param string|null $userId
     * @param string $username
     * @return void
     */
    public function loggedIn(?string $userId, string $username): void
    {
        $this->record(self::ACTION_LOGIN, Outcome::Success, $userId, $username, null);
    }

    /**
     * Someone failed to sign in with a user name
     *
     * @param string $username As typed; it may name no account
     * @param string|null $reason
     * @return void
     */
    public function loginFailed(string $username, ?string $reason): void
    {
        $this->record(self::ACTION_LOGIN_FAILED, Outcome::Failure, null, $username, $reason);
    }

    /**
     * An admin is signing out
     *
     * @param string|null $userId
     * @param string $username
     * @return void
     */
    public function loggingOut(?string $userId, string $username): void
    {
        $this->record(self::ACTION_LOGOUT, Outcome::Success, $userId, $username, null);
    }

    /**
     * Journal an account event, never failing the sign-in or sign-out
     *
     * @param string $action
     * @param Outcome $outcome
     * @param string|null $userId
     * @param string $username
     * @param string|null $message
     * @return void
     */
    private function record(string $action, Outcome $outcome, ?string $userId, string $username, ?string $message): void
    {
        if (!$this->config->isAdminEnabled()) {
            return;
        }

        try {
            $this->activityRecorder->record(
                $this->activityBuilder->build(
                    ChannelCode::ADMIN,
                    $action,
                    $outcome,
                    $this->subject($userId, $username),
                    null,
                    $message === null || trim($message) === '' ? null : $message
                )
            );
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf('"%s" could not be recorded.', $action), ['exception' => $exception]);
        }
    }

    /**
     * The admin account as a subject
     *
     * @param string|null $userId
     * @param string $username
     * @return SubjectInterface
     */
    private function subject(?string $userId, string $username): SubjectInterface
    {
        $name = trim($username) === '' ? '?' : mb_substr(trim($username), 0, self::NAME_MAX_LENGTH);

        return $this->subjectFactory->create([
            'type' => self::SUBJECT_TYPE,
            'label' => sprintf('User "%s"', $name),
            'id' => $userId === null || $userId === '' ? null : $userId,
        ]);
    }
}
