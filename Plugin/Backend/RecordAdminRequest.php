<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Backend;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\AdminRequestJournal;
use Magento\Backend\App\AbstractAction;
use Magento\Framework\App\RequestInterface;

/**
 * Captures the admin request when its action is dispatched (after the admin is authenticated) and records it with
 * the error when the action throws; a request that completes is recorded when its response is sent
 *
 * An around plugin, because only it sees both the start of the action and an error escaping it. The action's result
 * and errors pass through unchanged.
 */
class RecordAdminRequest
{
    /**
     * @param AdminRequestJournal $journal
     */
    public function __construct(
        private readonly AdminRequestJournal $journal
    ) {
    }

    /**
     * Capture the request and record it when the action throws
     *
     * @param AbstractAction $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return mixed The action's response or result (ResponseInterface or ResultInterface), as it returned it
     * @throws \Throwable What the action threw
     */
    public function aroundDispatch(AbstractAction $subject, callable $proceed, RequestInterface $request): mixed
    {
        $this->journal->start($request, $subject);
        try {
            return $proceed($request);
        } catch (\Throwable $error) {
            $this->journal->finish(null, $error);
            throw $error;
        }
    }
}
