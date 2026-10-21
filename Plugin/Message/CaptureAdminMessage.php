<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\AdminActionLog\Plugin\Message;

use Hryvinskyi\AdminActionLog\Model\AdminRequest\RequestCapture;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Message\MessageInterface;

/**
 * Keeps the messages an admin action shows ("You saved the product.") with the request being recorded
 */
class CaptureAdminMessage
{
    /**
     * @param RequestCapture $capture
     */
    public function __construct(
        private readonly RequestCapture $capture
    ) {
    }

    /**
     * Keep the message with the request being recorded
     *
     * @param ManagerInterface $subject
     * @param ManagerInterface $result
     * @param MessageInterface $message
     * @param string|null $group
     * @return ManagerInterface
     */
    public function afterAddMessage(
        ManagerInterface $subject,
        ManagerInterface $result,
        MessageInterface $message,
        $group = null
    ): ManagerInterface {
        if ($this->capture->isActive()) {
            $this->capture->addMessage((string) $message->getType(), strip_tags((string) $message->getText()));
        }

        return $result;
    }
}
