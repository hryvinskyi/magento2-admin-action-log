<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Magento\Framework\Message\MessageInterface;

/**
 * How an admin request ended, as the admin experienced it
 *
 * A thrown error or an error status is a failure. 403 is the admin panel refusing the page for lack of permission:
 * denied. A request answered normally but with an error message ("The URL key is already used") also failed; the
 * first error message explains it.
 */
class OutcomePolicy
{
    private const DENIED_STATUS = 403;
    private const FIRST_ERROR_STATUS = 400;
    private const MESSAGE_MAX_LENGTH = 500;

    /**
     * Outcome and explaining message of a request
     *
     * @param int|null $responseCode
     * @param list<array{type:string,text:string}> $messages Messages shown to the admin
     * @param \Throwable|null $error
     * @return array{outcome: Outcome, message: string|null}
     */
    public function decide(?int $responseCode, array $messages, ?\Throwable $error): array
    {
        if ($error !== null) {
            return ['outcome' => Outcome::Failure, 'message' => $this->clip($error->getMessage())];
        }

        if ($responseCode === self::DENIED_STATUS) {
            return ['outcome' => Outcome::Denied, 'message' => null];
        }

        $firstError = $this->firstError($messages);
        if ($responseCode !== null && $responseCode >= self::FIRST_ERROR_STATUS) {
            return ['outcome' => Outcome::Failure, 'message' => $firstError];
        }

        return $firstError === null
            ? ['outcome' => Outcome::Success, 'message' => null]
            : ['outcome' => Outcome::Failure, 'message' => $firstError];
    }

    /**
     * The first error message shown, or null
     *
     * @param list<array{type:string,text:string}> $messages
     * @return string|null
     */
    private function firstError(array $messages): ?string
    {
        foreach ($messages as $message) {
            if ($message['type'] === MessageInterface::TYPE_ERROR) {
                return $this->clip($message['text']);
            }
        }

        return null;
    }

    /**
     * A message cut to what an activity holds, or null when empty
     *
     * @param string $text
     * @return string|null
     */
    private function clip(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : mb_substr($text, 0, self::MESSAGE_MAX_LENGTH);
    }
}
