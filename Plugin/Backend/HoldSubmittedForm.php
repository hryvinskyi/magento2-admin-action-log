<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Backend;

use Hryvinskyi\AdminActionLog\Model\EntityChange\Automatic\SubmittedForm;
use Magento\Backend\App\AbstractAction;
use Magento\Framework\App\RequestInterface;

/**
 * Holds what the admin submitted while a back-office action runs, so the changes it causes can be told apart from
 * those it asked for
 *
 * An around plugin, because the data must be dropped when the action ends however it ends: a save made after it, in
 * the same process, was not asked for by that form. The action's result and errors pass through unchanged.
 */
class HoldSubmittedForm
{
    /**
     * @param SubmittedForm $form
     */
    public function __construct(
        private readonly SubmittedForm $form
    ) {
    }

    /**
     * Hold the request's parameters while the action runs
     *
     * @param AbstractAction $subject
     * @param callable $proceed
     * @param RequestInterface $request
     * @return mixed The action's response or result (ResponseInterface or ResultInterface), as it returned it
     * @throws \Throwable What the action threw
     */
    public function aroundDispatch(AbstractAction $subject, callable $proceed, RequestInterface $request): mixed
    {
        $this->form->begin($request->getParams());
        try {
            return $proceed($request);
        } finally {
            $this->form->end();
        }
    }
}
