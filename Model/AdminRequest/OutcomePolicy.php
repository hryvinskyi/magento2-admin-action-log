<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Model\AdminRequest;

use Hryvinskyi\ActivityLog\Api\Data\Outcome;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\MessageInterface;

/**
 * How an admin request ended, as the admin experienced it
 *
 * A thrown error or an error status is a failure. 403 is the admin panel refusing the page for lack of permission:
 * denied. A request answered normally but with an error message ("The URL key is already used") also failed; the
 * first error message explains it.
 *
 * A thrown error explains the failure by its message only when it was written for people (a LocalizedException).
 * Any other error's message is for developers and may quote a query or a file path, so the activity says only that
 * the request stopped on an unexpected error; Magento keeps the error itself in its exception log.
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
            return ['outcome' => Outcome::Failure, 'message' => $this->explanationOf($error)];
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
     * What a thrown error tells people about the failure
     *
     * @param \Throwable $error
     * @return string|null
     */
    private function explanationOf(\Throwable $error): ?string
    {
        if ($error instanceof LocalizedException) {
            return $this->clip($error->getMessage());
        }

        return __('The request stopped on an unexpected error. The error is in the exception log.')->render();
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
