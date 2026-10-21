<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\ViewModel;

use Hryvinskyi\AdminActionLog\Model\ResourceModel\AdminRequestStore;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;

/**
 * The admin request panel of the activity detail page
 *
 * @phpstan-import-type RequestDetail from AdminRequestStore
 */
class AdminRequestPanel implements ArgumentInterface
{
    private const MESSAGE_TONES = ['success', 'error', 'warning', 'notice'];

    /**
     * @param RequestInterface $request
     * @param AdminRequestStore $store
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly AdminRequestStore $store
    ) {
    }

    /**
     * The stored request of the activity on the page, or null when none was stored (a sign-in, a sign-out)
     *
     * @return RequestDetail|null
     */
    public function detail(): ?array
    {
        $id = $this->request->getParam('id');

        return is_numeric($id) && (int) $id > 0 ? $this->store->find((int) $id) : null;
    }

    /**
     * CSS modifier of a message by its type; an unknown type looks like a notice
     *
     * @param string $type
     * @return string
     */
    public function messageTone(string $type): string
    {
        return in_array($type, self::MESSAGE_TONES, true) ? $type : 'notice';
    }
}
